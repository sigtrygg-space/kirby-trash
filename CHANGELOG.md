# Changelog

## 0.4.0 (unreleased)

- Image previews in the trash list: trashed image files show a
  thumbnail instead of a generic row, trashed pages show their
  first image as a cover, and the details dialog shows a large
  preview of both. Thumbnails are generated lazily as JPEG (works
  on any server-side GD build, whatever the source format), cached
  below the cache root, streamed through a Panel route behind the
  `access` permission and removed together with their item. The
  source format is detected by content sniffing, never by file
  extension; SVG is deliberately excluded from streaming. All
  other items show the same type-based icons and colors as Kirby's
  own file panels. Disable via the new `previews` option

## 0.3.1 (2026-07-26)

- The red "cleanup required" badge no longer survives the click it
  invites: Kirby builds the Panel areas before it calls the route
  action but resolves the menu entries afterwards, so the badge is
  computed lazily now and the response that just ran the cleanup
  carries the updated badge instead of the pre-cleanup count
- Entries whose meta.json is missing or unreadable — what an
  interrupted deletion leaves behind — can be removed from the Panel
  again: they never appear as table rows, so the empty-trash button
  is gated on everything the trash root holds rather than on the
  listed items. The confirmation dialog counts and measures the same
  way and no longer offers to free "0 B" while such an entry sits on
  disk
- The area no longer claims the trash is empty while entries it
  cannot list occupy disk space — a note above the list says how
  many there are, why they are missing and that emptying the trash
  removes them. It also covers the partial case, where the table
  used to show fewer rows than the badge counted without explaining
  the difference
- An unreadable trash root no longer breaks the empty-trash dialog.
  The trash is measured one entry at a time, so an unreadable root
  reports 0 with the root warning explaining it, and an unreadable
  folder inside a single entry costs only that entry's bytes
  instead of zeroing the whole total

## 0.3.0 (2026-07-08)

- Expired items no longer linger invisibly until someone opens the
  trash area: when only expired items remain, the menu badge no
  longer disappears but turns red, showing their number as a
  "cleanup required" call to action. Opening the area removes them
  (as before) and now reports what was just cleaned up — no more
  "invisible gigabyte" on sites where nobody visits the trash; the
  CLI command remains the guaranteed path for unvisited sites
- Postpone deletion: every item can be kept for another retention
  cycle via its options menu or the details dialog — the natural
  action to the warn color's "last chance" signal. Implemented as a
  `keepUntil` meta field, so the "Deleted" column keeps telling the
  truth; gated by the `restore` permission and hidden when retention
  is disabled
- The empty-trash header button is additionally gated on the root
  warning (defensive only — the warning state already implies an
  empty item list)
- rootIssue() also flags a root that exists as a file or dangling
  symlink, or whose path is blocked by one in an intermediate
  segment — previously these misconfigurations slipped through as a
  silent empty area

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
