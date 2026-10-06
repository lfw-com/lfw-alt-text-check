/**
 * LFW Alt Text in the block editor. No build step.
 * 1. Adds lfwAltReason / lfwAltNote to the Image, Cover and Media & Text blocks.
 * 2. A block settings panel to record why an image has no alt text.
 * 3. An "Alt text" panel in the pre-publish checks and the post settings that
 *    lists each image needing attention, with "Go to image" and "Mark decorative".
 * 4. When enforcing, holds the Publish / Update button with lockPostSaving until
 *    every image passes (or an allowed role chooses to publish anyway), and
 *    announces the change to screen readers. The server checks again on save.
 */
(function (wp) {
  "use strict";

  const el = wp.element.createElement;
  const Fragment = wp.element.Fragment;
  const __ = wp.i18n.__,
    _n = wp.i18n._n,
    sprintf = wp.i18n.sprintf;
  const R = window.lfwAltTextRules;
  const cfg = window.lfwAltText || { mode: "off", canOverride: false, noteMax: 200 };
  const ALT_KEY = { "core/image": "alt", "core/cover": "alt", "core/media-text": "mediaAlt" };
  const LOCK = "lfw-alt-text";
  const NOTICE = "lfw-alt-text-lock";
  const D = "lfw-alt-text";

  // 1. The attributes. Registered whatever the mode, so a saved choice is never dropped.
  wp.hooks.addFilter(
    "blocks.registerBlockType",
    "lfw-alt-text/attributes",
    function (settings, name) {
      if (!ALT_KEY[name]) return settings;
      return Object.assign({}, settings, {
        attributes: Object.assign({}, settings.attributes, {
          lfwAltReason: { type: "string", default: "" },
          lfwAltNote: { type: "string", default: "" },
        }),
      });
    }
  );

  function reasonOptions() {
    return [
      { value: "", label: __("No reason: this image needs alt text", D) },
      { value: "decorative", label: __("Decorative: it adds no information", D) },
      { value: "described", label: __("Described in the surrounding text", D) },
      { value: "other", label: __("Other reason (explain in the note)", D) },
    ];
  }

  // 2. The block settings panel.
  const withPanel = wp.compose.createHigherOrderComponent(function (BlockEdit) {
    return function (props) {
      if (!ALT_KEY[props.name] || !props.isSelected) return el(BlockEdit, props);
      const items = R.collect([
        {
          name: props.name,
          clientId: props.clientId,
          attributes: props.attributes,
          innerBlocks: [],
        },
      ]);
      if (!items.length) return el(BlockEdit, props);
      const a = props.attributes,
        item = items[0];
      let body;
      if (R.hasAlt(item.alt)) {
        body = el("p", null, __("This image has alt text.", D));
      } else {
        body = el(
          Fragment,
          null,
          el(wp.components.SelectControl, {
            __nextHasNoMarginBottom: true,
            __next40pxDefaultSize: true,
            label: __("If there is no alt text, why?", D),
            help: __(
              "Images need alt text unless they add no information or the text around them already says what they show.",
              D
            ),
            value: a.lfwAltReason || "",
            options: reasonOptions(),
            onChange: function (v) {
              const next = { lfwAltReason: v };
              if (!v) next.lfwAltNote = "";
              if (v === "decorative") next[ALT_KEY[props.name]] = "";
              props.setAttributes(next);
            },
          }),
          a.lfwAltReason
            ? el(wp.components.TextControl, {
                __nextHasNoMarginBottom: true,
                __next40pxDefaultSize: true,
                label:
                  a.lfwAltReason === "other" ? __("Note (required)", D) : __("Note (optional)", D),
                help: sprintf(__("Up to %d characters. Editors see this note.", D), cfg.noteMax),
                value: a.lfwAltNote || "",
                maxLength: cfg.noteMax,
                onChange: function (v) {
                  props.setAttributes({ lfwAltNote: String(v).slice(0, cfg.noteMax) });
                },
              })
            : null
        );
      }
      return el(
        Fragment,
        null,
        el(BlockEdit, props),
        el(
          wp.blockEditor.InspectorControls,
          null,
          el(
            wp.components.PanelBody,
            { title: __("Alt text check", D), initialOpen: !R.hasAlt(item.alt) },
            body
          )
        )
      );
    };
  }, "withLfwAltTextPanel");
  wp.hooks.addFilter("editor.BlockEdit", "lfw-alt-text/panel", withPanel);

  // 3 and 4. The document check.
  const PrePublish =
    (wp.editor && wp.editor.PluginPrePublishPanel) ||
    (wp.editPost && wp.editPost.PluginPrePublishPanel);
  const DocPanel =
    (wp.editor && wp.editor.PluginDocumentSettingPanel) ||
    (wp.editPost && wp.editPost.PluginDocumentSettingPanel);
  const useSelect = wp.data.useSelect,
    useMemo = wp.element.useMemo,
    useEffect = wp.element.useEffect,
    useRef = wp.element.useRef,
    useState = wp.element.useState;

  function blockLabel(item) {
    if (item.block === "core/image")
      return item.parent === "core/gallery" ? __("Gallery image", D) : __("Image block", D);
    if (item.block === "core/cover") return __("Cover background", D);
    if (item.block === "core/media-text") return __("Media & Text image", D);
    if (item.block === "core/html") return __("Image in Custom HTML", D);
    return __("Image in Classic block", D);
  }

  function fileName(src) {
    const s = String(src || "")
      .split("?")[0]
      .split("#")[0];
    let name = s.slice(s.lastIndexOf("/") + 1);
    try {
      name = decodeURIComponent(name);
    } catch (e) {}
    return name.length > 40 ? name.slice(0, 37) + "..." : name;
  }

  function goTo(item) {
    const ed = wp.data.dispatch("core/editor");
    if (wp.data.select("core/editor").isPublishSidebarOpened()) ed.closePublishSidebar();
    wp.data.dispatch("core/block-editor").selectBlock(item.clientId);
    try {
      wp.data.dispatch("core/edit-post").openGeneralSidebar("edit-post/block");
    } catch (e) {}
    setTimeout(function () {
      const frame = document.querySelector('iframe[name="editor-canvas"]');
      const doc = frame && frame.contentDocument ? frame.contentDocument : document;
      const node = doc.getElementById("block-" + item.clientId);
      if (node) {
        node.scrollIntoView({ block: "center" });
        node.focus();
      }
    }, 60);
  }

  function markDecorative(item, n) {
    const next = { lfwAltReason: "decorative", lfwAltNote: "" };
    next[item.altKey] = "";
    wp.data.dispatch("core/block-editor").updateBlockAttributes(item.clientId, next);
    wp.a11y.speak(sprintf(__("Image %d marked decorative.", D), n), "polite");
  }

  function Check(props) {
    const s = props.state;
    const kids = [];
    if (!s.items.length) return el("p", null, __("There are no images in this content.", D));
    if (!s.issues.length) {
      kids.push(
        el(
          "p",
          { key: "ok" },
          sprintf(
            _n(
              "The %d image has alt text or a recorded reason.",
              "All %d images have alt text or a recorded reason.",
              s.items.length,
              D
            ),
            s.items.length
          )
        )
      );
      return el(Fragment, null, kids);
    }
    const lead =
      cfg.mode === "enforce"
        ? s.locked
          ? __(
              "Publishing is blocked until each image below has alt text or a reason it has none.",
              D
            )
          : __(
              "Each image below needs alt text or a reason it has none before this can be published.",
              D
            )
        : __("These images have no alt text and no reason. You can still publish.", D);
    kids.push(el("p", { key: "lead" }, el("strong", null, lead)));
    kids.push(
      el(
        "ul",
        {
          key: "list",
          className: "lfw-alt-text-list",
          style: { margin: "0 0 1em", padding: 0, listStyle: "none" },
        },
        s.issues.map(function (item) {
          const n = s.items.indexOf(item) + 1;
          return el(
            "li",
            {
              key: item.clientId + "-" + n,
              style: { margin: "0 0 12px", paddingBottom: "12px", borderBottom: "1px solid #ddd" },
            },
            el(
              "div",
              null,
              el("strong", null, sprintf(__("Image %d", D), n)),
              " ",
              blockLabel(item)
            ),
            el(
              "div",
              { style: { color: "#555", wordBreak: "break-all", margin: "2px 0 6px" } },
              fileName(item.src)
            ),
            el(
              "div",
              { style: { display: "flex", gap: "8px", flexWrap: "wrap" } },
              el(
                wp.components.Button,
                {
                  variant: "secondary",
                  size: "compact",
                  onClick: function () {
                    goTo(item);
                  },
                  "aria-label": sprintf(__("Go to image %d", D), n),
                },
                __("Go to image", D)
              ),
              item.canMark
                ? el(
                    wp.components.Button,
                    {
                      variant: "tertiary",
                      size: "compact",
                      onClick: function () {
                        markDecorative(item, n);
                      },
                      "aria-label": sprintf(__("Mark image %d decorative", D), n),
                    },
                    __("Mark decorative", D)
                  )
                : null
            ),
            item.canMark
              ? null
              : el(
                  "p",
                  { className: "description", style: { margin: "6px 0 0" } },
                  __(
                    "Add alt text in the image settings of this block, or record a reason for the image in the Media Library.",
                    D
                  )
                )
          );
        })
      )
    );
    if (cfg.mode === "enforce" && cfg.canOverride) {
      kids.push(
        el(wp.components.CheckboxControl, {
          key: "override",
          __nextHasNoMarginBottom: true,
          label: __("Publish anyway. This is recorded with your name.", D),
          checked: s.override,
          onChange: s.setOverride,
        })
      );
    }
    return el(Fragment, null, kids);
  }

  function AltTextCheck() {
    const blocks = useSelect(function (select) {
      return select("core/block-editor").getBlocks();
    }, []);
    const items = useMemo(
      function () {
        return R.collect(blocks);
      },
      [blocks]
    );
    const ids = items
      .filter(function (i) {
        return i.id && !R.hasAlt(i.alt) && !R.validReason(i.reason, i.note);
      })
      .map(function (i) {
        return i.id;
      })
      .filter(function (id, k, all) {
        return all.indexOf(id) === k;
      })
      .join(",");
    // A string, so useSelect returns the same value until a library record changes.
    const libKey = useSelect(
      function (select) {
        const core = select("core");
        return ids
          .split(",")
          .filter(Boolean)
          .map(function (id) {
            const m = core.getMedia(Number(id), { context: "view" });
            return id + ":" + (m && m.lfw_alt_text ? m.lfw_alt_text.status : "");
          })
          .join(",");
      },
      [ids]
    );
    const issues = useMemo(
      function () {
        const lib = {};
        libKey
          .split(",")
          .filter(Boolean)
          .forEach(function (pair) {
            const p = pair.split(":");
            lib[p[0]] = { status: p[1] };
          });
        return R.issues(items, function (id) {
          return lib[id] || null;
        });
      },
      [items, libKey]
    );
    const status = useSelect(function (select) {
      return select("core/editor").getCurrentPostAttribute("status");
    }, []);
    const panelOpen = useSelect(function (select) {
      return select("core/editor").isPublishSidebarOpened();
    }, []);
    const ov = useState(false),
      override = ov[0],
      setOverride = ov[1];
    const locked = R.shouldLock({
      mode: cfg.mode,
      issueCount: issues.length,
      canOverride: cfg.canOverride,
      override: override,
      publishPanelOpen: panelOpen,
      status: status,
    });
    const wasLocked = useRef(false);

    useEffect(
      function () {
        const editor = wp.data.dispatch("core/editor");
        const notices = wp.data.dispatch("core/notices");
        const live = status === "publish" || status === "future";
        if (locked) {
          editor.lockPostSaving(LOCK);
          const msg = sprintf(
            _n(
              "%1$s is blocked: %2$d image has no alt text and no reason. See Alt text in the post settings.",
              "%1$s is blocked: %2$d images have no alt text and no reason. See Alt text in the post settings.",
              issues.length,
              D
            ),
            live ? __("Updating", D) : __("Publishing", D),
            issues.length
          );
          if (live) {
            // A notice is announced by core; the pre-publish panel shows the list itself.
            notices.createNotice("warning", msg, { id: NOTICE, isDismissible: false });
          } else if (!wasLocked.current) {
            wp.a11y.speak(msg, "assertive");
          }
        } else {
          editor.unlockPostSaving(LOCK);
          notices.removeNotice(NOTICE);
          if (wasLocked.current) {
            wp.a11y.speak(
              issues.length
                ? __("Publishing is unlocked by your override.", D)
                : __("Every image has alt text or a reason. Publishing is unlocked.", D),
              "polite"
            );
          }
        }
        wasLocked.current = locked;
      },
      [locked, issues.length, status]
    );

    useEffect(function () {
      return function () {
        wp.data.dispatch("core/editor").unlockPostSaving(LOCK);
      };
    }, []);

    if (cfg.mode === "off") return null;
    const state = {
      items: items,
      issues: issues,
      locked: locked,
      override: override,
      setOverride: setOverride,
    };
    const title = issues.length
      ? sprintf(
          _n(
            "Alt text: %d image needs attention",
            "Alt text: %d images need attention",
            issues.length,
            D
          ),
          issues.length
        )
      : __("Alt text", D);
    return el(
      Fragment,
      null,
      PrePublish && items.length
        ? el(
            PrePublish,
            { title: title, initialOpen: true, className: "lfw-alt-text-prepublish" },
            el(Check, { state: state })
          )
        : null,
      DocPanel
        ? el(
            DocPanel,
            { name: "lfw-alt-text", title: title, className: "lfw-alt-text-panel" },
            el(Check, { state: state })
          )
        : null
    );
  }

  if (cfg.mode !== "off" && wp.plugins && PrePublish) {
    wp.plugins.registerPlugin("lfw-alt-text", { render: AltTextCheck });
  }
})(window.wp);
