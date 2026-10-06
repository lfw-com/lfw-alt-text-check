# LFW Alt Text

A small WordPress plugin: an image cannot be published without alternative text or a recorded reason it has none (decorative, described in the surrounding text, or another reason with a note). Free and open source (GPL-2.0-or-later), from [LFW](https://lfw.com).

- Status, reason and note in the Media Library attachment details, an "Alt text" column and a "missing" filter.
- A pre-publish panel in the block editor that lists each image needing attention, with "Go to image" and "Mark decorative".
- Blocks publishing (per post type) until every image passes; the server checks REST saves too. Chosen roles may override, and each override is logged.
- Tools > Alt text report and `wp lfw-alt-text audit`.
- No network calls, no account.

It checks that a choice was made. It does not judge whether the alt text is good; a person still reviews that.

## Tests

- `node --test wp-plugins/lfw-alt-text/tests/*.test.mjs` runs the editor rule and publish lock logic (`assets/rules.js`).
- `wp-plugins/lfw-alt-text/test.sh` runs a throwaway WordPress 6.8 in Docker and checks save sanitization, capability checks, the server-side publish rule, overrides, settings, the REST field and the CLI. `KEEP=1` leaves it running on port 8094 (admin / admin, editor ed / ed).

## How the publish lock works (WordPress 6.8)

`lockPostSaving('lfw-alt-text')` disables the Publish button inside the pre-publish panel and the Update button, but it also disables Save draft. So the editor takes the lock only while the pre-publish panel is open or the post is already published or scheduled; drafts keep saving. The header "Publish" toggle stays usable so the panel can open and show the list. If a user has turned off pre-publish checks, the header button publishes directly and the server refuses the save (`rest_pre_insert_{post_type}` returns `lfw_alt_text_missing`), which the editor shows as an error notice.
