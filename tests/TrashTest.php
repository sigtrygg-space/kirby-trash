<?php

namespace SigtryggSpace\KirbyTrash;

use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Cms\Page;
use Kirby\Data\Data;
use Kirby\Exception\DuplicateException;
use Kirby\Exception\NotFoundException;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use PHPUnit\Framework\TestCase;
use Throwable;

final class TrashTest extends TestCase
{
	protected App $kirby;
	protected string $tmp;

	protected function setUp(): void
	{
		// App::destroy() in tearDown() wipes Kirby's static plugin
		// registry, so the plugin has to be re-registered per test
		if (App::plugin('sigtrygg-space/kirby-trash') === null) {
			require dirname(__DIR__) . '/index.php';
		}

		$this->tmp = sys_get_temp_dir() . '/kirby-trash-test-' . bin2hex(random_bytes(4));
		$this->kirby = $this->app();
	}

	protected function tearDown(): void
	{
		App::destroy();
		Dir::remove($this->tmp);
	}

	protected function app(array $options = [], array $props = []): App
	{
		Dir::make($this->tmp . '/content');

		$kirby = new App([
			'roots' => [
				'index'    => $this->tmp,
				'content'  => $this->tmp . '/content',
				'site'     => $this->tmp . '/site',
				'media'    => $this->tmp . '/media',
				'accounts' => $this->tmp . '/accounts',
				'sessions' => $this->tmp . '/sessions',
				'cache'    => $this->tmp . '/cache',
			],
			'options' => $options,
			...$props,
		]);

		$kirby->impersonate('kirby');

		return $kirby;
	}

	protected function trash(): Trash
	{
		return Trash::instance();
	}

	/**
	 * Fresh app instance without memoized models;
	 * clone() drops the impersonation, so re-impersonate
	 */
	protected function fresh(): App
	{
		$clone = $this->kirby->clone();
		$clone->impersonate('kirby');

		return $clone;
	}

	protected function createPage(string $slug, array $content = [], Page|null $parent = null): Page
	{
		return Page::create([
			'slug'     => $slug,
			'parent'   => $parent,
			'template' => 'default',
			'content'  => ['title' => ucfirst($slug), ...$content],
		]);
	}

	protected function createFile(Page $page, string $filename, array $content = []): File
	{
		$source = $this->tmp . '/' . $filename;
		F::write($source, 'file content of ' . $filename);

		$file = File::create([
			'source'   => $source,
			'parent'   => $page,
			'filename' => $filename,
		]);

		if ($content !== []) {
			$file = $file->update($content);
		}

		return $file;
	}

	public function testDeletedPageEndsUpInTrash(): void
	{
		$page = $this->createPage('test');
		$root = $page->root();

		$page->delete();

		$this->assertDirectoryDoesNotExist($root);

		$items = $this->trash()->items();
		$this->assertCount(1, $items);
		$this->assertSame('page', $items[0]['type']);
		$this->assertSame('test', $items[0]['id']);
		$this->assertSame('Test', $items[0]['title']);
		$this->assertNotEmpty($items[0]['deletedAt']);
		$this->assertGreaterThan(0, $items[0]['size']);
	}

	public function testRestorePage(): void
	{
		$page = $this->createPage('test', ['text' => 'Hello']);
		$uuid = $page->uuid()->toString();
		$this->assertNotEmpty($uuid);

		$page->delete();
		$this->assertNull($this->kirby->page('test'));

		$items = $this->trash()->items();
		$this->trash()->restore($items[0]['trashId']);

		$this->assertCount(0, $this->trash()->items());

		$restored = $this->fresh()->page('test');
		$this->assertNotNull($restored);
		$this->assertSame('Hello', $restored->text()->value());
		$this->assertSame($uuid, 'page://' . $restored->content()->get('uuid')->value());
	}

	public function testPageWithFilesAndChildrenCreatesSingleTrashItem(): void
	{
		$parent = $this->createPage('parent');
		$this->createFile($parent, 'test.jpg', ['alt' => 'An image']);
		$this->createPage('child', parent: $parent);

		$parent = $this->fresh()->page('parent');
		$parent->delete(true);

		$items = $this->trash()->items();
		$this->assertCount(1, $items, 'nested file/child deletions must not create own trash items');
		$this->assertSame('page', $items[0]['type']);

		$this->trash()->restore($items[0]['trashId']);

		$restored = $this->fresh()->page('parent');
		$this->assertNotNull($restored);
		$this->assertNotNull($restored->file('test.jpg'));
		$this->assertSame('An image', $restored->file('test.jpg')->alt()->value());
		$this->assertNotNull($restored->childrenAndDrafts()->find('child'));
	}

	public function testRestoreFileWithCompanionContentFiles(): void
	{
		$page = $this->createPage('gallery');
		$file = $this->createFile($page, 'test.jpg', ['alt' => 'Alt text stays']);

		$file->delete();

		$page = $this->fresh()->page('gallery');
		$this->assertNull($page->file('test.jpg'));

		$items = $this->trash()->items();
		$this->assertCount(1, $items);
		$this->assertSame('file', $items[0]['type']);
		$this->assertSame('test.jpg', $items[0]['relativePath']);

		$this->trash()->restore($items[0]['trashId']);

		$restored = $this->fresh()->page('gallery')->file('test.jpg');
		$this->assertNotNull($restored);
		$this->assertSame('Alt text stays', $restored->alt()->value());
	}

	public function testRestoreFileWithMultiLanguageContentFiles(): void
	{
		$this->kirby = $this->app(
			options: ['languages' => true],
			props: [
				'languages' => [
					['code' => 'en', 'name' => 'English', 'default' => true],
					['code' => 'de', 'name' => 'Deutsch'],
				],
			]
		);

		$page = $this->createPage('gallery');
		$file = $this->createFile($page, 'test.jpg', ['alt' => 'English alt']);
		$file->update(['alt' => 'Deutscher Alt-Text'], 'de');

		$this->fresh()->page('gallery')->file('test.jpg')->delete();

		$items = $this->trash()->items();
		$this->assertCount(1, $items);

		$dataRoot = $this->trash()->root() . '/' . $items[0]['trashId'] . '/data';
		$this->assertFileExists($dataRoot . '/test.jpg.en.txt');
		$this->assertFileExists($dataRoot . '/test.jpg.de.txt');

		$this->trash()->restore($items[0]['trashId']);

		$restored = $this->fresh()->page('gallery')->file('test.jpg');
		$this->assertNotNull($restored);
		$this->assertSame('English alt', $restored->content('en')->get('alt')->value());
		$this->assertSame('Deutscher Alt-Text', $restored->content('de')->get('alt')->value());
	}

	public function testRestoreFileWithCustomContentExtension(): void
	{
		$this->kirby = $this->app([
			'content' => ['extension' => 'md'],
		]);

		$page = $this->createPage('gallery');
		$file = $this->createFile($page, 'test.jpg', ['alt' => 'Alt text stays']);

		$this->assertFileExists($page->root() . '/test.jpg.md');

		$file->delete();

		$items = $this->trash()->items();
		$this->assertCount(1, $items);

		$dataRoot = $this->trash()->root() . '/' . $items[0]['trashId'] . '/data';
		$this->assertFileExists($dataRoot . '/test.jpg.md');

		$this->trash()->restore($items[0]['trashId']);

		$restored = $this->fresh()->page('gallery')->file('test.jpg');
		$this->assertNotNull($restored);
		$this->assertSame('Alt text stays', $restored->alt()->value());
	}

	public function testRestoreFailsWhenParentIsGone(): void
	{
		$parent = $this->createPage('parent');
		$child  = $this->createPage('child', parent: $parent);

		$child->delete();
		$this->fresh()->page('parent')->delete(true);

		$items = $this->trash()->items();
		$childItem = array_values(
			array_filter($items, fn (array $item) => $item['id'] === 'parent/child')
		)[0];

		$this->expectException(NotFoundException::class);
		$this->trash()->restore($childItem['trashId']);
	}

	public function testRestoreFailsWhenTargetExists(): void
	{
		$page = $this->createPage('test');
		$page->delete();

		$this->createPage('test');
		$items = $this->trash()->items();

		$this->expectException(DuplicateException::class);
		$this->trash()->restore($items[0]['trashId']);
	}

	public function testDeleteAndEmptyTrash(): void
	{
		$this->createPage('one')->delete();
		$this->createPage('two')->delete();
		$this->createPage('three')->delete();

		$trash = $this->trash();
		$this->assertCount(3, $trash->items());

		$trash->delete($trash->items()[0]['trashId']);
		$this->assertCount(2, $trash->items());

		$trash->emptyTrash();
		$this->assertCount(0, $trash->items());
	}

	public function testCleanupRemovesExpiredItems(): void
	{
		$this->createPage('old')->delete();
		$this->createPage('fresh')->delete();

		$trash = $this->trash();
		$this->backdateItem('old', 40);

		$removed = $trash->cleanup();

		$this->assertSame(1, $removed);
		$this->assertCount(1, $trash->items());
		$this->assertSame('fresh', $trash->items()[0]['id']);
	}

	public function testCleanupKeepsEverythingWithNegativeRetention(): void
	{
		$this->kirby = $this->app([
			'sigtrygg-space.kirby-trash.retentionDays' => -1,
		]);

		$this->createPage('old')->delete();
		$this->backdateItem('old', 4000);

		$this->assertNull($this->trash()->retentionDays());
		$this->assertSame(0, $this->trash()->cleanup());
		$this->assertCount(1, $this->trash()->items());
	}

	public function testRetentionDaysZeroFallsBackToDefault(): void
	{
		$this->kirby = $this->app([
			'sigtrygg-space.kirby-trash.retentionDays' => 0,
		]);

		$this->assertSame(Trash::DEFAULT_RETENTION_DAYS, $this->trash()->retentionDays());
	}

	public function testFailingCopyBlocksDeletion(): void
	{
		// point the trash root below a regular file so that
		// the copy operation cannot create its directories
		F::write($this->tmp . '/blocker', 'not a directory');

		$this->kirby = $this->app([
			'sigtrygg-space.kirby-trash.root' => $this->tmp . '/blocker/trash',
		]);

		$page = $this->createPage('important');
		$root = $page->root();

		try {
			$page->delete();
			$this->fail('deletion should have thrown');
		} catch (Throwable) {
			// expected: the failing trash copy blocks the deletion
		}

		$this->assertDirectoryExists($root);
	}

	public function testDisabledPluginSkipsTrash(): void
	{
		$this->kirby = $this->app([
			'sigtrygg-space.kirby-trash.enabled' => false,
		]);

		$this->createPage('test')->delete();

		$this->assertCount(0, $this->trash()->items());
	}

	public function testInvalidIdsAreRejected(): void
	{
		$this->expectException(NotFoundException::class);
		$this->trash()->item('../../etc/passwd');
	}

	protected function backdateItem(string $pageId, int $days): void
	{
		foreach ($this->trash()->items() as $item) {
			if ($item['id'] === $pageId) {
				$file = $this->trash()->root() . '/' . $item['trashId'] . '/meta.json';
				$meta = Data::read($file, 'json');
				$meta['deletedAt'] = date('c', time() - $days * 86400);
				Data::write($file, $meta, 'json');
				$this->trash()->flushIndex();
				return;
			}
		}

		$this->fail('trash item for ' . $pageId . ' not found');
	}
}
