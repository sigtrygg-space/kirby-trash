# Changelog

## 0.1.0 (unreleased)

Initial release.

- Soft-delete for pages and files via `page.delete:before` /
  `file.delete:before` hooks
- Failing trash copies block the actual deletion (safety net)
- Panel area with restore, permanent delete and "empty trash"
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
