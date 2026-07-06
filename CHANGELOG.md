# Changelog

## 0.1.0 (unreleased)

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
  a disabled trash also hides its Panel area and refuses its API
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
