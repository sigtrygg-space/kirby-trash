// Renders the committed Panel bundle with Vue 3, the way Kirby 6 runs
// it. PHPUnit never sees the Vue side, so Vue 2 → 3 rendering changes
// (like `false` attribute values no longer being dropped) need their
// own net. Run with `npm test` after `npm run build`.

import assert from "node:assert/strict";
import { test } from "node:test";
import { createSSRApp, h } from "vue";
import { renderToString } from "vue/server-renderer";

// the bundle registers its components via Kirby's global `panel`
let plugin;
globalThis.panel = { plugin: (name, definition) => (plugin = definition) };
await import("../index.js");

// renders through an app with Vue's dev build (Node's default), so
// Vue warnings surface here — the Panel ships Vue's production build,
// which swallows them, unless a plugin has a non-empty index.dev.js
const cell = async (row, attrs = {}) => {
  const warnings = [];
  const app = createSSRApp({
    render: () =>
      h(plugin.components["k-table-remaining-cell"], {
        column: { warnTheme: "orange" },
        row,
        value: "12 days",
        ...attrs
      })
  });
  app.config.warnHandler = (message) => warnings.push(message);

  const html = await renderToString(app);
  assert.deepEqual(warnings, [], "Vue warnings");

  return html;
};

test("registers all components", () => {
  assert.deepEqual(Object.keys(plugin.components).sort(), [
    "k-table-remaining-cell",
    "k-trash-details-dialog",
    "k-trash-view"
  ]);
});

test("a regular time-left cell carries no theme", async () => {
  const html = await cell({ expiresSoon: false });

  assert.match(html, /12 days/);
  assert.doesNotMatch(html, /data-theme/);
});

test("an expiring time-left cell carries the warn theme", async () => {
  const html = await cell({ expiresSoon: true });

  assert.match(html, /data-theme="orange"/);
});

test("props k-table passes to every cell stay off the DOM", async () => {
  // k-table-cell passes these to any cell component
  const html = await cell(
    { expiresSoon: false },
    { disabled: false, field: {}, onInput: () => {} }
  );

  assert.doesNotMatch(html, /disabled/);
});
