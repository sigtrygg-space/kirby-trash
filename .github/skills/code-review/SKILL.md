---
name: code-review
description: Review guidance for kirby-trash pull requests — project invariants, verified Kirby facts, known non-bugs, and the checks that matter most in this codebase. Use when reviewing any PR in this repository.
---

# Reviewing kirby-trash

kirby-trash is a trash can plugin for Kirby CMS 6 (`main`, 1.x; the
Kirby 5 line lives on `kirby-5`, 0.x): pages and files are
soft-deleted via `page.delete:before` / `file.delete:before` hooks (a
failing safety copy blocks the deletion) and managed in a Panel area.
All core logic lives in `src/Trash.php`; `index.php` registers options,
hooks, the Panel area with backend-defined dialogs, and a CLI command.

## Known non-bugs — do not flag these

- **Plugin cache option resolution:** `sigtrygg-space.kirby-trash.cache
  => false` is resolved by Kirby itself inside `$kirby->cache()` to a
  no-op NullCache. Code that uses the cache without guarding against
  "cache disabled" is correct by design.
- **`previews` option semantics:** only an explicit `false` disables
  previews. Numbers and CSS length strings are row-height values and
  keep previews enabled — that overloading is deliberate.
- **`keepUntil` meta field:** carries a date string *or* `true`
  (= keep indefinitely; the automatic cleanup and "empty trash" spare
  such items, only their own delete action removes them). Type checks
  against both shapes are intentional.
- **Committed `index.js` / `index.css`:** build artifacts, precompiled
  from `src/` with kirbyup. Do not review their diffs; review the
  sources instead — `src/index.js` (the entrypoint registering
  components), `src/components/` and `src/styles.css`. CI fails when
  the committed build is stale.
- **Missing menu badge on Kirby 6.0.0:** Kirby 6.0.0 drops the
  `badge` of Panel menu entries (fixed upstream for 6.0.1). The
  skipped `testKirbyRendersTheMenuBadge` and the `menuBadge()` test
  helper account for it; do not flag the skip.
- **Dialog texts without `Escape::html`:** Kirby 6 escapes plain
  strings where it renders them; escaping them in PHP too would show
  `&amp;` in the Panel. Plain strings in dialog `text` are correct.
- **Badge theme `passive` by default:** deliberate since 1.0.1 —
  Kirby renders `notice` in exactly the warn default's orange, so
  the warn state was invisible. Grey → orange → red is the intended
  escalation.
- **`composer.json` `version` field:** intentional despite Composer's
  warning — the Panel shows it for manual and submodule installs, and
  the release workflow keys off it. Never suggest removing it.

## Project invariants — flag any violation

- **The Panel area closure runs on EVERY Panel request, BEFORE
  Kirby's firewall.** Anything inside it (especially the menu badge
  path) must be side-effect-free, cheap, and must never throw. Watch
  for new code that mutates state or does per-request filesystem work
  there.
- **No `catch (Throwable)` around Kirby API calls.** Degrading paths
  catch `Exception` and type-guard meta values first; a Throwable
  catch hid a Kirby 6 signature change (`Uuid::for()` with a string)
  behind a silent fallback in 1.0.0/1.0.1. Flag new Throwable catches
  outside filesystem access and the badge path.
- **Corrupt `meta.json` must degrade, never crash.** Meta fields are
  user-editable files on disk: every new read of a meta value needs
  type guards like `metaTime()`'s `is_string()` check, and per-entry
  work needs a try/catch like the `Data::read()` handling in
  `items()`, so one broken entry cannot 500 the whole listing. This
  is a tested, first-class scenario.
- **Cleanup on failure:** operations that write files (`trashPage()`,
  `trashFile()`, preview generation) remove their partial output in a
  catch block before rethrowing. New write paths need the same
  pattern — a leftover partial file gets served or restored later.
- **Preview endpoint security:** source formats are decided by content
  sniffing (`Mime::type`), never by file extension; SVG is deliberately
  excluded from streaming; every filename from metadata is validated
  against its item's `data/` folder (`basename` check); everything is
  gated by `ensure('access')` / `ensure('restore')`. Weakening any of
  these is a security regression.
- **Panel routes other than views need the CSRF token** (Kirby
  5.6.4+), also for GETs. Anything the browser loads without
  `fetch()` — like the preview thumbnails via `<img src>` — must carry
  it as the `csrf` query parameter. A new endpoint without it, or a
  test that only exercises it impersonated, misses this.
- **Values that end up in style attributes** must be strictly
  validated server-side (see `rowHeight()`: only plain positive
  px/rem/em lengths pass). Text goes to the Panel as plain strings,
  which Kirby 6 escapes; flag any `HtmlString` that wraps user or
  meta data (titles, paths, user names).
- **Vue 3 components:** every emitted event is declared in `emits`;
  no `$listeners`, `$set` or `template:` strings. Attribute bindings
  fall back to `null`, never `false` (Vue 3 renders "false", and
  `[data-*]` selectors then match); no HTML comment at a template's
  root (it ships and makes the component a fragment).
- **Release lines:** a `version` bump on `main` must be 1.x or later,
  on `kirby-5` 0.x — the release workflow refuses anything else.

## Kirby facts this project verified the hard way

- Custom dialog components must declare a `visible` prop and forward
  it to `k-dialog` — attribute fallthrough does not work.
- Dropdown options use the `click` / `dialog` keys; Kirby 6 also
  reads `option`, Kirby 5 does not.
- `F::type()` treats any 2–4 character input as a literal extension —
  pass `F::extension()` output, never a short filename.
- Kirby's date field serializes an *edited* value as `Y-m-d 00:00:00`,
  an untouched prefill as `Y-m-d` — code handling submitted dates must
  cover both.
- Hooks re-enter on nested deletions; root paths are compared after
  normalizing `\` to `/` (Windows mixes separators in one request).

## Conventions

- PHP follows Kirby core style: tabs, aligned array arrows, exceptions
  via `key:` / `fallback:` named arguments, explicit `=== true` /
  `=== false` comparisons.
- Translations: `translations/en.json` and `de.json` must stay in
  parity — a new key in one language needs its counterpart. Count-based
  texts use `one`/`many` key pairs or count-indexed arrays.
- Tests: environment-dependent cases (POSIX permissions, symlinks, GD)
  live in their own guarded test methods so portable assertions keep
  counting everywhere. `App::destroy()` wipes the plugin registry, so
  setUp re-requires `index.php`.
- CHANGELOG: one section per version; it becomes the release notes
  verbatim. Fixes to unreleased features do not get their own bullet.
