# CLAUDE.md

kirby-trash is a trash can plugin for Kirby CMS 5+: pages and files are
soft-deleted via `page.delete:before` / `file.delete:before` hooks (a
failing safety copy blocks the deletion) and managed in a Panel area.
Maintainer: Matthias Schmidt (@sigtrygg-space). Chat with the
maintainer is German; code, comments, commits, issues and docs are
English (international Kirby community).

## Map

- `index.php` — plugin registration: options, permissions,
  translations, hooks, the Panel area incl. backend-defined dialogs
  (`dialogs` key), CLI command `trash:cleanup`
- `src/Trash.php` — all core logic (singleton per App instance)
- `src/index.js`, `src/components/*.vue`, `src/styles.css` — Panel
  sources, built with kirbyup into the committed `index.js`/`index.css`
- `tests/TrashTest.php` — PHPUnit suite (`composer test`)
- `translations/en.json`, `de.json` — UI keys under
  `sigtrygg-space.kirby-trash.*`, error keys under
  `error.sigtrygg-space.kirby-trash.*`

## Non-negotiables

- **Verify Kirby internals before building on them** — against
  `vendor/getkirby/cms/src/**` and the panel dist
  (`vendor/getkirby/cms/panel/dist/js/index.min.js`). Every UI bug in
  this project's history came from an unverified assumption.
- **Never use Vue `template:` strings in panel code.** Sites disable
  the Vue template compiler per Kirby's security recommendation
  (deprecated in Kirby 6); runtime-only Vue renders such components
  silently as nothing. Components are SFCs precompiled with kirbyup:
  `npm install && npm run build` after changing `src/` panel files —
  CI fails if the committed build is stale.
- Kirby core code style in PHP: tabs, aligned arrays, Kirby's
  `key:`/`data:`/`fallback:` exception convention.

## Hard-won Kirby facts (all verified against 5.5)

- Panel dialogs: define them in the area's `dialogs` key
  (pattern/load/submit). Submitting via the pipeline gives loading
  spinner, disabled buttons, success notification (`message`) and
  automatic view reload. Open by path: `dialog: "trash/…"` on buttons
  and dropdown options (k-button handles `dialog` natively).
- Custom dialog components MUST declare a `visible` prop and forward
  it to `k-dialog` — Vue 2 attr fallthrough does not, and the dialog
  portal stays empty (blank overlay, no error).
- Dropdown options use the `click` key (string) in released Kirby;
  the `option` key is unreleased-main-only.
- The area `menu` key may return an array that `Panel\Menu` spreads
  into the menu button props (`badge` etc.); `array_filter` drops
  null values. The badge is computed on EVERY panel request — it must
  never throw and must stay cheap (dir listing; expiry stats cached,
  keyed on trash root mtime + item count, flushed by `flushIndex()`).
- `k-table` resolves a column's `type` to a global
  `k-table-<type>-cell` component; plugin components qualify.
- Kirby has NO `storage` root; `site/storage` is convention. Custom
  folder setups set the plugin's `root` option — `rootIssue()` powers
  the warning box when the root is unusable.
- Kirby resolves the plugin cache option
  (`sigtrygg-space.kirby-trash.cache => false` → no-op NullCache)
  itself inside `$kirby->cache()`.
- Hooks re-enter: nested deletions are guarded via normalized roots
  (`\` → `/`, Windows mixes separators within one request).
- Deleted models: read UUIDs non-generatively; generate only for the
  surviving parent (`uuidOf($parent, generate: true)`).

## Testing gotchas

- `App::destroy()` wipes the static plugin registry → setUp
  re-requires `index.php` conditionally.
- `$kirby->clone()` drops impersonation → use the `fresh()` helper.
- Manual meta.json edits bypass the expiry cache (`flushIndex()` or
  touch the trash root).
- Permission-based tests skip as superuser (`posix_geteuid() === 0`)
  and run on CI.

## Release process (automated)

Bump `version` in composer.json AND date the CHANGELOG section in one
PR; merging to main lets `.github/workflows/release.yml` create the
matching tag and GitHub release (notes = that CHANGELOG section,
pre-release flag while 0.x). Packagist syncs via hook and SKIPS tags
that don't match the version field. Never move a published tag.

## Collaboration

- Development on a `claude/…` work branch, one open PR at a time; the
  maintainer merges (release PRs may be merged by Claude when asked).
- A local Claude instance ("auditor") verifies Panel changes visually
  in a Starterkit demo and produces the README screenshots
  (`.github/screenshot*.png`, ~1300px, light mode, English Panel).
- Backlog lives in GitHub issues; capture ideas there, not in chat.
