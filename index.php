<?php

use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Cms\Page;
use Kirby\Data\Data;
use Kirby\Exception\NotFoundException;
use Kirby\Filesystem\F;
use Kirby\Toolkit\Escape;
use Kirby\Toolkit\I18n;
use SigtryggSpace\KirbyTrash\Trash;

load([
	'sigtryggspace\\kirbytrash\\trash' => 'src/Trash.php',
], __DIR__);

App::plugin('sigtrygg-space/kirby-trash', [
	'options' => [
		'enabled'       => true,
		'retentionDays' => Trash::DEFAULT_RETENTION_DAYS,
		'root'          => null,
		'badge'         => true,
		// image previews in the trash list; thumbnails are
		// generated lazily below the cache root. false disables
		// them; a number (px) or CSS length string like '3.5rem'
		// additionally scales the table rows and previews
		'previews'      => true,
		'warnDays'      => 5,
		'warnTheme'     => 'orange',
		// caches the next-expiry lookup the menu badge needs
		// on every Panel request
		'cache'         => true,
	],

	// default: no access for non-admin roles; admins are always
	// allowed (see Trash::can()). Other roles can be allowed via
	// the role blueprint.
	'permissions' => [
		'access'  => false,
		'restore' => false,
		'delete'  => false,
	],

	'translations' => [
		'en' => Data::read(__DIR__ . '/translations/en.json', 'json'),
		'de' => Data::read(__DIR__ . '/translations/de.json', 'json'),
	],

	'hooks' => [
		// the page still exists on disk here; a failing copy throws
		// and thereby blocks the actual deletion (safety net).
		// trashPage() also guards the root, so the nested hooks of
		// the page's own files and children don't create own items
		'page.delete:before' => function (Page $page) {
			$trash = Trash::instance();

			if ($trash->enabled() === false || $trash->covers($page->root()) === true) {
				return;
			}

			$trash->trashPage($page);
		},
		'page.delete:after' => function (Page $page) {
			Trash::instance()->release($page->root());
		},
		'file.delete:before' => function (File $file) {
			$trash = Trash::instance();

			if ($trash->enabled() === false || $trash->covers($file->root()) === true) {
				return;
			}

			$trash->trashFile($file);
		},
	],

	'areas' => [
		'trash' => function (App $kirby) {
			$trash = Trash::instance();

			return [
				'label' => I18n::translate('sigtrygg-space.kirby-trash.title', 'Trash'),
				'icon'  => 'trash',
				// an array is spread into the menu button props by
				// Kirby's Panel\Menu; a null badge is filtered out.
				// It has to stay a closure: areas are built before the
				// route action runs, so an eagerly computed badge would
				// still show the pre-cleanup count in the very response
				// whose view action just ran the cleanup. Panel\Menu
				// resolves the closure afterwards, per request.
				'menu'  => $trash->enabled() === true && $trash->can('access')
					? fn () => ['badge' => Trash::instance()->badge()]
					: false,
				'link'  => 'trash',
				'views' => [
					[
						'pattern' => 'trash',
						'action'  => function () {
							$trash = Trash::instance();
							$trash->ensure('access');
							$cleaned = $trash->cleanup();

							return [
								'component' => 'k-trash-view',
								'title'     => I18n::translate('sigtrygg-space.kirby-trash.title', 'Trash'),
								'props'     => [
									'items'      => $trash->panelItems(),
									'columns'    => $trash->panelColumns(),
									'canRestore' => $trash->can('restore'),
									'canDelete'  => $trash->can('delete'),
									// number of entries in the trash, incl. the
									// ones without a readable meta.json: gates
									// the "trash is empty" claim
									'total'      => $trash->count(),
									// what "empty trash" would actually remove
									// (kept items excluded, broken entries
									// included) — gates the empty-trash button:
									// with only kept items left, emptying would
									// be a no-op and the button disappears.
									// Count-only: the dialog does the byte
									// measuring, not every view request
									'removable'  => $trash->removableCount(),
									// note about the entries behind the
									// difference between `total` and `items`
									'unlisted'   => $trash->unlistedLabel(),
									// null when retention is disabled —
									// then there is nothing to postpone
									'postponeLabel' => $trash->postponeLabel(),
									// warning shown when the configured
									// root is unreadable or uncreatable
									'issue'      => $trash->rootIssue(),
									// custom table row height (scales the
									// previews), null = Kirby standard
									'rowHeight'  => $trash->rowHeight(),
									// explains why the red "cleanup
									// required" badge led to fewer items
									'cleaned'    => $cleaned > 0
										? I18n::template(
											'sigtrygg-space.kirby-trash.cleaned.' . ($cleaned === 1 ? 'one' : 'many'),
											null,
											['count' => $cleaned]
										)
										: null,
								],
							];
						},
					],
				],

				// area request routes run through the Panel router like
				// dialogs: the firewall authenticates before the action,
				// and returned Response objects pass through verbatim —
				// which is what lets this endpoint stream binary images
				'requests' => [
					[
						'pattern' => 'trash/preview/(:any)',
						'action'  => function (string $id) {
							$trash = Trash::instance();
							$trash->ensure('access');

							return $trash->preview($id);
						},
					],
				],

				// backend-defined dialogs: submitting runs through the
				// Panel's dialog pipeline, which disables the submit
				// button, shows a loading spinner and reloads the view
				'dialogs' => [
					'trash.details' => [
						'pattern' => 'trash/(:any)/details',
						'load' => function (string $id) {
							$trash = Trash::instance();
							$trash->ensure('access');

							$columns = $trash->panelColumns();
							$row     = $trash->panelItem($id);
							$fields  = [];

							// one displayable row value per field,
							// labelled by the table column; fields
							// without a column (deletedBy) use the
							// plugin key of the same name
							foreach ($row as $key => $value) {
								if ($key === 'trashId' || is_string($value) === false || $value === '') {
									continue;
								}

								$fields[] = [
									'label' => $columns[$key]['label']
										?? I18n::translate('sigtrygg-space.kirby-trash.' . $key),
									'value' => $value,
								];
							}

							return [
								'component' => 'k-trash-details-dialog',
								'props' => [
									'fields'     => $fields,
									'trashId'    => $id,
									// the row's panel image object; the
									// dialog shows a large preview when
									// it carries a src
									'image'      => $row['image'],
									// native aspect ratio of the preview
									// image, so the dialog frame matches
									// the format instead of cropping
									'ratio'      => $trash->previewRatio($id),
									'canRestore' => $trash->can('restore'),
									'canDelete'  => $trash->can('delete'),
									'postponeLabel' => $row['postponable'] === true
										? $trash->postponeLabel()
										: null,
								],
							];
						},
						// read-only: the close button closes client-side,
						// no submit handler needed
					],
					'trash.postpone' => [
						'pattern' => 'trash/(:any)/postpone',
						'load' => function (string $id) {
							$trash = Trash::instance();
							$trash->ensure('restore');

							$row = $trash->panelItem($id);

							// retention disabled or no deletion date to
							// postpone from — the UI never offers this,
							// refuse crafted requests as a backstop
							if ($row['postponable'] !== true) {
								throw new NotFoundException(
									key: 'sigtrygg-space.kirby-trash.notFound',
									fallback: 'The trash item could not be found'
								);
							}

							$days = $trash->retentionDays();
							$meta = $trash->item($id);

							return [
								'component' => 'k-form-dialog',
								'props' => [
									'fields' => [
										'forever' => [
											'type'  => 'toggle',
											'label' => I18n::translate('sigtrygg-space.kirby-trash.postpone.forever'),
											// only the automatic cleanup spares the
											// item; delete and empty-trash still work
											'help'  => I18n::translate('sigtrygg-space.kirby-trash.postpone.forever.help'),
										],
										'until' => [
											'type'     => 'date',
											'time'     => false,
											'label'    => I18n::translate('sigtrygg-space.kirby-trash.postpone.until'),
											'min'      => date('Y-m-d', time() + 86400),
											'required' => true,
											// hidden while "keep indefinitely" is on
											'when'     => ['forever' => false],
										],
									],
									// the entry points say "Keep longer" (the
									// main intent), but the dialog can also
									// shorten or switch to indefinite — the
									// submit stays accurate with Kirby's
									// standard "Save"
									'submitButton' => [
										'icon' => 'clock',
										'text' => I18n::translate('save'),
									],
									'value' => [
										'forever' => ($meta['keepUntil'] ?? null) === true,
										// prefill: the current keepUntil date, or one
										// retention cycle from now — submitting the
										// default equals the classic postpone
										'until'   => is_string($meta['keepUntil'] ?? null)
											? date('Y-m-d', strtotime($meta['keepUntil']) ?: time() + $days * 86400)
											: date('Y-m-d', time() + $days * 86400),
									],
								],
							];
						},
						'submit' => function (string $id) {
							$trash = Trash::instance();
							$trash->ensure('restore');

							$request = App::instance()->request();
							$forever = filter_var($request->get('forever'), FILTER_VALIDATE_BOOLEAN);
							$until   = $request->get('until');

							// the date field serializes an edited value as
							// "Y-m-d 00:00:00" while an untouched prefill
							// stays "Y-m-d". The dialog means a plain day
							// either way, so reduce to the date part —
							// postpone() then keeps the item for that
							// whole day instead of expiring it at the
							// day's very start
							if (is_string($until) === true) {
								$until = substr($until, 0, 10);
							}

							$trash->postpone($id, $forever === true ? true : $until);

							return [
								'message' => I18n::translate(
									'sigtrygg-space.kirby-trash.notification.' . ($forever === true ? 'kept' : 'postponed')
								),
							];
						},
					],
					'trash.restore' => [
						'pattern' => 'trash/(:any)/restore',
						'load' => function (string $id) {
							$trash = Trash::instance();
							$trash->ensure('restore');

							return [
								'component' => 'k-text-dialog',
								'props' => [
									'text' => I18n::template('sigtrygg-space.kirby-trash.dialog.restore', null, [
										'title' => Escape::html($trash->panelItem($id)['title']),
									]),
									'submitButton' => [
										'icon' => 'undo',
										'text' => I18n::translate('sigtrygg-space.kirby-trash.restore'),
									],
								],
							];
						},
						'submit' => function (string $id) {
							$trash = Trash::instance();
							$trash->ensure('restore');
							$trash->restore($id);

							return [
								'message' => I18n::translate('sigtrygg-space.kirby-trash.notification.restored'),
							];
						},
					],
					'trash.delete' => [
						'pattern' => 'trash/(:any)/delete',
						'load' => function (string $id) {
							$trash = Trash::instance();
							$trash->ensure('delete');

							return [
								'component' => 'k-remove-dialog',
								'props' => [
									'text' => I18n::template('sigtrygg-space.kirby-trash.dialog.delete', null, [
										'title' => Escape::html($trash->panelItem($id)['title']),
									]),
								],
							];
						},
						'submit' => function (string $id) {
							$trash = Trash::instance();
							$trash->ensure('delete');
							$trash->delete($id);

							return [
								'message' => I18n::translate('sigtrygg-space.kirby-trash.notification.deleted'),
							];
						},
					],
					'trash.empty' => [
						'pattern' => 'trash/empty',
						'load' => function () {
							$trash = Trash::instance();
							$trash->ensure('delete');

							// counts what emptying actually removes —
							// broken entries without meta.json included,
							// indefinitely kept items excluded
							$stats = $trash->emptyStats();
							$key   = $stats['count'] === 1 ? 'one' : 'many';
							$text  = I18n::template('sigtrygg-space.kirby-trash.dialog.empty.' . $key, null, [
								'count' => $stats['count'],
								'size'  => F::niceSize($stats['size']),
							]);

							if ($stats['kept'] > 0) {
								$text .= ' ' . I18n::translateCount('sigtrygg-space.kirby-trash.dialog.empty.kept', $stats['kept']);
							}

							return [
								'component' => 'k-remove-dialog',
								'props' => [
									'text' => $text,
									'submitButton' => [
										'icon' => 'trash',
										'text' => I18n::translate('sigtrygg-space.kirby-trash.emptyTrash'),
									],
								],
							];
						},
						'submit' => function () {
							$trash = Trash::instance();
							$trash->ensure('delete');
							$trash->emptyTrash();

							// kept items survive by design — say so instead
							// of claiming an empty trash next to a full list
							$key = $trash->count() > 0 ? 'emptiedExceptKept' : 'emptied';

							return [
								'message' => I18n::translate('sigtrygg-space.kirby-trash.notification.' . $key),
							];
						},
					],
				],
			];
		},
	],

	// `kirby trash:cleanup` for real server cronjobs (requires getkirby/cli)
	'commands' => [
		'trash:cleanup' => [
			'description' => 'Removes expired items from the trash',
			'args'        => [],
			'command'     => function ($cli) {
				$removed = Trash::instance()->cleanup();
				$cli->success($removed . ' expired trash item(s) removed');
			},
		],
	],
]);
