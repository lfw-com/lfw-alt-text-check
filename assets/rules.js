/**
 * LFW Alt Text: the rule, shared by the block editor panel and the tests.
 * An image passes when it has alt text, a recorded reason on the block, or a
 * recorded reason in the Media Library. No WordPress calls here, so it runs
 * the same in the browser and in Node. The PHP twin is
 * LFW_Alt_Text::images_in_content() and ::item_passes().
 */
(function (root) {
  "use strict";

  const REASONS = ["decorative", "described", "other"];
  const NOTE_MAX = 200;

  function hasAlt(alt) {
    return typeof alt === "string" && alt.trim() !== "";
  }

  /** Plain text, no tags, at most NOTE_MAX characters. The server cleans it again. */
  function cleanNote(note) {
    if (typeof note !== "string") return "";
    return note
      .replace(/<[^>]*>/g, "")
      .replace(/\s+/g, " ")
      .trim()
      .slice(0, NOTE_MAX);
  }

  function validReason(reason, note) {
    if (REASONS.indexOf(reason) === -1) return false;
    if (reason === "other") return cleanNote(note) !== "";
    return true;
  }

  function attr(tag, name) {
    const m = new RegExp("\\s" + name + "\\s*=\\s*(\"([^\"]*)\"|'([^']*)'|([^\\s>]+))", "i").exec(
      tag
    );
    if (m) return m[2] !== undefined ? m[2] : m[3] !== undefined ? m[3] : m[4];
    return new RegExp("\\s" + name + "(?=[\\s/>])", "i").test(tag) ? "" : null;
  }

  /** img tags in an HTML string: [{ alt (string|null), src, id }]. */
  function imgTags(html) {
    const out = [];
    if (typeof html !== "string") return out;
    const re = /<img\b[^>]*>/gi;
    let m;
    while ((m = re.exec(html))) {
      const cls = attr(m[0], "class") || "";
      const id = /\bwp-image-(\d+)\b/.exec(cls);
      out.push({
        alt: attr(m[0], "alt"),
        src: attr(m[0], "src") || "",
        id: id ? Number(id[1]) : 0,
      });
    }
    return out;
  }

  /**
   * Every image in a block tree, in document order. Blocks are editor block
   * objects: { name, clientId, attributes, innerBlocks }.
   * Each item: { clientId, block, parent, altKey, alt, id, src, reason, note, canMark }.
   */
  function collect(blocks, parent, out) {
    out = out || [];
    (blocks || []).forEach(function (b) {
      const a = b.attributes || {};
      const base = {
        clientId: b.clientId,
        block: b.name,
        parent: parent || "",
        reason: a.lfwAltReason || "",
        note: a.lfwAltNote || "",
      };
      if (b.name === "core/image") {
        if (a.url)
          out.push(
            Object.assign(base, {
              altKey: "alt",
              alt: a.alt,
              id: a.id || 0,
              src: a.url,
              canMark: true,
            })
          );
      } else if (b.name === "core/cover") {
        if (a.url && (a.backgroundType || "image") === "image" && !a.useFeaturedImage) {
          out.push(
            Object.assign(base, {
              altKey: "alt",
              alt: a.alt,
              id: a.id || 0,
              src: a.url,
              canMark: true,
            })
          );
        }
      } else if (b.name === "core/media-text") {
        if (a.mediaType === "image" && a.mediaUrl && !a.useFeaturedImage) {
          out.push(
            Object.assign(base, {
              altKey: "mediaAlt",
              alt: a.mediaAlt,
              id: a.mediaId || 0,
              src: a.mediaUrl,
              canMark: true,
            })
          );
        }
      } else if (b.name === "core/freeform" || b.name === "core/html") {
        imgTags(a.content).forEach(function (img) {
          out.push(
            Object.assign({}, base, {
              reason: "",
              note: "",
              altKey: "",
              alt: img.alt,
              id: img.id,
              src: img.src,
              canMark: false,
            })
          );
        });
      }
      if (b.innerBlocks && b.innerBlocks.length) collect(b.innerBlocks, b.name, out);
    });
    return out;
  }

  /** True when the item passes. library(id) returns { status } from the Media Library, or null. */
  function passes(item, library) {
    if (hasAlt(item.alt) || validReason(item.reason, item.note)) return true;
    if (item.id && typeof library === "function") {
      const lib = library(item.id);
      return !!lib && REASONS.indexOf(lib.status) !== -1;
    }
    return false;
  }

  function issues(items, library) {
    return items.filter(function (i) {
      return !passes(i, library);
    });
  }

  /**
   * Whether the editor should hold the publish button. Locks only when
   * enforcing, something needs attention, nobody chose to override, and the
   * user is about to publish (the publish panel is open) or the post is
   * already live (the Update button). Drafts keep saving.
   */
  function shouldLock(s) {
    if (s.mode !== "enforce" || !s.issueCount) return false;
    if (s.canOverride && s.override) return false;
    return !!(s.publishPanelOpen || s.status === "publish" || s.status === "future");
  }

  root.lfwAltTextRules = {
    REASONS: REASONS,
    NOTE_MAX: NOTE_MAX,
    hasAlt: hasAlt,
    cleanNote: cleanNote,
    validReason: validReason,
    imgTags: imgTags,
    collect: collect,
    passes: passes,
    issues: issues,
    shouldLock: shouldLock,
  };
})(globalThis);
