<?php
/**
 * Plugin Name: LFW Alt Text
 * Plugin URI:  https://lfw.com/
 * Description: An image cannot be published without alternative text or a recorded reason it has none (decorative, described in the surrounding text, or another reason with a note). Shows the status in the Media Library, lists every image that needs attention before publishing, and blocks publishing until each one is handled. Runs on your own site and makes no network calls.
 * Version:     1.0.0
 * Requires at least: 6.6
 * Requires PHP: 7.4
 * Author:      LFW
 * Author URI:  https://lfw.com/
 * License:     GPL-2.0-or-later
 * Text Domain: lfw-alt-text
 *
 * What this does NOT do: it does not judge whether alt text is good. "Image" or
 * "IMG_2041.jpg" passes. A person still reviews what the alt text says and
 * whether a "decorative" choice is honest; the report lists every recorded
 * reason so that review is quick.
 *
 * Abuse case (written before the code, per LFW rule): the reason note is free
 * text that a contributor can store and an editor will later read in the
 * editor, the Media Library and the report. A hostile or careless user could
 * try stored script injection through the note, a note large enough to bloat
 * every post, an invented reason value, a direct REST call that skips the
 * editor lock, or saving reasons on images they cannot edit. So: the reason is
 * a fixed list; the note is sanitize_text_field() and capped at NOTE_MAX
 * characters on every save path (attachment fields and block attributes, which
 * are cleaned in wp_insert_post_data whatever the client sent); every output
 * is escaped; attachment saves check edit_post on that attachment and ride
 * core's nonces; settings need manage_options and the options.php nonce; the
 * publish rule is enforced again on the server for REST saves, so turning off
 * JavaScript does not skip it; overrides are limited to chosen roles and each
 * one is logged on the post with the user and time. The REST field that
 * exposes the reason is only filled for users who can edit posts.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LFW_Alt_Text {

	const VERSION       = '1.0.0';
	const OPTION        = 'lfw_alt_text';
	const META_REASON   = '_lfw_alt_text_reason';
	const META_NOTE     = '_lfw_alt_text_note';
	const META_BY       = '_lfw_alt_text_by';
	const META_OVERRIDE = '_lfw_alt_text_overrides';
	const NOTE_MAX      = 200;
	const BLOCKS        = array( 'core/image', 'core/cover', 'core/media-text' );

	/** Set while a guarded save is let through by an override, logged after the save. */
	private static $pending_override = 0;

	/** Attachment status cache for one request. */
	private static $library = array();

	public static function init() {
		add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'fields' ), 10, 2 );
		add_filter( 'attachment_fields_to_save', array( __CLASS__, 'save_fields' ), 10, 2 );
		add_filter( 'manage_media_columns', array( __CLASS__, 'column' ) );
		add_action( 'manage_media_custom_column', array( __CLASS__, 'column_value' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'media_filter' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'media_filter_query' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'rest_field' ) );
		add_filter( 'register_block_type_args', array( __CLASS__, 'block_attributes' ), 10, 2 );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'clean_block_attributes' ), 9 );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'classic_guard' ), 20, 2 );
		add_action( 'save_post', array( __CLASS__, 'log_override' ) );
		add_action( 'init', array( __CLASS__, 'rest_guards' ), 99 );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'editor_assets' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menus' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'lfw-alt-text', 'LFW_Alt_Text_CLI' );
		}
	}

	/* ------------------------------------------------------------------ *
	 * The rule
	 * ------------------------------------------------------------------ */

	/** Reasons an image may have no alt text, in plain words. */
	public static function reasons() {
		return array(
			'decorative' => __( 'Decorative: it adds no information', 'lfw-alt-text' ),
			'described'  => __( 'Described in the surrounding text', 'lfw-alt-text' ),
			'other'      => __( 'Other reason (explain in the note)', 'lfw-alt-text' ),
		);
	}

	/**
	 * Clean a reason and note from any source. Returns array( reason, note ).
	 * The reason is one of the fixed keys or ''. "Other" without a note is not
	 * a reason. The note is plain text, at most NOTE_MAX characters, and empty
	 * when there is no reason.
	 */
	public static function sanitize_reason( $reason, $note ) {
		$reason = is_string( $reason ) ? sanitize_key( $reason ) : '';
		if ( ! array_key_exists( $reason, self::reasons() ) ) {
			return array( '', '' );
		}
		$note = is_scalar( $note ) ? sanitize_text_field( (string) $note ) : '';
		$note = function_exists( 'mb_substr' ) ? mb_substr( $note, 0, self::NOTE_MAX ) : substr( $note, 0, self::NOTE_MAX );
		$note = trim( $note );
		if ( 'other' === $reason && '' === $note ) {
			return array( '', '' );
		}
		return array( $reason, $note );
	}

	/** True when a reason and note together count as a recorded reason. */
	public static function valid_reason( $reason, $note ) {
		list( $r ) = self::sanitize_reason( $reason, $note );
		return '' !== $r;
	}

	public static function has_alt( $alt ) {
		return is_string( $alt ) && '' !== trim( $alt );
	}

	/**
	 * Media Library status of one image: array( 'status' => present|decorative|
	 * described|other|missing, 'note' => string ).
	 */
	public static function attachment_status( $id ) {
		$id = (int) $id;
		if ( isset( self::$library[ $id ] ) ) {
			return self::$library[ $id ];
		}
		$alt = get_post_meta( $id, '_wp_attachment_image_alt', true );
		if ( self::has_alt( $alt ) ) {
			$s = array( 'status' => 'present', 'note' => '' );
		} else {
			list( $reason, $note ) = self::sanitize_reason( get_post_meta( $id, self::META_REASON, true ), get_post_meta( $id, self::META_NOTE, true ) );
			$s = array( 'status' => '' !== $reason ? $reason : 'missing', 'note' => $note );
		}
		self::$library[ $id ] = $s;
		return $s;
	}

	public static function status_label( $s ) {
		if ( 'present' === $s['status'] ) {
			return __( 'Present', 'lfw-alt-text' );
		}
		if ( 'missing' === $s['status'] ) {
			return __( 'Missing', 'lfw-alt-text' );
		}
		$labels = array(
			'decorative' => __( 'None: decorative', 'lfw-alt-text' ),
			'described'  => __( 'None: described in the surrounding text', 'lfw-alt-text' ),
			'other'      => __( 'None: other reason', 'lfw-alt-text' ),
		);
		return $labels[ $s['status'] ];
	}

	/**
	 * Every image in a post's content, in document order. Each item is
	 * array( block, parent, alt (string|null), id, src, reason, note ).
	 * Blocks: core/image (gallery images are core/image too), core/cover with
	 * an image background, core/media-text with an image, and img tags inside
	 * classic content, the Classic block and Custom HTML.
	 */
	public static function images_in_content( $content ) {
		$out = array();
		if ( ! is_string( $content ) || '' === trim( $content ) ) {
			return $out;
		}
		self::walk( parse_blocks( $content ), '', $out );
		return $out;
	}

	private static function walk( $blocks, $parent, &$out ) {
		foreach ( $blocks as $b ) {
			$name   = isset( $b['blockName'] ) ? $b['blockName'] : null;
			$a      = isset( $b['attrs'] ) && is_array( $b['attrs'] ) ? $b['attrs'] : array();
			$html   = isset( $b['innerHTML'] ) ? (string) $b['innerHTML'] : '';
			$reason = isset( $a['lfwAltReason'] ) ? $a['lfwAltReason'] : '';
			$note   = isset( $a['lfwAltNote'] ) ? $a['lfwAltNote'] : '';
			if ( 'core/image' === $name ) {
				$img = self::imgs( $html, true );
				if ( $img && '' !== $img[0]['src'] ) {
					$out[] = self::item( $name, $parent, $img[0]['alt'], isset( $a['id'] ) ? (int) $a['id'] : $img[0]['id'], $img[0]['src'], $reason, $note );
				}
			} elseif ( 'core/media-text' === $name ) {
				if ( isset( $a['mediaType'] ) && 'image' === $a['mediaType'] && empty( $a['useFeaturedImage'] ) ) {
					$img = self::imgs( $html, true );
					if ( $img && '' !== $img[0]['src'] ) {
						$out[] = self::item( $name, $parent, $img[0]['alt'], isset( $a['mediaId'] ) ? (int) $a['mediaId'] : $img[0]['id'], $img[0]['src'], $reason, $note );
					}
				}
			} elseif ( 'core/cover' === $name ) {
				$type = isset( $a['backgroundType'] ) ? $a['backgroundType'] : 'image';
				if ( ! empty( $a['url'] ) && 'image' === $type && empty( $a['useFeaturedImage'] ) ) {
					$out[] = self::item( $name, $parent, isset( $a['alt'] ) ? (string) $a['alt'] : '', isset( $a['id'] ) ? (int) $a['id'] : 0, (string) $a['url'], $reason, $note );
				}
			} elseif ( null === $name || 'core/freeform' === $name || 'core/html' === $name ) {
				foreach ( self::imgs( $html ) as $img ) {
					$out[] = self::item( $name ? $name : 'classic', $parent, $img['alt'], $img['id'], $img['src'], '', '' );
				}
			}
			if ( ! empty( $b['innerBlocks'] ) ) {
				self::walk( $b['innerBlocks'], (string) $name, $out );
			}
		}
	}

	private static function item( $block, $parent, $alt, $id, $src, $reason, $note ) {
		return array(
			'block'  => $block,
			'parent' => $parent,
			'alt'    => $alt,
			'id'     => (int) $id,
			'src'    => (string) $src,
			'reason' => is_string( $reason ) ? $reason : '',
			'note'   => is_string( $note ) ? $note : '',
		);
	}

	/** img tags in an HTML fragment: array of array( alt|null, src, id ). */
	private static function imgs( $html, $first = false ) {
		$out = array();
		if ( false === stripos( $html, '<img' ) ) {
			return $out;
		}
		$p = new WP_HTML_Tag_Processor( $html );
		while ( $p->next_tag( 'img' ) ) {
			$alt   = $p->get_attribute( 'alt' );
			$src   = $p->get_attribute( 'src' );
			$class = $p->get_attribute( 'class' );
			$id    = ( is_string( $class ) && preg_match( '/\bwp-image-(\d+)\b/', $class, $m ) ) ? (int) $m[1] : 0;
			$out[] = array(
				'alt' => is_string( $alt ) ? $alt : ( true === $alt ? '' : null ),
				'src' => is_string( $src ) ? $src : '',
				'id'  => $id,
			);
			if ( $first ) {
				break;
			}
		}
		return $out;
	}

	/** True when an image passes: alt text, a reason on the block, or a reason in the Media Library. */
	public static function item_passes( $item ) {
		if ( self::has_alt( $item['alt'] ) || self::valid_reason( $item['reason'], $item['note'] ) ) {
			return true;
		}
		if ( $item['id'] > 0 ) {
			$s = self::attachment_status( $item['id'] );
			return array_key_exists( $s['status'], self::reasons() );
		}
		return false;
	}

	/** The images in a post's content that have no alt text and no reason. */
	public static function issues( $content ) {
		$out = array();
		foreach ( self::images_in_content( $content ) as $item ) {
			if ( ! self::item_passes( $item ) ) {
				$out[] = $item;
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ *
	 * Settings
	 * ------------------------------------------------------------------ */

	public static function defaults() {
		return array(
			'modes'          => array(
				'post' => 'enforce',
				'page' => 'enforce',
			),
			'override_roles' => array( 'administrator' ),
		);
	}

	public static function settings() {
		$s = get_option( self::OPTION );
		return is_array( $s ) ? array_merge( self::defaults(), $s ) : self::defaults();
	}

	/** Post types this plugin can check: editable in the admin, with content. */
	public static function post_types() {
		$out = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $name => $obj ) {
			if ( 'attachment' === $name || 0 === strpos( $name, 'wp_' ) || ! post_type_supports( $name, 'editor' ) ) {
				continue;
			}
			$out[ $name ] = $obj;
		}
		return $out;
	}

	/** off, warn or enforce. Types not saved yet default to warn. */
	public static function mode_for( $post_type ) {
		$s = self::settings();
		if ( isset( $s['modes'][ $post_type ] ) && in_array( $s['modes'][ $post_type ], array( 'off', 'warn', 'enforce' ), true ) ) {
			return $s['modes'][ $post_type ];
		}
		return array_key_exists( $post_type, self::post_types() ) ? 'warn' : 'off';
	}

	/** Whether a user's role lets them publish past the rule. */
	public static function can_override( $user_id = 0 ) {
		$user = $user_id ? get_userdata( $user_id ) : wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return false;
		}
		if ( is_multisite() && is_super_admin( $user->ID ) ) {
			return true;
		}
		$roles = (array) self::settings()['override_roles'];
		return (bool) array_intersect( $roles, (array) $user->roles );
	}

	public static function sanitize_settings( $in ) {
		$in  = is_array( $in ) ? $in : array();
		$out = array(
			'modes'          => array(),
			'override_roles' => array(),
		);
		foreach ( array_keys( self::post_types() ) as $pt ) {
			$m                   = isset( $in['modes'][ $pt ] ) ? (string) $in['modes'][ $pt ] : 'off';
			$out['modes'][ $pt ] = in_array( $m, array( 'off', 'warn', 'enforce' ), true ) ? $m : 'off';
		}
		$roles                 = array_keys( wp_roles()->roles );
		$given                 = isset( $in['override_roles'] ) ? array_map( 'strval', (array) $in['override_roles'] ) : array();
		$out['override_roles'] = array_values( array_intersect( $given, $roles ) );
		return $out;
	}

	public static function register_settings() {
		register_setting(
			'lfw_alt_text',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => self::defaults(),
			)
		);
	}

	/* ------------------------------------------------------------------ *
	 * Media Library
	 * ------------------------------------------------------------------ */

	private static function is_image( $post ) {
		return $post && 0 === strpos( (string) get_post_mime_type( $post ), 'image/' );
	}

	/** Status, reason and note in the attachment details (media modal and edit screen). */
	public static function fields( $fields, $post ) {
		if ( ! self::is_image( $post ) ) {
			return $fields;
		}
		$id     = (int) $post->ID;
		$s      = self::attachment_status( $id );
		$reason = (string) get_post_meta( $id, self::META_REASON, true );
		$note   = (string) get_post_meta( $id, self::META_NOTE, true );
		$color  = 'missing' === $s['status'] ? ' style="color:#b32d2e"' : '';
		$text   = '<strong' . $color . '>' . esc_html( self::status_label( $s ) ) . '</strong>';
		if ( 'missing' === $s['status'] ) {
			$text .= '<br>' . esc_html__( 'Add alternative text above, or choose a reason it has none.', 'lfw-alt-text' );
		}
		$fields['lfw_alt_text_status'] = array(
			'label' => __( 'Alt text check', 'lfw-alt-text' ),
			'input' => 'html',
			'html'  => '<p id="lfw-alt-text-status-' . $id . '">' . $text . '</p>',
		);

		$options = '<option value="">' . esc_html__( 'No reason: this image needs alt text', 'lfw-alt-text' ) . '</option>';
		foreach ( self::reasons() as $key => $label ) {
			$options .= '<option value="' . esc_attr( $key ) . '"' . selected( $reason, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$fields['lfw_alt_text_reason'] = array(
			'label' => __( 'If there is no alt text, why?', 'lfw-alt-text' ),
			'input' => 'html',
			'html'  => '<select name="attachments[' . $id . '][lfw_alt_text_reason]" id="attachments-' . $id . '-lfw_alt_text_reason" aria-describedby="lfw-alt-text-status-' . $id . '">' . $options . '</select>',
		);
		$fields['lfw_alt_text_note']   = array(
			'label' => __( 'Reason note', 'lfw-alt-text' ),
			'input' => 'html',
			'html'  => '<input type="text" class="text" maxlength="' . self::NOTE_MAX . '" name="attachments[' . $id . '][lfw_alt_text_note]" id="attachments-' . $id . '-lfw_alt_text_note" value="' . esc_attr( $note ) . '">',
			/* translators: %d: maximum number of characters. */
			'helps' => sprintf( __( 'Required for "Other". Up to %d characters. Editors see this note.', 'lfw-alt-text' ), self::NOTE_MAX ),
		);
		return $fields;
	}

	/**
	 * Save the reason. Core checks the nonce on both save paths (the modal's
	 * save-attachment-compat and post.php); the capability is checked here too.
	 */
	public static function save_fields( $post, $attachment ) {
		$id = isset( $post['ID'] ) ? (int) $post['ID'] : 0;
		if ( ! $id || ! is_array( $attachment ) || ! array_key_exists( 'lfw_alt_text_reason', $attachment ) || ! current_user_can( 'edit_post', $id ) ) {
			return $post;
		}
		list( $reason, $note ) = self::sanitize_reason( $attachment['lfw_alt_text_reason'], isset( $attachment['lfw_alt_text_note'] ) ? $attachment['lfw_alt_text_note'] : '' );
		$before                = (string) get_post_meta( $id, self::META_REASON, true ) . '|' . (string) get_post_meta( $id, self::META_NOTE, true );
		if ( '' === $reason ) {
			delete_post_meta( $id, self::META_REASON );
			delete_post_meta( $id, self::META_NOTE );
		} else {
			update_post_meta( $id, self::META_REASON, $reason );
			update_post_meta( $id, self::META_NOTE, $note );
		}
		if ( $before !== $reason . '|' . $note ) {
			update_post_meta( $id, self::META_BY, array( 'user' => get_current_user_id(), 'time' => time() ) );
		}
		unset( self::$library[ $id ] );
		return $post;
	}

	public static function column( $cols ) {
		$cols['lfw_alt_text'] = __( 'Alt text', 'lfw-alt-text' );
		return $cols;
	}

	public static function column_value( $name, $id ) {
		if ( 'lfw_alt_text' !== $name || ! self::is_image( $id ) ) {
			return;
		}
		$s = self::attachment_status( $id );
		if ( 'missing' === $s['status'] ) {
			echo '<span style="color:#b32d2e">' . esc_html( self::status_label( $s ) ) . '</span>';
			return;
		}
		echo esc_html( self::status_label( $s ) );
		if ( '' !== $s['note'] ) {
			echo '<br><span class="description">' . esc_html( $s['note'] ) . '</span>';
		}
	}

	/** Meta query for images with no alt text and no reason. */
	public static function missing_meta_query() {
		return array(
			'relation' => 'AND',
			array(
				'relation' => 'OR',
				array( 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_wp_attachment_image_alt', 'value' => '' ),
			),
			array( 'key' => self::META_REASON, 'compare' => 'NOT EXISTS' ),
		);
	}

	private static function filter_value() {
		$v = isset( $_GET['lfw_alt'] ) ? sanitize_key( wp_unslash( $_GET['lfw_alt'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- a read-only list filter.
		return in_array( $v, array( 'missing', 'reason', 'present' ), true ) ? $v : '';
	}

	public static function media_filter( $post_type ) {
		if ( 'attachment' !== $post_type ) {
			return;
		}
		$v    = self::filter_value();
		$opts = array(
			''        => __( 'Any alt text status', 'lfw-alt-text' ),
			'missing' => __( 'Alt text missing', 'lfw-alt-text' ),
			'reason'  => __( 'No alt text, reason recorded', 'lfw-alt-text' ),
			'present' => __( 'Alt text present', 'lfw-alt-text' ),
		);
		echo '<label for="lfw-alt-filter" class="screen-reader-text">' . esc_html__( 'Filter by alt text', 'lfw-alt-text' ) . '</label><select name="lfw_alt" id="lfw-alt-filter">';
		foreach ( $opts as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $v, $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	public static function media_filter_query( $q ) {
		global $pagenow;
		if ( ! is_admin() || ! $q->is_main_query() || 'upload.php' !== $pagenow ) {
			return;
		}
		$v = self::filter_value();
		if ( '' === $v ) {
			return;
		}
		$q->set( 'post_mime_type', 'image' );
		$q->set( 'meta_query', self::status_meta_query( $v ) );
	}

	private static function status_meta_query( $v, $reason = '' ) {
		if ( 'missing' === $v ) {
			return self::missing_meta_query();
		}
		if ( 'present' === $v ) {
			return array( array( 'key' => '_wp_attachment_image_alt', 'value' => '', 'compare' => '!=' ) );
		}
		return array(
			'relation' => 'AND',
			array(
				'relation' => 'OR',
				array( 'key' => '_wp_attachment_image_alt', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_wp_attachment_image_alt', 'value' => '' ),
			),
			$reason ? array( 'key' => self::META_REASON, 'value' => $reason ) : array( 'key' => self::META_REASON, 'compare' => 'EXISTS' ),
		);
	}

	/** The library status for the block editor. Filled only for users who can edit posts. */
	public static function rest_field() {
		register_rest_field(
			'attachment',
			'lfw_alt_text',
			array(
				'get_callback' => function ( $obj ) {
					if ( ! current_user_can( 'edit_posts' ) || empty( $obj['id'] ) || ! self::is_image( (int) $obj['id'] ) ) {
						return null;
					}
					return self::attachment_status( (int) $obj['id'] );
				},
				'schema'       => array(
					'description' => __( 'Alt text status recorded by LFW Alt Text.', 'lfw-alt-text' ),
					'type'        => array( 'object', 'null' ),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
			)
		);
	}

	/* ------------------------------------------------------------------ *
	 * Block attributes and saving
	 * ------------------------------------------------------------------ */

	/** Register the reason attributes on the server too, so server rendering accepts them. */
	public static function block_attributes( $args, $name ) {
		if ( in_array( $name, self::BLOCKS, true ) ) {
			$args['attributes']                 = isset( $args['attributes'] ) ? $args['attributes'] : array();
			$args['attributes']['lfwAltReason'] = array( 'type' => 'string', 'default' => '' );
			$args['attributes']['lfwAltNote']   = array( 'type' => 'string', 'default' => '' );
		}
		return $args;
	}

	/**
	 * Clean lfwAltReason / lfwAltNote in block markup whatever the client sent.
	 * Returns the content unchanged (byte for byte) when nothing needed fixing.
	 */
	public static function sanitize_block_markup( $content ) {
		if ( ! is_string( $content ) || false === strpos( $content, 'lfwAlt' ) ) {
			return $content;
		}
		$changed = false;
		$blocks  = self::clean_blocks( parse_blocks( $content ), $changed );
		return $changed ? serialize_blocks( $blocks ) : $content;
	}

	private static function clean_blocks( $blocks, &$changed ) {
		foreach ( $blocks as &$b ) {
			if ( isset( $b['attrs'] ) && is_array( $b['attrs'] ) && ( isset( $b['attrs']['lfwAltReason'] ) || isset( $b['attrs']['lfwAltNote'] ) ) ) {
				$r0 = isset( $b['attrs']['lfwAltReason'] ) ? $b['attrs']['lfwAltReason'] : '';
				$n0 = isset( $b['attrs']['lfwAltNote'] ) ? $b['attrs']['lfwAltNote'] : '';
				list( $r, $n ) = in_array( $b['blockName'], self::BLOCKS, true ) ? self::sanitize_reason( $r0, $n0 ) : array( '', '' );
				if ( $r !== $r0 || $n !== $n0 ) {
					$changed = true;
					unset( $b['attrs']['lfwAltReason'], $b['attrs']['lfwAltNote'] );
					if ( '' !== $r ) {
						$b['attrs']['lfwAltReason'] = $r;
						if ( '' !== $n ) {
							$b['attrs']['lfwAltNote'] = $n;
						}
					}
				}
			}
			if ( ! empty( $b['innerBlocks'] ) ) {
				$b['innerBlocks'] = self::clean_blocks( $b['innerBlocks'], $changed );
			}
		}
		return $blocks;
	}

	/** Every save path goes through wp_insert_post_data; content arrives slashed. */
	public static function clean_block_attributes( $data ) {
		if ( empty( $data['post_content'] ) || false === strpos( $data['post_content'], 'lfwAlt' ) ) {
			return $data;
		}
		$raw   = wp_unslash( $data['post_content'] );
		$clean = self::sanitize_block_markup( $raw );
		if ( $clean !== $raw ) {
			$data['post_content'] = wp_slash( $clean );
		}
		return $data;
	}

	/** The message for a blocked publish. */
	public static function blocked_message( $count ) {
		/* translators: %d: number of images. */
		return sprintf( _n( 'Not published: %d image has no alt text and no reason it has none. Add alt text or mark it decorative, then publish again.', 'Not published: %d images have no alt text and no reason they have none. Add alt text or mark them decorative, then publish again.', $count, 'lfw-alt-text' ), $count );
	}

	/** Server-side rule for REST saves (the block editor), so the editor lock cannot be skipped. */
	public static function rest_guards() {
		foreach ( get_post_types( array( 'show_in_rest' => true ) ) as $pt ) {
			add_filter( 'rest_pre_insert_' . $pt, array( __CLASS__, 'rest_guard' ), 20, 2 );
		}
	}

	public static function rest_guard( $prepared, $request ) {
		if ( is_wp_error( $prepared ) || ! is_object( $prepared ) ) {
			return $prepared;
		}
		$existing = ! empty( $prepared->ID ) ? get_post( (int) $prepared->ID ) : null;
		$type     = isset( $prepared->post_type ) ? $prepared->post_type : ( $existing ? $existing->post_type : '' );
		$status   = isset( $prepared->post_status ) ? $prepared->post_status : ( $existing ? $existing->post_status : 'draft' );
		if ( ! in_array( $status, array( 'publish', 'future' ), true ) || 'enforce' !== self::mode_for( $type ) ) {
			return $prepared;
		}
		$content = isset( $prepared->post_content ) ? $prepared->post_content : ( $existing ? $existing->post_content : '' );
		$issues  = self::issues( self::sanitize_block_markup( $content ) );
		if ( ! $issues ) {
			return $prepared;
		}
		if ( self::can_override() ) {
			self::$pending_override = count( $issues );
			return $prepared;
		}
		return new WP_Error( 'lfw_alt_text_missing', self::blocked_message( count( $issues ) ), array( 'status' => 400, 'images' => count( $issues ) ) );
	}

	/**
	 * Classic editor: a first publish with images that need attention is saved
	 * as a draft (or kept pending) with a notice. An already published post is
	 * not taken offline; it saves and the notice warns.
	 */
	public static function classic_guard( $data, $postarr ) {
		// phpcs:disable WordPress.Security.NonceVerification -- core verified the editpost nonce before wp_insert_post runs.
		if ( ! is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || empty( $_POST['action'] ) || 'editpost' !== $_POST['action'] || isset( $_GET['meta-box-loader'] ) ) {
			return $data;
		}
		// phpcs:enable
		if ( ! in_array( $data['post_status'], array( 'publish', 'future' ), true ) || 'enforce' !== self::mode_for( $data['post_type'] ) ) {
			return $data;
		}
		$issues = self::issues( wp_unslash( $data['post_content'] ) );
		if ( ! $issues ) {
			return $data;
		}
		if ( self::can_override() ) {
			self::$pending_override = count( $issues );
			return $data;
		}
		$was = ! empty( $postarr['ID'] ) ? get_post_status( (int) $postarr['ID'] ) : '';
		if ( in_array( $was, array( 'publish', 'future' ), true ) ) {
			self::flash( 'warning', sprintf( /* translators: %d: number of images. */ _n( 'Saved, but %d image on this live page has no alt text and no reason it has none.', 'Saved, but %d images on this live page have no alt text and no reason they have none.', count( $issues ), 'lfw-alt-text' ), count( $issues ) ) );
			return $data;
		}
		$data['post_status'] = 'pending' === $was ? 'pending' : 'draft';
		self::flash( 'error', self::blocked_message( count( $issues ) ) . ' ' . __( 'It was saved as a draft.', 'lfw-alt-text' ) );
		return $data;
	}

	/** Record an override on the post: who, when, how many images. Keeps the last 20. */
	public static function log_override( $post_id ) {
		if ( ! self::$pending_override || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$log   = get_post_meta( $post_id, self::META_OVERRIDE, true );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			'user'   => get_current_user_id(),
			'time'   => time(),
			'images' => (int) self::$pending_override,
		);
		update_post_meta( $post_id, self::META_OVERRIDE, array_slice( $log, -20 ) );
		self::$pending_override = 0;
	}

	private static function flash( $type, $message ) {
		set_transient( 'lfw_alt_text_flash_' . get_current_user_id(), array( 'type' => $type, 'message' => $message ), 120 );
	}

	public static function notices() {
		$key   = 'lfw_alt_text_flash_' . get_current_user_id();
		$flash = get_transient( $key );
		if ( is_array( $flash ) ) {
			delete_transient( $key );
			printf( '<div class="notice notice-%s"><p>%s</p></div>', esc_attr( 'error' === $flash['type'] ? 'error' : 'warning' ), esc_html( $flash['message'] ) );
		}
		// The classic editor gets a standing note while the post has images that need attention.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || ( method_exists( $screen, 'is_block_editor' ) && $screen->is_block_editor() ) ) {
			return;
		}
		$post = get_post();
		if ( ! $post || 'off' === self::mode_for( $post->post_type ) ) {
			return;
		}
		$n = count( self::issues( $post->post_content ) );
		if ( $n ) {
			/* translators: %d: number of images. */
			printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html( sprintf( _n( '%d image in this content has no alt text and no reason it has none. Add alt text in the image settings, or record a reason in the Media Library.', '%d images in this content have no alt text and no reason they have none. Add alt text in the image settings, or record a reason in the Media Library.', $n, 'lfw-alt-text' ), $n ) ) );
		}
	}

	/* ------------------------------------------------------------------ *
	 * Block editor
	 * ------------------------------------------------------------------ */

	public static function editor_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->base, array( 'post', 'site-editor' ), true ) ) {
			return;
		}
		$url = plugin_dir_url( __FILE__ ) . 'assets/';
		wp_enqueue_script( 'lfw-alt-text-rules', $url . 'rules.js', array(), self::VERSION, true );
		wp_enqueue_script( 'lfw-alt-text-editor', $url . 'editor.js', array( 'lfw-alt-text-rules', 'wp-plugins', 'wp-editor', 'wp-element', 'wp-components', 'wp-data', 'wp-hooks', 'wp-compose', 'wp-block-editor', 'wp-i18n', 'wp-a11y', 'wp-notices', 'wp-core-data' ), self::VERSION, true );
		$type = 'post' === $screen->base ? (string) $screen->post_type : '';
		wp_add_inline_script(
			'lfw-alt-text-editor',
			'window.lfwAltText = ' . wp_json_encode(
				array(
					'mode'        => $type ? self::mode_for( $type ) : 'off',
					'canOverride' => self::can_override(),
					'noteMax'     => self::NOTE_MAX,
				)
			) . ';',
			'before'
		);
		wp_set_script_translations( 'lfw-alt-text-editor', 'lfw-alt-text' );
	}

	/* ------------------------------------------------------------------ *
	 * Admin pages: Tools > Alt text (report), Settings > Alt text
	 * ------------------------------------------------------------------ */

	public static function menus() {
		add_management_page( __( 'Alt text report', 'lfw-alt-text' ), __( 'Alt text', 'lfw-alt-text' ), 'edit_others_posts', 'lfw-alt-text', array( __CLASS__, 'report_page' ) );
		add_options_page( __( 'Alt text', 'lfw-alt-text' ), __( 'Alt text', 'lfw-alt-text' ), 'manage_options', 'lfw-alt-text-settings', array( __CLASS__, 'settings_page' ) );
	}

	private static function count_images( $meta_query = null ) {
		$args = array(
			'post_type'              => 'attachment',
			'post_status'            => 'inherit',
			'post_mime_type'         => 'image',
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);
		if ( $meta_query ) {
			$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery -- an admin report.
		}
		$q = new WP_Query( $args );
		return (int) $q->found_posts;
	}

	/**
	 * Everything the report and the CLI show. $limit caps how many posts with
	 * images are read (newest modified first); $show caps the image list.
	 */
	public static function audit( $limit = 500, $show = 100 ) {
		global $wpdb;
		$counts = array(
			'images'     => self::count_images(),
			'present'    => self::count_images( self::status_meta_query( 'present' ) ),
			'decorative' => self::count_images( self::status_meta_query( 'reason', 'decorative' ) ),
			'described'  => self::count_images( self::status_meta_query( 'reason', 'described' ) ),
			'other'      => self::count_images( self::status_meta_query( 'reason', 'other' ) ),
			'missing'    => self::count_images( self::missing_meta_query() ),
		);
		$missing = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'posts_per_page' => (int) $show,
				'fields'         => 'ids',
				'meta_query'     => self::missing_meta_query(), // phpcs:ignore WordPress.DB.SlowDBQuery -- an admin report.
			)
		);
		$types = array_keys( self::post_types() );
		$posts = array();
		$ids   = array();
		if ( $types ) {
			$in  = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			$sql = "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($in) AND post_status IN ('publish','future','draft','pending','private') AND ( post_content LIKE %s OR post_content LIKE %s ) ORDER BY post_modified_gmt DESC LIMIT %d";
			$ids = $wpdb->get_col( $wpdb->prepare( $sql, array_merge( $types, array( '%<img%', '%wp:cover%', (int) $limit + 1 ) ) ) ); // phpcs:ignore WordPress.DB
		}
		$capped = count( $ids ) > $limit;
		$ids    = array_slice( array_map( 'intval', $ids ), 0, $limit );
		foreach ( $ids as $id ) {
			$p = get_post( $id );
			$n = $p ? count( self::issues( $p->post_content ) ) : 0;
			if ( $n ) {
				$posts[] = array(
					'id'     => $id,
					'title'  => get_the_title( $p ),
					'type'   => $p->post_type,
					'status' => $p->post_status,
					'mode'   => self::mode_for( $p->post_type ),
					'images' => $n,
				);
			}
		}
		$overrides = get_posts(
			array(
				'post_type'      => $types ? $types : 'post',
				'post_status'    => 'any',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'meta_key'       => self::META_OVERRIDE, // phpcs:ignore WordPress.DB.SlowDBQuery -- an admin report.
			)
		);
		return array(
			'counts'         => $counts,
			'missing_images' => array_map( 'intval', $missing ),
			'posts'          => $posts,
			'checked'        => count( $ids ),
			'capped'         => $capped,
			'overrides'      => array_map( 'intval', $overrides ),
		);
	}

	public static function report_page() {
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this report.', 'lfw-alt-text' ) );
		}
		$a = self::audit();
		$c = $a['counts'];
		echo '<div class="wrap"><h1>' . esc_html__( 'Alt text report', 'lfw-alt-text' ) . '</h1>';
		echo '<p>' . esc_html__( 'Images without alt text and without a recorded reason, in the Media Library and in your content. This report checks that a choice was made; a person still reviews whether the alt text and reasons are right.', 'lfw-alt-text' ) . '</p>';

		echo '<h2>' . esc_html__( 'Media Library', 'lfw-alt-text' ) . '</h2><ul>';
		/* translators: %d: count. */
		$rows = array(
			sprintf( __( '%d images in total', 'lfw-alt-text' ), $c['images'] ),
			/* translators: %d: count. */
			sprintf( __( '%d with alt text', 'lfw-alt-text' ), $c['present'] ),
			/* translators: %d: count. */
			sprintf( __( '%d marked decorative', 'lfw-alt-text' ), $c['decorative'] ),
			/* translators: %d: count. */
			sprintf( __( '%d described in the surrounding text', 'lfw-alt-text' ), $c['described'] ),
			/* translators: %d: count. */
			sprintf( __( '%d with another recorded reason', 'lfw-alt-text' ), $c['other'] ),
		);
		foreach ( $rows as $r ) {
			echo '<li>' . esc_html( $r ) . '</li>';
		}
		/* translators: %d: count. */
		echo '<li><strong>' . esc_html( sprintf( __( '%d missing alt text and a reason', 'lfw-alt-text' ), $c['missing'] ) ) . '</strong> <a href="' . esc_url( admin_url( 'upload.php?mode=list&lfw_alt=missing' ) ) . '">' . esc_html__( 'Show them in the Media Library', 'lfw-alt-text' ) . '</a></li></ul>';

		if ( $a['missing_images'] ) {
			/* translators: %d: count shown. */
			echo '<h3>' . esc_html( sprintf( __( 'Newest images missing alt text (up to %d)', 'lfw-alt-text' ), 100 ) ) . '</h3><table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Image', 'lfw-alt-text' ) . '</th><th scope="col">' . esc_html__( 'Uploaded', 'lfw-alt-text' ) . '</th></tr></thead><tbody>';
			foreach ( $a['missing_images'] as $id ) {
				echo '<tr><td><a href="' . esc_url( get_edit_post_link( $id ) ) . '">' . esc_html( get_the_title( $id ) ? get_the_title( $id ) : basename( (string) get_attached_file( $id ) ) ) . '</a></td><td>' . esc_html( get_the_date( '', $id ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		echo '<h2>' . esc_html__( 'Content', 'lfw-alt-text' ) . '</h2>';
		$found = count( $a['posts'] );
		if ( $a['capped'] ) {
			/* translators: 1: number of posts checked, 2: number with problems. */
			echo '<p>' . esc_html( sprintf( __( 'Checked the %1$d most recently changed posts that contain images. At least %2$d have images without alt text or a reason. Run "wp lfw-alt-text audit --limit=0" to check everything.', 'lfw-alt-text' ), $a['checked'], $found ) ) . '</p>';
		} else {
			/* translators: 1: number of posts checked, 2: number with problems. */
			echo '<p>' . esc_html( sprintf( __( 'Checked %1$d posts that contain images. %2$d have images without alt text or a reason.', 'lfw-alt-text' ), $a['checked'], $found ) ) . '</p>';
		}
		if ( $a['posts'] ) {
			echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Title', 'lfw-alt-text' ) . '</th><th scope="col">' . esc_html__( 'Type', 'lfw-alt-text' ) . '</th><th scope="col">' . esc_html__( 'Status', 'lfw-alt-text' ) . '</th><th scope="col">' . esc_html__( 'Images needing attention', 'lfw-alt-text' ) . '</th></tr></thead><tbody>';
			foreach ( $a['posts'] as $p ) {
				$title = '' !== $p['title'] ? $p['title'] : __( '(no title)', 'lfw-alt-text' );
				$link  = get_edit_post_link( $p['id'] );
				echo '<tr><td>' . ( $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title ) ) . '</td><td>' . esc_html( $p['type'] ) . '</td><td>' . esc_html( $p['status'] ) . '</td><td>' . esc_html( (string) $p['images'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		if ( $a['overrides'] ) {
			echo '<h2>' . esc_html__( 'Published with an override', 'lfw-alt-text' ) . '</h2><table class="widefat striped"><thead><tr><th scope="col">' . esc_html__( 'Title', 'lfw-alt-text' ) . '</th><th scope="col">' . esc_html__( 'Last override', 'lfw-alt-text' ) . '</th></tr></thead><tbody>';
			foreach ( $a['overrides'] as $id ) {
				$log  = get_post_meta( $id, self::META_OVERRIDE, true );
				$last = is_array( $log ) && $log ? end( $log ) : null;
				$who  = $last ? get_userdata( (int) $last['user'] ) : null;
				$link = get_edit_post_link( $id );
				$text = $last ? sprintf( /* translators: 1: user name, 2: date, 3: number of images. */ _n( '%1$s, %2$s, %3$d image', '%1$s, %2$s, %3$d images', (int) $last['images'], 'lfw-alt-text' ), $who ? $who->display_name : __( 'unknown user', 'lfw-alt-text' ), wp_date( get_option( 'date_format' ), (int) $last['time'] ), (int) $last['images'] ) : '';
				echo '<tr><td>' . ( $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( get_the_title( $id ) ) . '</a>' : esc_html( get_the_title( $id ) ) ) . '</td><td>' . esc_html( $text ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	public static function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s     = self::settings();
		$modes = array(
			'enforce' => __( 'Block publishing', 'lfw-alt-text' ),
			'warn'    => __( 'Warn only', 'lfw-alt-text' ),
			'off'     => __( 'Off', 'lfw-alt-text' ),
		);
		echo '<div class="wrap"><h1>' . esc_html__( 'Alt text', 'lfw-alt-text' ) . '</h1>';
		echo '<p>' . esc_html__( 'Every image needs alternative text or a recorded reason it has none. Choose where that is required before publishing, and who may publish anyway.', 'lfw-alt-text' ) . '</p>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'lfw_alt_text' );
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( self::post_types() as $pt => $obj ) {
			$cur = self::mode_for( $pt );
			echo '<tr><th scope="row">' . esc_html( $obj->labels->name ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html( $obj->labels->name ) . '</legend>';
			foreach ( $modes as $key => $label ) {
				echo '<label style="margin-right:1.5em"><input type="radio" name="' . esc_attr( self::OPTION ) . '[modes][' . esc_attr( $pt ) . ']" value="' . esc_attr( $key ) . '"' . checked( $cur, $key, false ) . '> ' . esc_html( $label ) . '</label>';
			}
			echo '</fieldset></td></tr>';
		}
		echo '<tr><th scope="row">' . esc_html__( 'Who may publish anyway', 'lfw-alt-text' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Who may publish anyway', 'lfw-alt-text' ) . '</legend>';
		foreach ( wp_roles()->roles as $role => $info ) {
			echo '<label style="display:block"><input type="checkbox" name="' . esc_attr( self::OPTION ) . '[override_roles][]" value="' . esc_attr( $role ) . '"' . checked( in_array( $role, (array) $s['override_roles'], true ), true, false ) . '> ' . esc_html( translate_user_role( $info['name'] ) ) . '</label>';
		}
		echo '<p class="description">' . esc_html__( 'These roles see a "Publish anyway" choice. Each use is recorded on the post and listed in Tools > Alt text.', 'lfw-alt-text' ) . '</p></fieldset></td></tr>';
		echo '</tbody></table>';
		submit_button();
		echo '</form></div>';
	}
}

/**
 * Report images without alt text or a recorded reason.
 */
class LFW_Alt_Text_CLI {

	/**
	 * Count images by alt text status and list what needs attention.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : How many posts with images to check, newest changed first. 0 checks all.
	 * ---
	 * default: 500
	 * ---
	 *
	 * [--format=<format>]
	 * : table, json or csv.
	 * ---
	 * default: table
	 * ---
	 *
	 * [--strict]
	 * : Exit with an error when anything needs attention (for CI or cron).
	 *
	 * ## EXAMPLES
	 *     wp lfw-alt-text audit
	 *     wp lfw-alt-text audit --limit=0 --format=json
	 *
	 * @when after_wp_load
	 */
	public function audit( $args, $assoc ) {
		$limit  = isset( $assoc['limit'] ) ? (int) $assoc['limit'] : 500;
		$format = isset( $assoc['format'] ) ? $assoc['format'] : 'table';
		$a      = LFW_Alt_Text::audit( $limit > 0 ? $limit : PHP_INT_MAX, $limit > 0 ? 100 : -1 );
		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $a ) );
		} else {
			WP_CLI::line( 'Media Library images:' );
			$counts = array();
			foreach ( $a['counts'] as $k => $v ) {
				$counts[] = array( 'status' => $k, 'count' => $v );
			}
			WP_CLI\Utils\format_items( $format, $counts, array( 'status', 'count' ) );
			if ( $a['missing_images'] ) {
				WP_CLI::line( '' );
				WP_CLI::line( 'Images missing alt text and a reason:' );
				$rows = array();
				foreach ( $a['missing_images'] as $id ) {
					$rows[] = array( 'id' => $id, 'file' => basename( (string) get_attached_file( $id ) ) );
				}
				WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'file' ) );
			}
			WP_CLI::line( '' );
			WP_CLI::line( sprintf( '%s %d posts with images; %s%d need attention:', $a['capped'] ? 'Checked the newest' : 'Checked', $a['checked'], $a['capped'] ? 'at least ' : '', count( $a['posts'] ) ) );
			if ( $a['posts'] ) {
				WP_CLI\Utils\format_items( $format, $a['posts'], array( 'id', 'type', 'status', 'mode', 'images', 'title' ) );
			}
		}
		if ( isset( $assoc['strict'] ) && ( $a['counts']['missing'] || $a['posts'] ) ) {
			WP_CLI::halt( 1 );
		}
	}
}

LFW_Alt_Text::init();
