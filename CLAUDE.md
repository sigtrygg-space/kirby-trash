# CLAUDE.md

kirby-trash is a trash can plugin for Kirby CMS 6 (1.x on `main`; the
0.7 line for Kirby 5 lives on `kirby-5`): pages and files are
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
- `tests/panel.test.mjs` — renders the committed `index.js` with Vue 3
  and fails on Vue warnings (`npm test`, after `npm run build`)
- `translations/en.json`, `de.json` — UI keys under
  `sigtrygg-space.kirby-trash.*`, error keys under
  `error.sigtrygg-space.kirby-trash.*`

## Non-negotiables

- **Verify Kirby internals before building on them** — against
  `vendor/getkirby/cms/src/**` and the panel dist
  (`vendor/getkirby/cms/panel/dist/js/index.min.js`). Every UI bug in
  this project's history came from an unverified assumption.
- **Never use Vue `template:` strings in panel code.** Sites disable
  the Vue template compiler per Kirby's security recommendation;
  runtime-only Vue renders such components silently as nothing.
  Components are Vue 3 SFCs precompiled with kirbyup 4 (Node.js 24+):
  `npm install && npm run build` after changing `src/` panel files —
  CI fails if the committed build is stale. The bundle imports Vue
  from `"vue"` (Kirby 6's import map); there is no `window.Vue`.
- **Vue 3 rules:** declare every emitted event in `emits` (undeclared
  ones become native listeners on the root element); `$listeners`
  and `$set` no longer exist. Attribute bindings fall back to `null`,
  never `false` — Vue 3 renders `false` as the string "false", so
  `[data-*]` selectors match (1.0.1's grey pills). No HTML comments
  at a template's root: they ship in the build and turn the component
  into a fragment. Table cells set `inheritAttrs: false` (k-table
  passes every cell `disabled` and an `input` listener).
- Kirby core code style in PHP: tabs, aligned arrays, Kirby's
  `key:`/`data:`/`fallback:` exception convention.

## Hard-won Kirby facts (verified against 6.0; 5.5 where noted)

- Panel dialogs: define them in the area's `dialogs` key
  (pattern/load/submit). Submitting via the pipeline gives loading
  spinner, disabled buttons, success notification (`message`) and
  automatic view reload. Open by path: `dialog: "trash/…"` on buttons
  and dropdown options (k-button handles `dialog` natively).
- Custom dialog components declare a `visible` prop and forward it
  to `k-dialog`. Under Kirby 5 (Vue 2) attr fallthrough does not, and
  the dialog portal stays empty (blank overlay, no error); keep the
  pattern so both lines share the component.
- Dropdown options use the `click` key (string). Kirby 6 reads
  `option ?? click`, Kirby 5 only `click`.
- Trusted HTML: Kirby 6 escapes plain strings where it renders them
  (`text` of `k-text-dialog` / `k-remove-dialog` / `k-form-dialog`,
  `k-box`, `k-text`, field `help`). Pass plain strings — escaping
  them again (`Escape::html`) shows `&amp;` in the Panel. Only HTML
  that is meant as HTML goes in as `Kirby\Toolkit\HtmlString`.
- Panel backend: `$kirby->panel()` is an object (`->router()->call()`,
  `->areas()`); `Areas::area($id, $definition)` returns an `Area`,
  `Menu::item($id)` a `Ui\Button`. `menu`/`when` closures receive
  objects.
- The area `menu` key may return an array of menu button props
  (`badge` etc.). **Kirby 6.0.0 drops the `badge`** in
  `Menu::item()`; fixed upstream for 6.0.1 (the frontend renders it
  fine). Tests detect this via `kirbyRendersMenuBadges()` and skip
  `testKirbyRendersTheMenuBadge` — not a plugin bug. The badge is
  computed on EVERY panel request — it must never throw and must stay
  cheap (dir listing; expiry stats cached, keyed on trash root mtime
  + item count, flushed by `flushIndex()`).
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
- Content storage (`contentFile()`, `contentDirectory()` incl.
  `_changes`) is identical in Kirby 5 and 6 — trash entries carry
  over between the lines.
- Deleted models: read UUIDs non-generatively; generate only for the
  surviving parent (`uuidOf($parent, generate: true)`).
- Since Kirby 5.6.4 (and in 6) every Panel route except views (dialogs, drawers,
  dropdowns, searches, request routes) requires the Panel's CSRF
  token, also for GETs. `Request::csrf()` reads the `X-CSRF` header or
  a `csrf` query parameter — resources loaded via `<img src>` (the
  preview route) must carry it in the URL (`previewUrl()`).

## Testing gotchas

- `App::destroy()` wipes the static plugin registry → setUp
  re-requires `index.php` conditionally.
- `$kirby->clone()` drops impersonation → use the `fresh()` helper.
- Manual meta.json edits bypass the expiry cache (`flushIndex()` or
  touch the trash root).
- Permission-based tests skip as superuser (`posix_geteuid() === 0`)
  and run on CI.
- Kirby 6 serves Vue's production build even with `debug => true`,
  which swallows Vue warnings; the dev build only comes when a plugin
  has a non-empty `index.dev.js` (`Panel\Plugins::isDev()`). "No Vue
  warnings in the console" proves nothing without it — `npm test`
  renders with Vue's dev build and fails on any warning.
- `getkirby/starterkit` still pulls Kirby 5 unless Kirby 6 is
  requested explicitly (`getkirby/cms:^6.0`) — check the version of
  any demo used for verification.
- Menu badges are invisible on Kirby 6.0.0 (see above): verify them
  on `develop-patch` or 6.0.1+.
- Deprecations only surface with `debug` on (or `KIRBY_TESTING`);
  tests that guard against deprecated APIs collect `E_USER_DEPRECATED`
  with `debug => true` (see the role blueprint test).
- Impersonated tests never hit Kirby's session-only checks (the
  CSRF check above). Anything routed through the Panel needs a test
  with a real `loginPasswordless()` session through
  `$kirby->panel()->router()`
  (see the preview router test). The `app()` helper predefines the
  CSRF token so ordinary tests open no file sessions: Kirby mirrors
  the session cookie into `$_COOKIE`, and a second App instance in
  the same process would block on that session's lock.

## Release process (automated)

Bump `version` in composer.json AND date the CHANGELOG section in one
PR; merging lets `.github/workflows/release.yml` create the matching
tag and GitHub release (notes = that CHANGELOG section). Two lines:
`main` releases 1.x (Kirby 6, marked latest), `kirby-5` releases 0.x
(Kirby 5, pre-release, never latest); semver pre-releases
(`1.1.0-rc.1`) are pre-releases and never latest on either line; a
version on the wrong branch fails the release. `.github/scripts/test-release-workflow.sh` exercises
the workflow in CI. Packagist syncs via hook and SKIPS tags that don't
match the version field. Never move a published tag.

Kirby 5 fixes go to `kirby-5` in their own PR (Vue 2, kirbyup 3,
Node 22, PHP 8.2+); port them to `main` when they apply there too.

## Collaboration

- Development on a `claude/…` work branch, one open PR at a time; the
  maintainer merges (release PRs may be merged by Claude when asked).
- A local Claude instance ("auditor") verifies Panel changes visually
  in a Starterkit demo and produces the README screenshots
  (`.github/screenshot*.png`, ~1300px, light mode, English Panel).
- Backlog lives in GitHub issues; capture ideas there, not in chat.
