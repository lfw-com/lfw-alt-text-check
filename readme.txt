=== LFW Alt Text ===
Contributors: lfw
Tags: alt text, accessibility, images, media, wcag
Requires at least: 6.6
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

An image cannot be published without alternative text or a recorded reason it has none.

== Description ==

An empty alt attribute means two different things on most sites. Sometimes the image really is decorative. Usually someone forgot. A screen reader user gets the same silence either way, and nobody can tell afterward which it was.

This plugin makes the choice explicit. Every image needs one of these:

* alternative text
* a recorded reason it has none: decorative, described in the surrounding text, or another reason with a short note

**In the Media Library**, each image shows its status in the attachment details (the media modal and the edit screen), with a reason list and a note field. The list view has an "Alt text" column (Present, None: decorative, Missing) and a filter for images that are missing alt text.

**In the block editor**, an "Alt text" panel in the pre-publish checks and in the post settings lists each image that needs attention, with "Go to image" and "Mark decorative" buttons. It covers Image blocks (including gallery images), Cover backgrounds, Media & Text images, and images inside Classic and Custom HTML blocks. Each Image, Cover and Media & Text block also gets an "Alt text check" panel to record a reason. Marking an image decorative sets alt="" on purpose and saves the choice on the block, so it is not flagged again.

**Publishing is blocked** until every image passes, for the post types you choose. Drafts keep saving. The Publish button in the pre-publish panel, and the Update button on a live post, stay disabled while anything needs attention, and screen readers hear when publishing is blocked and when it unlocks. The server checks again on every REST save, so a direct API call or a disabled script does not get around it.

**Overrides**: administrators (or the roles you choose) can tick "Publish anyway". Each override is recorded on the post with the user, the time and the number of images.

**Tools > Alt text** reports how many library images have alt text, a reason or neither, and lists the posts that contain images needing attention. `wp lfw-alt-text audit` prints the same from the command line.

**Settings > Alt text** sets "Block publishing", "Warn only" or "Off" for each post type, and which roles may publish anyway. By default posts and pages block publishing, other post types warn, and only administrators may override.

Everything runs on your own site. The plugin makes no network calls and needs no account.

= What it does not do =

It does not judge whether alt text is good. "Image" or "IMG_2041.jpg" passes. It checks that someone made a choice, and a person still reviews what the alt text says and whether "decorative" was the right call. The report lists every recorded reason so that review is quick.

It does not check images added by other plugins' blocks, images in synced patterns (check the pattern itself), or theme templates.

In the classic editor, a first publish with images that need attention is saved as a draft instead, with a notice. An already published post is not taken offline: the update saves and the notice warns. Quick Edit and bulk edit are not checked; the report catches those.

= Abuse case =

The reason note is free text that a contributor can save and an editor will later read. So the reason is a fixed list; the note is stripped to plain text and capped at 200 characters on every save path, including block attributes sent straight to the API; every output is escaped; saving a reason on an image requires permission to edit that image, through core's nonces; settings require manage_options; the publish rule runs again on the server; overrides are limited to chosen roles and logged; and the reason is exposed in the REST API only to users who can edit posts.

== Installation ==

1. Upload the `lfw-alt-text` folder to `/wp-content/plugins/`, or install the zip from Plugins > Add New.
2. Activate it. Posts and pages now require alt text or a reason before publishing.
3. Optional: adjust Settings > Alt text, and run `wp lfw-alt-text audit` to see what is already published.

== Frequently Asked Questions ==

= Core says "Leave empty if decorative." Does this fight that? =

No. Core's Image block in WordPress 6.8 treats an empty alt field as decorative, but does not record that anyone decided so. This plugin keeps core's alt field as is and adds the recorded choice next to it.

= Does a reason in the Media Library count in posts? =

Yes. If an image is marked decorative (or another reason) in the Media Library, blocks that use it with an empty alt field pass. If the library image has alt text but the block's alt field was emptied, the block is flagged.

== Changelog ==

= 1.0.0 =
* First release.
