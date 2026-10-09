// Tests for the editor rule and lock logic (assets/rules.js).
//   node --test tests/*.test.mjs
import { test } from "node:test";
import assert from "node:assert/strict";
import "../assets/rules.js";

const R = globalThis.lfwAltTextRules;
const img = (attrs, clientId = "a") => ({
  name: "core/image",
  clientId,
  attributes: attrs,
  innerBlocks: [],
});

test("an image with alt text passes; empty or whitespace alt does not", () => {
  const items = R.collect([
    img({ url: "/a.jpg", alt: "A heron" }, "a"),
    img({ url: "/b.jpg", alt: "" }, "b"),
    img({ url: "/c.jpg", alt: "   " }, "c"),
  ]);
  assert.deepEqual(
    R.issues(items).map((i) => i.clientId),
    ["b", "c"]
  );
});

test("an image block with no image yet is not counted", () => {
  assert.equal(R.collect([img({ alt: "" })]).length, 0);
});

test("a recorded reason on the block passes; Other needs a note", () => {
  const items = R.collect([
    img({ url: "/a.jpg", lfwAltReason: "decorative" }, "a"),
    img({ url: "/b.jpg", lfwAltReason: "described" }, "b"),
    img({ url: "/c.jpg", lfwAltReason: "other" }, "c"),
    img(
      {
        url: "/d.jpg",
        lfwAltReason: "other",
        lfwAltNote: "Signature of the mayor, transcribed below",
      },
      "d"
    ),
    img({ url: "/e.jpg", lfwAltReason: "made-up" }, "e"),
    img({ url: "/f.jpg", lfwAltReason: "other", lfwAltNote: "<b></b>  " }, "f"),
  ]);
  assert.deepEqual(
    R.issues(items).map((i) => i.clientId),
    ["c", "e", "f"]
  );
});

test("a reason recorded in the Media Library passes; library alt text alone does not", () => {
  const items = R.collect([
    img({ url: "/a.jpg", id: 5 }, "a"),
    img({ url: "/b.jpg", id: 6 }, "b"),
    img({ url: "/c.jpg", id: 7 }, "c"),
  ]);
  const lib = { 5: { status: "decorative" }, 6: { status: "present" }, 7: { status: "missing" } };
  assert.deepEqual(
    R.issues(items, (id) => lib[id] || null).map((i) => i.clientId),
    ["b", "c"]
  );
});

test("gallery images, cover backgrounds and media-text images are found", () => {
  const blocks = [
    {
      name: "core/gallery",
      clientId: "g",
      attributes: {},
      innerBlocks: [img({ url: "/1.jpg" }, "g1"), img({ url: "/2.jpg", alt: "Two" }, "g2")],
    },
    {
      name: "core/cover",
      clientId: "cv",
      attributes: { url: "/bg.jpg", alt: "" },
      innerBlocks: [img({ url: "/in.jpg" }, "inner")],
    },
    {
      name: "core/cover",
      clientId: "video",
      attributes: { url: "/v.mp4", backgroundType: "video" },
      innerBlocks: [],
    },
    {
      name: "core/cover",
      clientId: "feat",
      attributes: { useFeaturedImage: true, url: "/f.jpg" },
      innerBlocks: [],
    },
    {
      name: "core/media-text",
      clientId: "mt",
      attributes: { mediaType: "image", mediaUrl: "/m.jpg", mediaAlt: "" },
      innerBlocks: [],
    },
  ];
  const items = R.collect(blocks);
  assert.deepEqual(
    items.map((i) => i.clientId),
    ["g1", "g2", "cv", "inner", "mt"]
  );
  assert.equal(items[0].parent, "core/gallery");
  assert.equal(items.find((i) => i.clientId === "mt").altKey, "mediaAlt");
  assert.deepEqual(
    R.issues(items).map((i) => i.clientId),
    ["g1", "cv", "inner", "mt"]
  );
});

test("images in Classic and Custom HTML blocks are found and cannot be marked from the panel", () => {
  const html =
    '<p>x</p><img src="/a.jpg" class="alignleft wp-image-12" alt=""><img src=\'/b.jpg\' alt=\'Bridge\'><img src=/c.jpg>';
  const items = R.collect([
    { name: "core/freeform", clientId: "f", attributes: { content: html }, innerBlocks: [] },
  ]);
  assert.equal(items.length, 3);
  assert.deepEqual(
    items.map((i) => i.alt),
    ["", "Bridge", null]
  );
  assert.equal(items[0].id, 12);
  assert.equal(items[0].canMark, false);
  assert.equal(R.issues(items).length, 2);
  assert.equal(R.issues(items, (id) => (id === 12 ? { status: "decorative" } : null)).length, 1);
});

test("the lock: enforce + issues + about to publish or already live", () => {
  const base = {
    mode: "enforce",
    issueCount: 2,
    canOverride: false,
    override: false,
    publishPanelOpen: true,
    status: "draft",
  };
  assert.equal(R.shouldLock(base), true);
  assert.equal(R.shouldLock({ ...base, issueCount: 0 }), false, "nothing to fix");
  assert.equal(R.shouldLock({ ...base, mode: "warn" }), false, "warn only");
  assert.equal(R.shouldLock({ ...base, mode: "off" }), false, "off");
  assert.equal(R.shouldLock({ ...base, publishPanelOpen: false }), false, "drafts keep saving");
  assert.equal(
    R.shouldLock({ ...base, publishPanelOpen: false, status: "publish" }),
    true,
    "Update on a live post"
  );
  assert.equal(
    R.shouldLock({ ...base, publishPanelOpen: false, status: "future" }),
    true,
    "scheduled"
  );
  assert.equal(
    R.shouldLock({ ...base, override: true }),
    true,
    "override ignored without the role"
  );
  assert.equal(R.shouldLock({ ...base, canOverride: true }), true, "role alone does not unlock");
  assert.equal(
    R.shouldLock({ ...base, canOverride: true, override: true }),
    false,
    "role and choice unlock"
  );
});

test("marking decorative unlocks: the item passes once the reason is set", () => {
  const before = R.collect([img({ url: "/a.jpg", alt: "" })]);
  assert.equal(
    R.shouldLock({
      mode: "enforce",
      issueCount: R.issues(before).length,
      publishPanelOpen: true,
      status: "draft",
    }),
    true
  );
  const after = R.collect([img({ url: "/a.jpg", alt: "", lfwAltReason: "decorative" })]);
  assert.equal(
    R.shouldLock({
      mode: "enforce",
      issueCount: R.issues(after).length,
      publishPanelOpen: true,
      status: "draft",
    }),
    false
  );
});

test("note cleaning strips tags, collapses space and caps length", () => {
  assert.equal(R.cleanNote("<script>alert(1)</script>  hi \n there"), "alert(1) hi there");
  assert.equal(R.cleanNote("x".repeat(500)).length, R.NOTE_MAX);
  assert.equal(R.cleanNote(42), "");
});
