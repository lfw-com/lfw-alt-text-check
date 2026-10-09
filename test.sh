#!/usr/bin/env bash
# Throwaway WordPress 6.8 in Docker: activate LFW Alt Text Check and check the save
# sanitization, the Media Library status, the server-side publish rule for REST
# saves, overrides, settings and `wp lfw-alt-text-check audit`. Tears down after.
#   test.sh          run the checks
#   KEEP=1 test.sh   leave WordPress running on :8094 (admin / admin)
# The editor rule and lock logic have their own tests: node --test tests/*.test.mjs
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"; PORT="${PORT:-8094}"; FAILED=0
cleanup(){ [ -n "${KEEP:-}" ] || { docker rm -f lfwalt-db lfwalt-wp >/dev/null 2>&1 || true; docker network rm lfwalt >/dev/null 2>&1 || true; }; }
docker rm -f lfwalt-db lfwalt-wp >/dev/null 2>&1 || true; docker network rm lfwalt >/dev/null 2>&1 || true; trap cleanup EXIT
check(){ if [ "$2" = "$3" ]; then echo "  ok    $1"; else echo "  FAIL  $1 (got '$2', want '$3')"; FAILED=1; fi; }
has(){ if grep -q -- "$3" <<<"$2"; then echo "  ok    $1"; else echo "  FAIL  $1 (missing: $3)"; FAILED=1; fi; }
hasnt(){ if grep -q -- "$3" <<<"$2"; then echo "  FAIL  $1 (found: $3)"; FAILED=1; else echo "  ok    $1"; fi; }

docker network create lfwalt >/dev/null
docker run -d --name lfwalt-db --network lfwalt -e MARIADB_ROOT_PASSWORD=root -e MARIADB_DATABASE=wp -e MARIADB_USER=wp -e MARIADB_PASSWORD=wp mariadb:11 >/dev/null
docker run -d --name lfwalt-wp --network lfwalt -p "$PORT:80" -e WORDPRESS_DB_HOST=lfwalt-db -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp \
  -v "$HERE:/var/www/html/wp-content/plugins/lfw-alt-text-check:ro" wordpress:6.8-php8.2-apache >/dev/null
for i in $(seq 1 60); do docker exec lfwalt-db mariadb -uwp -pwp -e 'select 1' wp >/dev/null 2>&1 && curl -s -o /dev/null "http://localhost:$PORT/" && break; sleep 2; done
WP="docker run --rm -i --network lfwalt --volumes-from lfwalt-wp --user 33:33 -e WORDPRESS_DB_HOST=lfwalt-db -e WORDPRESS_DB_USER=wp -e WORDPRESS_DB_PASSWORD=wp -e WORDPRESS_DB_NAME=wp wordpress:cli-php8.2 wp"
# php CODE [ARGS...] [--user=x]: run PHP with arguments in $args (wp eval takes none).
php(){ local code="$1"; shift; printf '<?php %s' "$code" | $WP eval-file - "$@" 2>&1 | tail -1; }
docker exec lfwalt-wp bash -c 'mkdir -p /var/www/html/wp-content/uploads && chown www-data:www-data /var/www/html/wp-content /var/www/html/wp-content/uploads'
$WP core install --url="http://localhost:$PORT" --title=Test --admin_user=admin --admin_password=admin --admin_email=a@b.co --skip-email >/dev/null
$WP plugin activate lfw-alt-text-check >/dev/null
$WP user create ed ed@b.co --role=editor --user_pass=ed >/dev/null
$WP user create sub sub@b.co --role=subscriber --user_pass=sub >/dev/null

echo "==> Media: two images, one with alt text"
docker exec lfwalt-wp php -r 'mkdir("/var/www/html/imgtest");$i=imagecreatetruecolor(40,30); imagefill($i,0,0,imagecolorallocate($i,30,90,200)); imagepng($i,"/var/www/html/imgtest/a.png"); imagepng($i,"/var/www/html/imgtest/b.png");'
docker exec lfwalt-wp bash -c 'chmod 644 /var/www/html/imgtest/a.png /var/www/html/imgtest/b.png'
A=$($WP media import /var/www/html/imgtest/a.png --porcelain --alt="A blue rectangle")
B=$($WP media import /var/www/html/imgtest/b.png --porcelain)
check "image with alt text is present" "$($WP eval "echo LFW_Alt_Text_Check::attachment_status($A)['status'];")" "present"
check "image without alt text is missing" "$($WP eval "echo LFW_Alt_Text_Check::attachment_status($B)['status'];")" "missing"

echo "==> Save sanitization"
check "unknown reason is dropped" "$($WP eval 'echo json_encode(LFW_Alt_Text_Check::sanitize_reason("bogus","x"));')" '["",""]'
check "Other without a note is not a reason" "$($WP eval 'echo json_encode(LFW_Alt_Text_Check::sanitize_reason("other","   "));')" '["",""]'
check "tags are stripped from the note" "$($WP eval 'echo json_encode(LFW_Alt_Text_Check::sanitize_reason("other","<script>alert(1)</script>Logo of the county"));')" '["other","Logo of the county"]'
check "the note is capped at 200 characters" "$($WP eval 'echo mb_strlen(LFW_Alt_Text_Check::sanitize_reason("decorative",str_repeat("é",500))[1]);')" "200"
check "reason keys are normalized" "$($WP eval 'echo LFW_Alt_Text_Check::sanitize_reason("Decorative","")[0];')" "decorative"
SAVE='$r = apply_filters("attachment_fields_to_save", array("ID"=>'"$B"'), array("lfw_alt_text_check_reason"=>"decorative","lfw_alt_text_check_note"=>"<b>Divider</b>")); clean_post_cache('"$B"'); echo get_post_meta('"$B"',"_lfw_alt_text_check_reason",true);'
check "a subscriber cannot record a reason" "$($WP eval "$SAVE" --user=sub)" ""
check "an editor can record a reason" "$($WP eval "$SAVE" --user=ed)" "decorative"
check "the saved note is plain text" "$($WP post meta get "$B" _lfw_alt_text_check_note)" "Divider"
has "who and when are recorded" "$($WP post meta get "$B" _lfw_alt_text_check_by --format=json)" '"user":'
check "the library status follows" "$($WP eval "echo LFW_Alt_Text_Check::attachment_status($B)['status'];")" "decorative"
CLEAR='apply_filters("attachment_fields_to_save", array("ID"=>'"$B"'), array("lfw_alt_text_check_reason"=>"")); echo metadata_exists("post",'"$B"',"_lfw_alt_text_check_reason")?"kept":"gone";'
check "choosing no reason clears it" "$($WP eval "$CLEAR" --user=ed)" "gone"
has "the status shows in the attachment details" "$($WP eval "\$f = apply_filters('attachment_fields_to_edit', array(), get_post($B)); echo strip_tags(\$f['lfw_alt_text_check_status']['html']);")" "Missing"

echo "==> Block attributes are cleaned on save, whatever the client sent"
EVIL='<!-- wp:image {"lfwAltReason":"decorative","lfwAltNote":"<img src=x onerror=alert(1)>ok'"$(printf 'x%.0s' $(seq 1 300))"'"} --><figure class="wp-block-image"><img src="/a.png" alt=""/></figure><!-- /wp:image --><!-- wp:image {"lfwAltReason":"hacked"} --><figure class="wp-block-image"><img src="/b.png" alt=""/></figure><!-- /wp:image -->'
P=$($WP post create --post_status=draft --post_title=Evil --post_content="$EVIL" --porcelain)
SAVED="$($WP post get "$P" --field=post_content)"
hasnt "no markup survives in the note" "$SAVED" 'onerror'
hasnt "an invented reason is removed" "$SAVED" 'hacked'
check "the note is capped" "$($WP eval "\$b = parse_blocks(get_post($P)->post_content); echo mb_strlen(\$b[0]['attrs']['lfwAltNote']);")" "200"
CLEAN='<!-- wp:image {"lfwAltReason":"described"} --><figure class="wp-block-image"><img src="/a.png" alt=""/></figure><!-- /wp:image -->'
check "clean markup is left byte for byte" "$(php 'echo LFW_Alt_Text_Check::sanitize_block_markup($args[0]) === $args[0] ? "same" : "changed";' "$CLEAN")" "same"

echo "==> Finding images"
MIXED='<!-- wp:image {"id":'"$A"'} --><figure class="wp-block-image"><img src="/a.png" alt="" class="wp-image-'"$A"'"/></figure><!-- /wp:image --><!-- wp:gallery --><figure class="wp-block-gallery"><!-- wp:image {"id":'"$B"'} --><figure class="wp-block-image"><img src="/b.png" alt="" class="wp-image-'"$B"'"/></figure><!-- /wp:image --><!-- wp:image --><figure class="wp-block-image"><img src="/c.png" alt="Three"/></figure><!-- /wp:image --></figure><!-- /wp:gallery --><!-- wp:cover {"url":"/bg.png","dimRatio":50} --><div class="wp-block-cover"><img class="wp-block-cover__image-background" alt="" src="/bg.png"/><div class="wp-block-cover__inner-container"></div></div><!-- /wp:cover --><!-- wp:media-text {"mediaId":0,"mediaType":"image"} --><div class="wp-block-media-text"><figure class="wp-block-media-text__media"><img src="/m.png" alt=""/></figure><div class="wp-block-media-text__content"></div></div><!-- /wp:media-text --><!-- wp:html --><p><img src="/h.png"></p><!-- /wp:html --><!-- wp:image --><figure class="wp-block-image"><img alt=""/></figure><!-- /wp:image -->'
M=$($WP post create --post_status=draft --post_title=Mixed --post_content="$MIXED" --porcelain)
check "every kind of image is found (placeholder skipped)" "$($WP eval "echo count(LFW_Alt_Text_Check::images_in_content(get_post($M)->post_content));")" "6"
check "five need attention (the alt-text library image's block alt is empty)" "$($WP eval "echo count(LFW_Alt_Text_Check::issues(get_post($M)->post_content));")" "5"
$WP post meta update "$B" _lfw_alt_text_check_reason decorative >/dev/null
check "a library reason covers the gallery image" "$($WP eval "echo count(LFW_Alt_Text_Check::issues(get_post($M)->post_content));")" "4"
check "classic content images are found" "$($WP eval 'echo json_encode(array_column(LFW_Alt_Text_Check::images_in_content("<p><img src=\"/x.png\" alt=\"\"><img src=\"/y.png\" alt=\"Y\"></p>"),"alt"));')" '["","Y"]'

echo "==> The publish rule for REST saves (the block editor)"
NEED='<!-- wp:image --><figure class="wp-block-image"><img src="/a.png" alt=""/></figure><!-- /wp:image -->'
FIXED='<!-- wp:image {"lfwAltReason":"decorative"} --><figure class="wp-block-image"><img src="/a.png" alt=""/></figure><!-- /wp:image -->'
REST='$r = new WP_REST_Request("POST", "/wp/v2/" . $args[0]); $r->set_body_params(array("title"=>"T","status"=>$args[1],"content"=>$args[2])); $res = rest_do_request($r); echo $res->is_error() ? $res->as_error()->get_error_code() : $res->get_data()["status"];'
rest(){ php "$REST" "$@" --user="$U"; }
U=ed
check "an editor cannot publish a post with an image that needs attention" "$(rest posts publish "$NEED")" "lfw_alt_text_check_missing"
check "or schedule one" "$(php '$r = new WP_REST_Request("POST", "/wp/v2/posts"); $r->set_body_params(array("title"=>"T","status"=>"future","date"=>gmdate("Y-m-d\TH:i:s", time()+86400),"content"=>$args[0])); $res = rest_do_request($r); echo $res->is_error() ? $res->as_error()->get_error_code() : "ok";' "$NEED" --user=ed)" "lfw_alt_text_check_missing"
has "the message says what to do" "$(php '$r = new WP_REST_Request("POST", "/wp/v2/pages"); $r->set_body_params(array("status"=>"publish","content"=>$args[0])); echo rest_do_request($r)->as_error()->get_error_message();' "$NEED" --user=ed)" "mark it decorative"
check "a draft saves" "$(rest posts draft "$NEED")" "draft"
check "marked decorative, it publishes" "$(rest posts publish "$FIXED")" "publish"
check "with alt text, it publishes" "$(rest posts publish '<!-- wp:image --><figure class="wp-block-image"><img src="/a.png" alt="A heron"/></figure><!-- /wp:image -->')" "publish"
D=$($WP post create --post_status=draft --post_title=Draft --post_content="$NEED" --porcelain)
check "publishing an existing draft by status alone is blocked" "$(php '$r = new WP_REST_Request("POST", "/wp/v2/posts/" . $args[0]); $r->set_body_params(array("status"=>"publish")); $res = rest_do_request($r); echo $res->is_error() ? $res->as_error()->get_error_code() : "published";' "$D" --user=ed)" "lfw_alt_text_check_missing"
U=admin
check "an administrator may publish anyway" "$(rest posts publish "$NEED")" "publish"
O=$($WP post list --post_type=post --post_status=publish --orderby=ID --order=DESC --posts_per_page=1 --field=ID)
has "the override is recorded on the post" "$($WP post meta get "$O" _lfw_alt_text_check_overrides --format=json)" '"images":1'

echo "==> Settings"
$WP eval 'update_option("lfw_alt_text_check", LFW_Alt_Text_Check::sanitize_settings(array("modes"=>array("post"=>"warn","page"=>"nonsense"),"override_roles"=>array("editor","not-a-role"))));' >/dev/null
check "invalid modes become off" "$($WP eval 'echo LFW_Alt_Text_Check::mode_for("page");')" "off"
check "unknown roles are dropped" "$($WP option get lfw_alt_text_check --format=json | node -p 'JSON.stringify(JSON.parse(require("fs").readFileSync(0)).override_roles)')" '["editor"]'
U=ed
check "warn only lets an editor publish" "$(rest posts publish "$NEED")" "publish"
check "the editor role can now override" "$($WP eval 'echo LFW_Alt_Text_Check::can_override() ? "yes" : "no";' --user=ed)" "yes"
check "the administrator role no longer can" "$($WP eval 'echo LFW_Alt_Text_Check::can_override() ? "yes" : "no";' --user=admin)" "no"
$WP option delete lfw_alt_text_check >/dev/null
check "defaults: posts and pages enforce" "$($WP eval 'echo LFW_Alt_Text_Check::mode_for("post"), LFW_Alt_Text_Check::mode_for("page");')" "enforceenforce"

echo "==> REST field privacy"
hasnt "a logged-out visitor does not see reasons" "$(php '$r = rest_do_request(new WP_REST_Request("GET", "/wp/v2/media/" . $args[0])); echo wp_json_encode($r->get_data());' "$B")" "decorative"
check "an editor does" "$(php '$r = rest_do_request(new WP_REST_Request("GET", "/wp/v2/media/" . $args[0])); echo $r->get_data()["lfw_alt_text_check"]["status"];' "$B" --user=ed)" "decorative"

echo "==> wp lfw-alt-text-check audit"
OUT="$($WP lfw-alt-text-check audit 2>&1)"; echo "$OUT" | sed 's/^/    /'
has "audit counts library images" "$OUT" "missing"
has "audit lists posts that need attention" "$OUT" "Mixed"
check "--strict exits non-zero when something needs attention" "$($WP lfw-alt-text-check audit --strict >/dev/null 2>&1 && echo 0 || echo 1)" "1"
check "json output parses" "$($WP lfw-alt-text-check audit --format=json | node -p 'typeof JSON.parse(require("fs").readFileSync(0)).counts.images')" "number"

echo "==> Admin pages render"
REPORT="$($WP eval 'set_current_screen("tools_page_lfw-alt-text-check"); LFW_Alt_Text_Check::report_page();' --user=admin)"
has "the report renders" "$REPORT" "Alt text report"
has "the report lists the post" "$REPORT" "Mixed"
SET="$($WP eval 'LFW_Alt_Text_Check::settings_page();' --user=admin)"
has "the settings page renders" "$SET" 'name="lfw_alt_text_check\[modes\]\[post\]"'

[ "$FAILED" = 0 ] && echo "All checks passed." || { echo "Some checks FAILED."; exit 1; }
