<?php

namespace SigtryggSpace\KirbyTrash;

use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Cms\Page;
use Kirby\Cms\Site;
use Kirby\Data\Data;
use Kirby\Exception\DuplicateException;
use Kirby\Exception\NotFoundException;
use Kirby\Exception\PermissionException;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use Kirby\Toolkit\I18n;
use Kirby\Toolkit\Str;
use Kirby\Uuid\Uuid;
use Throwable;

/**
 * Core logic of the trash bin: copying deleted models into the
 * trash storage, listing, restoring, deleting and cleaning up items.
 */
class Trash
{
	public const DEFAULT_RETENTION_DAYS = 30;

	protected static self|null $instance = null;

	/**
	 * Roots of pages that are currently being deleted and have
	 * already been copied to the trash. Nested deletions (children,
	 * files) triggered by Kirby inside such a root must not create
	 * their own trash items.
	 */
	protected array $active = [];

	/**
	 * Request-scoped cache of all item metadata,
	 * invalidated by every mutating operation
	 */
	protected array|null $index = null;

	public function __construct(protected App $kirby)
	{
	}

	public static function instance(): static
	{
		$kirby = App::instance();

		if (static::$instance === null || static::$instance->kirby !== $kirby) {
			static::$instance = new static($kirby);
		}

		return static::$instance;
	}

	public function enabled(): bool
	{
		return $this->kirby->option('sigtrygg-space.kirby-trash.enabled', true) !== false;
	}

	public function root(): string
	{
		$root = $this->kirby->option('sigtrygg-space.kirby-trash.root');

		if (is_callable($root) === true) {
			$root = $root($this->kirby);
		}

		return $root ?? $this->kirby->root('site') . '/storage/trash';
	}

	/**
	 * Number of days items are kept before they are removed
	 * automatically; `null` means items are kept forever.
	 *
	 * Any negative value (documented as `-1`) disables automatic
	 * cleanup; `0` is invalid and falls back to the default, so a
	 * misconfiguration can never wipe the trash instantly.
	 */
	public function retentionDays(): int|null
	{
		$days = $this->kirby->option(
			'sigtrygg-space.kirby-trash.retentionDays',
			static::DEFAULT_RETENTION_DAYS
		);

		if (is_numeric($days) === false) {
			return static::DEFAULT_RETENTION_DAYS;
		}

		// check before the int cast, so fractional values
		// like -0.5 don't truncate to 0 and re-enable cleanup
		if ($days < 0) {
			return null;
		}

		$days = (int)$days;

		if ($days === 0) {
			return static::DEFAULT_RETENTION_DAYS;
		}

		return $days;
	}

	/**
	 * Marks a page root as being trashed, so nested delete hooks
	 * for its files and children are skipped. Called by trashPage()
	 * itself, so programmatic callers get the protection too.
	 * Guarded roots are released by the `page.delete:after` hook;
	 * if Kirby's deletion itself fails, the root stays guarded for
	 * the rest of the request (and an orphaned trash item remains).
	 */
	protected function guard(string $root): void
	{
		$this->active[] = $root;
	}

	/**
	 * Whether the given filesystem root lies within a page
	 * that is already being trashed in this request
	 */
	public function covers(string $root): bool
	{
		foreach ($this->active as $active) {
			if ($root === $active || str_starts_with($root, $active . '/') === true) {
				return true;
			}
		}

		return false;
	}

	public function release(string $root): void
	{
		$this->active = array_values(
			array_filter($this->active, fn (string $active) => $active !== $root)
		);
	}

	/**
	 * Copies the whole page directory (incl. all languages, files,
	 * children and `_changes`) into the trash. Must be called from
	 * `page.delete:before` while the page still exists on disk.
	 * Exceptions are passed on intentionally: a failing copy
	 * blocks the actual deletion.
	 */
	public function trashPage(Page $page): string
	{
		$id         = $this->createId($page->slug());
		$itemRoot   = $this->root() . '/' . $id;
		$parent     = $page->parent();
		$parentRoot = $parent?->root() ?? $this->kirby->root('content');

		try {
			Dir::copy($page->root(), $itemRoot . '/data');

			$this->writeMeta($itemRoot, [
				'type'         => 'page',
				'id'           => $page->id(),
				'title'        => $page->title()->value(),
				'parent'       => $parent?->id(),
				'parentUuid'   => $this->uuidOf($parent, generate: true),
				'uuid'         => $this->uuidOf($page),
				'relativePath' => ltrim(substr($page->root(), strlen($parentRoot)), '/'),
			]);
		} catch (Throwable $e) {
			Dir::remove($itemRoot);
			throw $e;
		}

		$this->guard($page->root());

		return $id;
	}

	/**
	 * Copies a single file plus all of its content ("companion")
	 * files into the trash. Must be called from `file.delete:before`.
	 * Files of users (e.g. avatars) are not supported and skipped.
	 */
	public function trashFile(File $file): string|null
	{
		$parent = $file->parent();

		if ($parent instanceof Page === false && $parent instanceof Site === false) {
			return null;
		}

		$id        = $this->createId($file->filename());
		$itemRoot  = $this->root() . '/' . $id;
		$dataRoot  = $itemRoot . '/data';
		$sourceDir = dirname($file->root());

		try {
			Dir::make($dataRoot);
			F::copy($file->root(), $dataRoot . '/' . $file->filename());

			foreach ($this->companionFiles($sourceDir, $file->filename()) as $relative) {
				F::copy($sourceDir . '/' . $relative, $dataRoot . '/' . $relative);
			}

			$this->writeMeta($itemRoot, [
				'type'         => 'file',
				'id'           => $file->id(),
				'title'        => $file->filename(),
				'parent'       => $parent instanceof Page ? $parent->id() : null,
				'parentUuid'   => $parent instanceof Page ? $this->uuidOf($parent, generate: true) : null,
				'uuid'         => $this->uuidOf($file),
				'relativePath' => $file->filename(),
			]);
		} catch (Throwable $e) {
			Dir::remove($itemRoot);
			throw $e;
		}

		return $id;
	}

	/**
	 * All content files that belong to the given file, relative to
	 * its directory: `image.jpg.txt` in single-language setups,
	 * `image.jpg.en.txt` etc. in multi-language setups, plus their
	 * counterparts in the `_changes` folder (Kirby 5 versioning).
	 *
	 * Matches exact names only — a prefix match would also capture
	 * content files of sibling files like `image.jpg.gz`.
	 */
	protected function companionFiles(string $dir, string $filename): array
	{
		$extension = $this->kirby->contentExtension();
		$names     = [$filename . '.' . $extension];

		if ($this->kirby->multilang() === true) {
			foreach ($this->kirby->languages()->codes() as $code) {
				$names[] = $filename . '.' . $code . '.' . $extension;
			}
		}

		$relatives = [];

		foreach ([null, '_changes'] as $subfolder) {
			$scan = $subfolder === null ? $dir : $dir . '/' . $subfolder;

			if (is_dir($scan) === false) {
				continue;
			}

			foreach (Dir::files($scan) as $candidate) {
				if (in_array($candidate, $names, true) === true) {
					$relatives[] = ($subfolder === null ? '' : $subfolder . '/') . $candidate;
				}
			}
		}

		return $relatives;
	}

	/**
	 * Discards the request-scoped item cache; must be called by
	 * anything that modifies the trash storage directly on disk
	 */
	public function flushIndex(): void
	{
		$this->index = null;
	}

	/**
	 * All trash items, newest first
	 */
	public function items(): array
	{
		if ($this->index !== null) {
			return $this->index;
		}

		$root = $this->root();

		if (is_dir($root) === false) {
			return $this->index = [];
		}

		$items = [];

		foreach (Dir::read($root) as $dir) {
			try {
				$meta = Data::read($root . '/' . $dir . '/meta.json', 'json');
			} catch (Throwable) {
				continue;
			}

			$meta = $this->normalizeMeta($meta);
			$meta['trashId'] = $dir;
			$items[] = $meta;
		}

		usort(
			$items,
			fn (array $a, array $b) =>
				strcmp($b['deletedAt'] ?? '', $a['deletedAt'] ?? '')
		);

		return $this->index = $items;
	}

	public function item(string $id): array
	{
		$id   = $this->validateId($id);
		$file = $this->root() . '/' . $id . '/meta.json';

		if (is_file($file) === false) {
			throw $this->notFound();
		}

		$meta = $this->normalizeMeta(Data::read($file, 'json'));
		$meta['trashId'] = $id;

		return $meta;
	}

	/**
	 * Moves a trash item back to its original location.
	 * UUIDs survive because the content files are restored verbatim.
	 */
	public function restore(string $id): void
	{
		$meta       = $this->item($id);
		$itemRoot   = $this->root() . '/' . $meta['trashId'];
		$dataRoot   = $itemRoot . '/data';
		$parentRoot = $this->resolveParentRoot($meta);
		$target     = $parentRoot . '/' . $meta['relativePath'];

		if (file_exists($target) === true) {
			throw new DuplicateException(
				key: 'sigtrygg-space.kirby-trash.exists',
				fallback: 'The restore target already exists'
			);
		}

		if (($meta['type'] ?? null) === 'page') {
			Dir::copy($dataRoot, $target);
		} else {
			// merge the file and its companions into the
			// live parent directory instead of replacing it
			foreach (Dir::index($dataRoot, true) as $relative) {
				$source = $dataRoot . '/' . $relative;

				if (is_file($source) === false) {
					continue;
				}

				F::copy($source, $parentRoot . '/' . $relative, true);
			}
		}

		Dir::remove($itemRoot);
		$this->flushIndex();
		$this->flushCaches();
	}

	/**
	 * Removes a single trash item permanently
	 */
	public function delete(string $id): void
	{
		$id   = $this->validateId($id);
		$root = $this->root() . '/' . $id;

		if (is_dir($root) === false) {
			throw $this->notFound();
		}

		Dir::remove($root);
		$this->flushIndex();
	}

	/**
	 * Removes all trash items permanently,
	 * including broken entries without meta.json
	 */
	public function emptyTrash(): void
	{
		$root = $this->root();

		if (is_dir($root) === false) {
			return;
		}

		foreach (Dir::read($root) as $entry) {
			$path = $root . '/' . $entry;
			is_dir($path) === true ? Dir::remove($path) : F::remove($path);
		}

		$this->flushIndex();
	}

	/**
	 * Removes all items that are older than the configured
	 * retention period; returns the number of removed items
	 */
	public function cleanup(): int
	{
		$days = $this->retentionDays();

		if ($days === null) {
			return 0;
		}

		$expiry  = time() - $days * 86400;
		$removed = 0;

		foreach ($this->items() as $item) {
			$deletedAt = strtotime($item['deletedAt'] ?? '') ?: null;

			if ($deletedAt !== null && $deletedAt < $expiry) {
				$this->delete($item['trashId']);
				$removed++;
			}
		}

		return $removed;
	}

	public function totalSize(): int
	{
		return array_sum(
			array_map(fn (array $item) => (int)($item['size'] ?? 0), $this->items())
		);
	}

	/**
	 * Permission check for the current user. Admins are always
	 * allowed: a site with a custom admin.yml blueprint that does
	 * not state `permissions: true` would otherwise resolve the
	 * registered plugin defaults (`false`) and lock admins out.
	 * Other roles need an explicit `sigtrygg-space.kirby-trash`
	 * grant in their role blueprint.
	 */
	public function can(string $action): bool
	{
		$user = $this->kirby->user();

		if ($user === null) {
			return false;
		}

		if ($user->isAdmin() === true) {
			return true;
		}

		return $user->role()->permissions()->for('sigtrygg-space.kirby-trash', $action) === true;
	}

	public function ensure(string $action): void
	{
		if ($this->can($action) === false) {
			throw new PermissionException(
				key: 'sigtrygg-space.kirby-trash.permission',
				fallback: 'You are not allowed to do this'
			);
		}
	}

	/**
	 * Items prepared for the Panel view (k-collection format)
	 */
	public function panelItems(): array
	{
		$days  = $this->retentionDays();
		$items = [];

		foreach ($this->items() as $meta) {
			$deletedAt = strtotime($meta['deletedAt'] ?? '') ?: null;
			$remaining = null;

			if ($days !== null && $deletedAt !== null) {
				$remaining = max(0, (int)ceil(($deletedAt + $days * 86400 - time()) / 86400));
			}

			$info = [
				$meta['id'] ?? '',
				F::niceSize((int)($meta['size'] ?? 0)),
				$deletedAt !== null ? date('Y-m-d H:i', $deletedAt) : null,
				$remaining !== null
					? I18n::template('sigtrygg-space.kirby-trash.info.remaining', null, ['days' => $remaining])
					: null,
			];

			$items[] = [
				'trashId' => $meta['trashId'],
				'text'    => $meta['title'] ?? $meta['id'] ?? $meta['trashId'],
				'info'    => implode(' · ', array_filter($info)),
				'image'   => [
					'icon' => $this->icon($meta),
					'back' => 'black',
				],
			];
		}

		return $items;
	}

	protected function icon(array $meta): string
	{
		if (($meta['type'] ?? null) === 'page') {
			return 'page';
		}

		return match (F::type($meta['relativePath'] ?? '')) {
			'image'    => 'image',
			'video'    => 'video',
			'audio'    => 'audio',
			'document' => 'document',
			default    => 'file',
		};
	}

	protected function notFound(): NotFoundException
	{
		return new NotFoundException(
			key: 'sigtrygg-space.kirby-trash.notFound',
			fallback: 'The trash item could not be found'
		);
	}

	protected function createId(string $name): string
	{
		$slug = Str::slug($name);
		$slug = $slug === '' ? 'item' : Str::short($slug, 40, '');

		return $slug . '-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
	}

	protected function validateId(string $id): string
	{
		if ($id === '' || $id !== basename($id) || str_contains($id, '..') === true) {
			throw $this->notFound();
		}

		return $id;
	}

	/**
	 * Writes the item metadata, appending the fields
	 * shared by all item types
	 */
	protected function writeMeta(string $itemRoot, array $meta): void
	{
		Data::write($itemRoot . '/meta.json', [
			'version'   => 1,
			...$meta,
			'size'      => Dir::size($itemRoot . '/data') ?: 0,
			'deletedAt' => date('c'),
			'deletedBy' => $this->kirby->user()?->email(),
		], 'json');

		$this->flushIndex();
	}

	/**
	 * Upgrades item metadata written by older plugin versions to
	 * the current shape, in one place for all read paths.
	 * Pre-1 metas stored the file name in `filename` instead
	 * of `relativePath`.
	 */
	protected function normalizeMeta(array $meta): array
	{
		$meta['relativePath'] ??= $meta['filename'] ?? basename($meta['id'] ?? '');

		return $meta;
	}

	/**
	 * Resolves the current root directory the item has to be
	 * restored into. Parents are looked up freshly (UUID first,
	 * then id), so renamed parents are handled correctly.
	 */
	protected function resolveParentRoot(array $meta): string
	{
		if (($meta['parent'] ?? null) === null) {
			return $this->kirby->root('content');
		}

		$parent = null;

		if (empty($meta['parentUuid']) === false) {
			try {
				$model = Uuid::for($meta['parentUuid'])->model();
				$parent = $model instanceof Page ? $model : null;
			} catch (Throwable) {
				$parent = null;
			}
		}

		$parent ??= $this->kirby->page($meta['parent']);

		if ($parent instanceof Page === false) {
			throw new NotFoundException(
				key: 'sigtrygg-space.kirby-trash.missingParent',
				data: ['parent' => $meta['parent']],
				fallback: 'The original parent page "{parent}" does not exist anymore'
			);
		}

		return $parent->root();
	}

	/**
	 * UUID of the given model as a `page://…`/`file://…` string,
	 * or `null` when UUIDs are disabled or none is stored.
	 *
	 * For the model that is being deleted the stored UUID is read
	 * without generating a missing one — generating would write into
	 * content that is removed moments later. For surviving parents
	 * `$generate` mints a missing UUID on purpose, so restoring
	 * still works after the parent has been renamed.
	 *
	 * Never throws: this runs inside deletion-blocking hooks, and a
	 * broken UUID must not prevent deletions site-wide.
	 */
	protected function uuidOf(Page|File|null $model, bool $generate = false): string|null
	{
		if ($model === null) {
			return null;
		}

		try {
			if ($generate === true) {
				return $model->uuid()?->toString();
			}

			$uuid = Uuid::for($model); // null when UUIDs are disabled

			if ($uuid === null) {
				return null;
			}

			$id = $uuid::retrieveId($model);

			if ($id === null || $id === '') {
				return null;
			}

			return ($model instanceof Page ? 'page' : 'file') . '://' . $id;
		} catch (Throwable) {
			return null;
		}
	}

	/**
	 * Restored content bypasses Kirby's model actions,
	 * so caches have to be flushed manually
	 */
	protected function flushCaches(): void
	{
		foreach (['pages', 'uuid'] as $cache) {
			try {
				$this->kirby->cache($cache)->flush();
			} catch (Throwable) {
				// cache not available/enabled — nothing to flush
			}
		}
	}
}
