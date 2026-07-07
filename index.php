<?php

use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Cms\Page;
use Kirby\Data\Data;
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
		'warnDays'      => 5,
		'warnTheme'     => 'warning',
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
				// Kirby's Panel\Menu; a null badge is filtered out
				'menu'  => $trash->enabled() === true && $trash->can('access')
					? ['badge' => $trash->badge()]
					: false,
				'link'  => 'trash',
				'views' => [
					[
						'pattern' => 'trash',
						'action'  => function () {
							$trash = Trash::instance();
							$trash->ensure('access');
							$trash->cleanup();

							return [
								'component' => 'k-trash-view',
								'title'     => I18n::translate('sigtrygg-space.kirby-trash.title', 'Trash'),
								'props'     => [
									'items'      => $trash->panelItems(),
									'columns'    => $trash->panelColumns(),
									'canRestore' => $trash->can('restore'),
									'canDelete'  => $trash->can('delete'),
								],
							];
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
							$fields  = [];

							// one displayable row value per field,
							// labelled by the table column; fields
							// without a column (deletedBy) use the
							// plugin key of the same name
							foreach ($trash->panelItem($id) as $key => $value) {
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
									'canRestore' => $trash->can('restore'),
									'canDelete'  => $trash->can('delete'),
								],
							];
						},
						// read-only: the close button closes client-side,
						// no submit handler needed
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

							$count = count($trash->items());
							$key   = $count === 1 ? 'one' : 'many';

							return [
								'component' => 'k-remove-dialog',
								'props' => [
									'text' => I18n::template('sigtrygg-space.kirby-trash.dialog.empty.' . $key, null, [
										'count' => $count,
										'size'  => F::niceSize($trash->totalSize()),
									]),
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

							return [
								'message' => I18n::translate('sigtrygg-space.kirby-trash.notification.emptied'),
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
