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
				'index'      => $this->tmp,
				'content'    => $this->tmp . '/content',
				'site'       => $this->tmp . '/site',
				'blueprints' => $this->tmp . '/site/blueprints',
				'media'      => $this->tmp . '/media',
				'accounts'   => $this->tmp . '/accounts',
				'sessions'   => $this->tmp . '/sessions',
				'cache'      => $this->tmp . '/cache',
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

	public function testCoversNormalizesMixedPathSeparators(): void
	{
		// on Windows, Kirby reports roots with mixed separators within
		// one request (`C:\...\content/1_a` vs. `C:\...\content\1_a`);
		// the guard has to match them regardless. String fixtures stand
		// in for real Windows paths, so this also runs on Linux CI.
		$trash = new class ($this->kirby) extends Trash {
			public function guardForTest(string $root): void
			{
				$this->guard($root);
			}
		};

		$trash->guardForTest('C:\\sites\\demo\\content/1_photography');

		$this->assertTrue($trash->covers('C:\\sites\\demo\\content\\1_photography'));
		$this->assertTrue($trash->covers('C:\\sites\\demo\\content\\1_photography/1_trees'));
		$this->assertTrue($trash->covers('C:/sites/demo/content/1_photography/2_sky'));
		$this->assertFalse($trash->covers('C:\\sites\\demo\\content\\2_notes'));
		$this->assertFalse($trash->covers('C:/sites/demo/content/10_photography-archive'));

		$trash->release('C:/sites/demo/content/1_photography');

		$this->assertFalse($trash->covers('C:\\sites\\demo\\content\\1_photography'));
	}

	public function testPanelItemsProvideTableRows(): void
	{
		$this->createPage('note');
		$this->fresh()->page('note')->delete();

		$rows = $this->trash()->panelItems();

		$this->assertCount(1, $rows);
		$this->assertSame('Note', $rows[0]['title']);
		$this->assertSame('note', $rows[0]['path']);
		$this->assertSame('30 days left', $rows[0]['remaining']);
		$this->assertNotEmpty($rows[0]['size']);
		$this->assertNotEmpty($rows[0]['deletedAt']);
		$this->assertArrayHasKey('trashId', $rows[0]);
	}

	public function testPanelItemsPluralizeRemainingDays(): void
	{
		$this->createPage('note');
		$this->fresh()->page('note')->delete();

		// one day left: deleted (retention - 1) days ago
		$this->backdateItem('note', 29);
		$this->assertSame('1 day left', $this->trash()->panelItems()[0]['remaining']);

		// retention disabled: kept forever
		$this->kirby = $this->app([
			'sigtrygg-space.kirby-trash.retentionDays' => -1,
		]);
		$this->assertSame('Kept forever', $this->trash()->panelItems()[0]['remaining']);
	}

	public function testMenuBadgeShowsItemCount(): void
	{
		$this->createPage('note');
		$this->fresh()->page('note')->delete();

		$trash = $this->trash();
		$this->assertSame(1, $trash->count());
		$this->assertSame(['theme' => 'notice', 'text' => 1], $trash->badge());

		// the area menu carries the badge into the button props
		$area = (App::plugin('sigtrygg-space/kirby-trash')->extends()['areas']['trash'])($this->kirby);
		$this->assertSame(['badge' => ['theme' => 'notice', 'text' => 1]], $area['menu']);

		$trash->emptyTrash();
		$this->assertSame(0, $trash->count());
		$this->assertNull($trash->badge());
	}

	public function testMenuBadgeCanBeDisabledAndThemed(): void
	{
		$this->kirby = $this->app([
			'sigtrygg-space.kirby-trash.badge' => false,
		]);

		$this->createPage('note');
		$this->fresh()->page('note')->delete();

		$this->assertSame(1, $this->trash()->count());
		$this->assertNull($this->trash()->badge());

		$this->kirby = $this->app([
			'sigtrygg-space.kirby-trash.badge' => ['theme' => 'passive'],
		]);

		$this->assertSame(['theme' => 'passive', 'text' => 1], $this->trash()->badge());
	}

	public function testWarnStateHighlightsExpiringItems(): void
	{
		$this->createPage('note');
		$this->fresh()->page('note')->delete();

		// fresh item: 30 days left, no warn state
		$this->assertFalse($this->trash()->expiresSoon());
		$this->assertFalse($this->trash()->panelItems()[0]['expiresSoon']);
		$this->assertSame('notice', $this->trash()->badge()['theme']);

		// 2 days left (retention 30, deleted 28 days ago): warn state
		$this->backdateItem('note', 28);
		$this->assertTrue($this->trash()->expiresSoon());
		$this->assertTrue($this->trash()->panelItems()[0]['expiresSoon']);
		$this->assertSame('warning', $this->trash()->badge()['theme']);

		// the column definition carries cell type and warn theme
		$columns = $this->trash()->panelColumns();
		$this->assertSame('remaining', $columns['remaining']['type']);
		$this->assertSame('warning', $columns['remaining']['warnTheme']);
	}

	public function testExpiredItemsAreIgnoredByBadgeAndWarnState(): void
	{
		$this->createPage('note');
		$this->createPage('other');
		$this->fresh()->page('note')->delete();
		$this->fresh()->page('other')->delete();

		// one item expired 10 days ago, one freshly deleted
		$this->backdateItem('note', 40);

		$trash = $this->trash();
		$this->assertSame(2, $trash->count());
		$this->assertSame(1, $trash->expiredCount());

		// the badge counts only the live item and does not warn
		// (the fresh item has 30 days left)
		$this->assertSame(1, $trash->badge()['text']);
		$this->assertSame('notice', $trash->badge()['theme']);

		// the expired row itself does not warn either
		$rows = array_column($trash->panelItems(), null, 'path');
		$this->assertFalse($rows['note']['expiresSoon']);

		// both expired: no future expiry, the badge disappears
		$this->backdateItem('other', 40);
		$this->assertNull($this->trash()->nextExpiry());
		$this->assertFalse($this->trash()->expiresSoon());
		$this->assertNull($this->trash()->badge());
	}

	public function testWarnStateCanBeDisabledAndRespectsRetention(): void
	{
		$this->createPage('note');
		$this->fresh()->page('note')->delete();
		$this->backdateItem('note', 28);

		// warnDays 0 disables the warn state entirely
		$this->kirby = $this->app([
			'sigtrygg-space.kirby-trash.warnDays' => 0,
		]);
		$this->assertFalse($this->trash()->expiresSoon());
		$this->assertFalse($this->trash()->panelItems()[0]['expiresSoon']);
		$this->assertSame('notice', $this->trash()->badge()['theme']);

		// retention disabled: nothing ever expires
		$this->kirby = $this->app([
			'sigtrygg-space.kirby-trash.retentionDays' => -1,
		]);
		$this->assertNull($this->trash()->nextExpiry());
		$this->assertFalse($this->trash()->expiresSoon());
	}

	public function testPanelDialogs(): void
	{
		$this->createPage('note');
		$this->fresh()->page('note')->delete();

		$item    = $this->trash()->items()[0];
		$area    = (App::plugin('sigtrygg-space/kirby-trash')->extends()['areas']['trash'])($this->kirby);
		$dialogs = $area['dialogs'];

		// details: read-only, lists all metadata fields
		$details = $dialogs['trash.details']['load']($item['trashId']);
		$this->assertSame('k-trash-details-dialog', $details['component']);
		$this->assertSame($item['trashId'], $details['props']['trashId']);
		$this->assertContains('Original path', array_column($details['props']['fields'], 'label'));

		// restore: confirmation text with the title, submit restores
		$restore = $dialogs['trash.restore']['load']($item['trashId']);
		$this->assertSame('k-text-dialog', $restore['component']);
		$this->assertStringContainsString('Note', $restore['props']['text']);

		$this->assertSame('Restored', $dialogs['trash.restore']['submit']($item['trashId'])['message']);
		$this->assertNotNull($this->fresh()->page('note'));
		$this->assertCount(0, $this->trash()->items());

		// delete: submit removes the item permanently
		$this->fresh()->page('note')->delete();
		$item = $this->trash()->items()[0];
		$this->assertSame('k-remove-dialog', $dialogs['trash.delete']['load']($item['trashId'])['component']);
		$this->assertSame('Deleted permanently', $dialogs['trash.delete']['submit']($item['trashId'])['message']);
		$this->assertCount(0, $this->trash()->items());

		// empty: singular text for a single item, submit empties
		$this->createPage('note-2');
		$this->fresh()->page('note-2')->delete();
		$this->assertStringContainsString('1 item ', $dialogs['trash.empty']['load']()['props']['text']);
		$this->assertSame('The trash has been emptied', $dialogs['trash.empty']['submit']()['message']);
		$this->assertCount(0, $this->trash()->items());
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

	public function testDisabledPluginRefusesPanelAccess(): void
	{
		$this->kirby = $this->app([
			'sigtrygg-space.kirby-trash.enabled' => false,
		]);

		try {
			$this->trash()->ensure('access');
			$this->fail('access to a disabled trash must be refused');
		} catch (Throwable $e) {
			$this->assertSame('The trash is disabled', $e->getMessage());
		}
	}

	public function testEnabledOptionAcceptsClosure(): void
	{
		$this->kirby = $this->app([
			'sigtrygg-space.kirby-trash.enabled' => fn (App $kirby) => false,
		]);

		$this->createPage('test')->delete();
		$this->assertCount(0, $this->trash()->items());

		$this->kirby = $this->app([
			'sigtrygg-space.kirby-trash.enabled' => fn (App $kirby) => $kirby instanceof App,
		]);

		$this->createPage('test')->delete();
		$this->assertCount(1, $this->trash()->items());
	}

	public function testAdminWithCustomBlueprintKeepsAccess(): void
	{
		// a custom admin.yml without `permissions: true` resolves the
		// registered plugin defaults (false); admins must stay allowed
		F::write($this->tmp . '/site/blueprints/users/admin.yml', 'title: Administrator');

		$this->kirby = $this->app(props: [
			'users' => [
				['email' => 'admin@example.com', 'role' => 'admin'],
			],
		]);
		$this->kirby->impersonate('admin@example.com');

		$this->assertTrue($this->trash()->can('access'));
		$this->assertTrue($this->trash()->can('restore'));
		$this->assertTrue($this->trash()->can('delete'));
	}

	public function testRestoreLegacyFileItemWithoutRelativePath(): void
	{
		$this->createPage('gallery');

		// file item as written by pre-release versions:
		// `filename` instead of `relativePath`, no `version`
		$itemRoot = $this->trash()->root() . '/legacy-item';
		F::write($itemRoot . '/data/test.jpg', 'binary');
		F::write($itemRoot . '/data/test.jpg.txt', "Alt: legacy alt\n");
		Data::write($itemRoot . '/meta.json', [
			'type'      => 'file',
			'id'        => 'gallery/test.jpg',
			'title'     => 'test.jpg',
			'filename'  => 'test.jpg',
			'parent'    => 'gallery',
			'size'      => 6,
			'deletedAt' => '2026-07-01T12:00:00+00:00',
		], 'json');
		$this->trash()->flushIndex();

		$this->assertSame('test.jpg', $this->trash()->item('legacy-item')['relativePath']);

		$this->trash()->restore('legacy-item');

		$restored = $this->fresh()->page('gallery')->file('test.jpg');
		$this->assertNotNull($restored);
		$this->assertSame('legacy alt', $restored->alt()->value());
	}

	public function testCompanionMatchingIgnoresSiblingFiles(): void
	{
		$page = $this->createPage('downloads');
		$this->createFile($page, 'a.tar', ['alt' => 'tar alt']);
		$this->createFile($page, 'a.tar.gz', ['alt' => 'gz alt']);

		$this->fresh()->page('downloads')->file('a.tar')->delete();

		$items    = $this->trash()->items();
		$dataRoot = $this->trash()->root() . '/' . $items[0]['trashId'] . '/data';
		$this->assertSame(['a.tar', 'a.tar.txt'], Dir::files($dataRoot));

		// the sibling's content must survive a later update + restore
		$this->fresh()->page('downloads')->file('a.tar.gz')->update(['alt' => 'gz alt updated']);
		$this->trash()->restore($items[0]['trashId']);

		$page = $this->fresh()->page('downloads');
		$this->assertSame('tar alt', $page->file('a.tar')->alt()->value());
		$this->assertSame('gz alt updated', $page->file('a.tar.gz')->alt()->value());
	}

	public function testParentUuidIsGeneratedForUuidLessParents(): void
	{
		$parent = $this->createPage('parent');
		$this->createPage('child', parent: $parent);

		// strip the stored uuid, as in content migrated from Kirby 3
		$contentFile = $parent->root() . '/default.txt';
		$content     = preg_replace('/^Uuid:[^\n]*\n?/mi', '', F::read($contentFile));
		F::write($contentFile, $content);
		$this->assertStringNotContainsStringIgnoringCase('uuid:', F::read($contentFile));

		$this->fresh()->page('parent/child')->delete();

		$item = $this->trash()->items()[0];
		$this->assertNotNull($item['parentUuid']);
		$this->assertStringStartsWith('page://', $item['parentUuid']);
		$this->assertStringContainsString('Uuid:', F::read($contentFile));
	}

	public function testRetentionDaysNegativeFractionMeansForever(): void
	{
		$this->kirby = $this->app([
			'sigtrygg-space.kirby-trash.retentionDays' => -0.5,
		]);

		$this->assertNull($this->trash()->retentionDays());
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
