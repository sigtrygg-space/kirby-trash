<?php

use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Cms\Page;
use Kirby\Data\Data;
use Kirby\Filesystem\F;
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
	],

	// default: no access for non-admin roles (admins get everything
	// through Kirby's own permission resolution). Other roles can be
	// allowed via the role blueprint.
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
		// and thereby blocks the actual deletion (safety net)
		'page.delete:before' => function (Page $page) {
			$trash = Trash::instance();

			if ($trash->enabled() === false || $trash->covers($page->root()) === true) {
				return;
			}

			$trash->trashPage($page);

			// Kirby deletes files and children individually with their
			// own hooks; guard the root so they don't get own items
			$trash->guard($page->root());
		},
		'page.delete:after' => function (bool $status, Page $page) {
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

	'api' => [
		'routes' => [
			[
				'pattern' => 'trash',
				'method'  => 'GET',
				'action'  => function () {
					$trash = Trash::instance();
					$trash->ensure('access');
					$trash->cleanup();

					return [
						'items' => $trash->items(),
						'size'  => $trash->totalSize(),
					];
				},
			],
			[
				'pattern' => 'trash/(:any)/restore',
				'method'  => 'POST',
				'action'  => function (string $id) {
					$trash = Trash::instance();
					$trash->ensure('restore');
					$trash->restore($id);

					return ['status' => 'ok'];
				},
			],
			[
				'pattern' => 'trash/(:any)',
				'method'  => 'DELETE',
				'action'  => function (string $id) {
					$trash = Trash::instance();
					$trash->ensure('delete');
					$trash->delete($id);

					return ['status' => 'ok'];
				},
			],
			[
				'pattern' => 'trash',
				'method'  => 'DELETE',
				'action'  => function () {
					$trash = Trash::instance();
					$trash->ensure('delete');
					$trash->emptyTrash();

					return ['status' => 'ok'];
				},
			],
		],
	],

	'areas' => [
		'trash' => function (App $kirby) {
			$trash = Trash::instance();

			return [
				'label' => I18n::translate('sigtrygg-space.kirby-trash.title', 'Trash'),
				'icon'  => 'trash',
				'menu'  => $trash->can('access'),
				'link'  => 'trash',
				'views' => [
					[
						'pattern' => 'trash',
						'action'  => function () use ($trash) {
							$trash->ensure('access');
							$trash->cleanup();

							return [
								'component' => 'k-trash-view',
								'title'     => I18n::translate('sigtrygg-space.kirby-trash.title', 'Trash'),
								'props'     => [
									'items'      => $trash->panelItems(),
									'canRestore' => $trash->can('restore'),
									'canDelete'  => $trash->can('delete'),
									'totalSize'  => F::niceSize($trash->totalSize()),
								],
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
