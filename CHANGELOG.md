# Changelog

## 0.2.2 (2026-07-08)

- The root warning replaces the trash list entirely — no more
  "The trash is empty" directly below the error box, which was
  misleading anyway (the trash is unreadable, not empty)

## 0.2.1 (2026-07-08)

- The Panel area shows a clear warning when the configured trash root
  is not readable or cannot be created (typically in custom folder
  setups where the storage location differs) instead of appearing
  silently empty
- The menu badge can no longer break the Panel: filesystem errors
  while counting degrade to "no badge", and unreadable roots are
  listed as empty
- The Panel components are precompiled with kirbyup: the plugin no
  longer needs the Vue template compiler, which sites disable
  following Kirby's security recommendation (previously the trash
  area rendered as a blank page on such sites) and which is
  deprecated in Kirby 6

## 0.2.0 (2026-07-08)

- The Panel menu entry shows the number of trashed items as a badge —
  configurable via the new `badge` option: `false` disables it, an
  array restyles it (e.g. `['theme' => 'passive']` for a more subtle
  look)
- Items that expire soon are highlighted in the table and switch the
  badge to the warn theme — a last chance to restore before the
  automatic cleanup removes them. Configurable via the new `warnDays`
  (default 5, `0` disables) and `warnTheme` (default `orange`)
  options; the expiry lookup is cached (new plugin `cache`, keyed on
  the trash root's mtime and item count)
- Already expired items neither warn nor count: the badge shows only
  what the next cleanup will keep, so it always matches what opening
  the area reveals

## 0.1.2 (2026-07-07)

- composer.json carries the plugin version (shown in the Panel for
  manual and submodule installs), a real author entry and support
  links for the Packagist page
- Releases are automated: a workflow tags and publishes when the
  version in composer.json changes on main

## 0.1.1 (2026-07-07)

- The Composer dist package no longer ships development files
  (`.github` with workflows and screenshots, `tests`, PHPUnit config) —
  installs contain only the plugin itself

## 0.1.0 (2026-07-06)

Initial release.

- Soft-delete for pages and files via `page.delete:before` /
  `file.delete:before` hooks
- Failing trash copies block the actual deletion (safety net)
- Panel area with restore, permanent delete and "empty trash"; items are
  listed as a table with original path, size, deletion date and time left,
  and a details dialog shows all metadata plus the restore / delete
  actions (also on small screens, where the table is reduced to the most
  important columns)
- All dialogs are defined in the backend and run through the Panel's
  dialog pipeline: while restore / delete / empty is running, the submit
  button is disabled and shows a loading spinner
- `enabled` and `root` options accept closures for logic-driven switching;
  a disabled trash also hides its Panel area and refuses its dialogs
- Automatic cleanup with configurable retention (`retentionDays`,
  default 30, `-1` = keep forever)
- `kirby trash:cleanup` CLI command for cronjobs
- Permissions (`access`, `restore`, `delete`), admin-only by default
- English and German translations

Fixed during the pre-release test round:

- Panel: the restore / delete entries in the item options dropdown did
  nothing on Kirby 5.5 — dropdown options have to use the `click` key,
  the `option` key is only supported by unreleased Kirby versions
- Windows: the guard against nested delete hooks compared roots with
  mixed path separators literally, so deleting a page with children
  created one trash entry per descendant instead of a single entry
- Panel: the details dialog opened as an empty overlay — custom dialog
  components have to declare the `visible` prop and forward it to
  `k-dialog`, Vue 2 attribute fallthrough does not
