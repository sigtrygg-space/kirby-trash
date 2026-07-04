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
