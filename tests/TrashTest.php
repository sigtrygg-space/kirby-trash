<?php

namespace SigtryggSpace\KirbyTrash;

use Closure;
use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Cms\Page;
use Kirby\Data\Data;
use Kirby\Exception\DuplicateException;
use Kirby\Exception\NotFoundException;
use Kirby\Filesystem\Dir;
use Kirby\Filesystem\F;
use Kirby\Http\Response;
use Kirby\Panel\Menu;
use Kirby\Panel\Panel;
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

	public function testRootIssueDetectsUnusableRoots(): void
	{
		// a missing root with a writable ancestor is the normal
		// state before the first deletion — no issue
		$this->assertNull($this->trash()->rootIssue());

		// an existing, readable root is fine too
		$this->createPage('note');
		$this->fresh()->page('note')->delete();
		$this->assertNull($this->trash()->rootIssue());

		// the root itself exists as a file: cannot be created
		F::write($this->tmp . '/blocker', 'not a directory');
		$this->kirby = $this->app([
			'sigtrygg-space.kirby-trash.root' => $this->tmp . '/blocker',
		]);
		$this->assertStringContainsString('cannot be created', $this->trash()->rootIssue());

		// a file in the middle of the path blocks creation as well
		$this->kirby = $this->app([
			'sigtrygg-space.kirby-trash.root' => $this->tmp . '/blocker/trash',
		]);
		$this->assertStringContainsString('cannot be created', $this->trash()->rootIssue());
	}

	public function testRootIssueDetectsPermissionProblems(): void
	{
		// a separate test, so environments that cannot simulate
		// POSIX permission problems skip only this part and still
		// run the portable cases above

		$locked = $this->tmp . '/locked';
		Dir::make($locked);
		chmod($locked, 0000);

		// the superuser, Windows and filesystems that carry no POSIX
		// modes (WSL DrvFs, some bind and network mounts) all leave
		// the directory usable. Checking whether the chmod took
		// effect covers every one of them; enumerating the
		// environments instead would fail here rather than skip.
		if (is_readable($locked) === true || is_writable($locked) === true) {
			chmod($locked, 0755);
			$this->markTestSkipped('directory permissions are not enforced here');
		}

		try {
			$this->kirby = $this->app([
				'sigtrygg-space.kirby-trash.root' => $locked . '/trash',
			]);
			$this->assertStringContainsString('cannot be created', $this->trash()->rootIssue());

			$this->kirby = $this->app([
				'sigtrygg-space.kirby-trash.root' => $locked,
			]);
			$this->assertStringContainsString('not readable', $this->trash()->rootIssue());
			$this->assertSame([], $this->trash()->items());
			$this->assertSame(0, $this->trash()->count());

			// every reader degrades to "empty", incl. the one the
			// empty-trash dialog calls right after count()
			$this->assertSame(0, $this->trash()->totalSize());
		} finally {
			chmod($locked, 0755);
		}
	}

	public function testRootIssueDetectsDanglingSymlinks(): void
	{
		// dangling symlinks report as missing via file_exists() but
		// still block creation — as the root and inside the path.
		// A separate test, so environments without symlink support
		// (e.g. Windows without elevated rights) skip only this part.
		Dir::make($this->tmp);

		if (@symlink($this->tmp . '/does-not-exist', $this->tmp . '/dangling') !== true) {
			$this->markTestSkipped('symlinks cannot be created in this environment');
		}

		$this->kirby = $this->app([
			'sigtrygg-space.kirby-trash.root' => $this->tmp . '/dangling',
		]);
		$this->assertStringContainsString('cannot be created', $this->trash()->rootIssue());

		$this->kirby = $this->app([
			'sigtrygg-space.kirby-trash.root' => $this->tmp . '/dangling/trash',
		]);
		$this->assertStringContainsString('cannot be created', $this->trash()->rootIssue());
	}

	public function testPostponeExtendsRetention(): void
	{
		$this->createPage('note');
		$this->fresh()->page('note')->delete();

		// 2 days left: warn state active
		$this->backdateItem('note', 28);
		$this->assertTrue($this->trash()->expiresSoon());

		$trashId = $this->trash()->items()[0]['trashId'];
		$this->trash()->postpone($trashId);

		// a full cycle again — and the "Deleted" date stays truthful
		$row = $this->trash()->panelItems()[0];
		$this->assertSame('30 days left', $row['remaining']);
		$this->assertFalse($row['expiresSoon']);
		$this->assertFalse($this->trash()->expiresSoon());

		// cleanup respects keepUntil even when deletedAt is long past
		$this->backdateItem('note', 40);
		$this->assertSame(0, $this->trash()->cleanup());
		$this->assertCount(1, $this->trash()->items());

		// an expired keepUntil is cleaned up like any other expiry
		$file = $this->trash()->root() . '/' . $trashId . '/meta.json';
		$meta = Data::read($file, 'json');
		$meta['keepUntil'] = date('c', time() - 3600);
		Data::write($file, $meta, 'json');
		$this->trash()->flushIndex();

		$this->assertSame(1, $this->trash()->cleanup());
		$this->assertCount(0, $this->trash()->items());
	}

	public function testPostponeDialog(): void
	{
		$this->createPage('note');
		$this->fresh()->page('note')->delete();

		$item    = $this->trash()->items()[0];
		$area    = (App::plugin('sigtrygg-space/kirby-trash')->extends()['areas']['trash'])($this->kirby);
		$dialogs = $area['dialogs'];

		$load = $dialogs['trash.postpone']['load']($item['trashId']);
		$this->assertSame('k-text-dialog', $load['component']);
		$this->assertStringContainsString('Note', $load['props']['text']);
		$this->assertStringContainsString('another 30 days', $load['props']['text']);

		$result = $dialogs['trash.postpone']['submit']($item['trashId']);
		$this->assertSame('Deletion postponed', $result['message']);

		$meta = Data::read($this->trash()->root() . '/' . $item['trashId'] . '/meta.json', 'json');
		$this->assertNotEmpty($meta['keepUntil']);
	}

	public function testPostponeUnavailableWithoutRetention(): void
	{
		$this->kirby = $this->app([
			'sigtrygg-space.kirby-trash.retentionDays' => -1,
		]);

		$this->createPage('note');
		$this->fresh()->page('note')->delete();

		$this->assertNull($this->trash()->postponeLabel());
		$this->assertFalse($this->trash()->panelItems()[0]['postponable']);

		$this->expectException(NotFoundException::class);
		$this->trash()->postpone($this->trash()->items()[0]['trashId']);
	}

	public function testPostponeRequiresDeletionDate(): void
	{
		$this->createPage('note');
		$this->fresh()->page('note')->delete();

		$trashId = $this->trash()->items()[0]['trashId'];
		$file    = $this->trash()->root() . '/' . $trashId . '/meta.json';
		$meta    = Data::read($file, 'json');
		unset($meta['deletedAt']);
		Data::write($file, $meta, 'json');
		$this->trash()->flushIndex();

		// without a deletion date there is nothing to postpone from
		$this->assertFalse($this->trash()->panelItems()[0]['postponable']);

		$this->expectException(NotFoundException::class);
		$this->trash()->postpone($trashId);
	}

	public function testCorruptDeletedAtDoesNotThrow(): void
	{
		$this->createPage('note');
		$this->fresh()->page('note')->delete();

		$trashId = $this->trash()->items()[0]['trashId'];
		$file    = $this->trash()->root() . '/' . $trashId . '/meta.json';
		$meta    = Data::read($file, 'json');
		$meta['deletedAt'] = ['not', 'a', 'string']; // corrupt meta
		Data::write($file, $meta, 'json');
		$this->trash()->flushIndex();

		// a non-string timestamp must not throw a TypeError anywhere
		$this->assertFalse($this->trash()->panelItems()[0]['postponable']);
		$this->assertSame(0, $this->trash()->cleanup());
		$this->assertNull($this->trash()->nextExpiry());

		$this->expectException(NotFoundException::class);
		$this->trash()->postpone($trashId);
	}

	public function testPostponeLabelPluralizes(): void
	{
		$this->assertSame('Keep for another 30 days', $this->trash()->postponeLabel());

		$this->kirby = $this->app([
			'sigtrygg-space.kirby-trash.retentionDays' => 1,
		]);
		$this->assertSame('Keep for one more day', $this->trash()->postponeLabel());
	}

	public function testBadgeTurnsRedWhenOnlyExpiredItemsRemain(): void
	{
		$this->createPage('fresh');
		$this->createPage('stale');
		$this->fresh()->page('fresh')->delete();
		$this->fresh()->page('stale')->delete();
		$this->backdateItem('stale', 40);

		// as long as live items remain, only those are counted
		$this->assertSame(['theme' => 'notice', 'text' => 1], $this->trash()->badge());

		// only expired items left: instead of disappearing (and
		// hiding the occupied disk space), the badge becomes a red
		// call to action showing their number
		$this->backdateItem('fresh', 40);
		$this->assertSame(['theme' => 'negative', 'text' => 2], $this->trash()->badge());
	}

	public function testAreaViewCleansUpAndReports(): void
	{
		$this->createPage('fresh');
		$this->createPage('stale');
		$this->fresh()->page('fresh')->delete();
		$this->fresh()->page('stale')->delete();
		$this->backdateItem('stale', 40);

		// building the area (as on every Panel request) must not
		// touch the trash — the closure runs before Kirby's
		// firewall, so it must not have side effects
		$area = (App::plugin('sigtrygg-space/kirby-trash')->extends()['areas']['trash'])($this->kirby);
		$this->assertCount(2, $this->trash()->items());

		// opening the view removes expired items and explains the
		// shrunken list to whoever followed the red badge here
		$props = $area['views'][0]['action']()['props'];
		$this->assertCount(1, $this->trash()->items());
		$this->assertSame('fresh', $this->trash()->items()[0]['id']);
		$this->assertSame(
			'1 expired item has just been removed by the automatic cleanup.',
			$props['cleaned']
		);

		// the next visit removes nothing and shows no note
		$props = $area['views'][0]['action']()['props'];
		$this->assertNull($props['cleaned']);
	}

	public function testMenuBadgeIsResolvedAfterTheViewAction(): void
	{
		$this->createPage('stale');
		$this->fresh()->page('stale')->delete();
		$this->backdateItem('stale', 40);

		$area = (App::plugin('sigtrygg-space/kirby-trash')->extends()['areas']['trash'])($this->kirby);

		// nothing but expired items: the red call to action
		$this->assertSame(
			['theme' => 'negative', 'text' => 1],
			$this->menuEntry($area)['badge']
		);

		// Kirby builds the areas before it calls the route action but
		// resolves the menu entries afterwards, so the badge of the
		// very response that ran the cleanup must be gone — not the
		// stale red one that invited the click in the first place
		$area['views'][0]['action']();

		$this->assertCount(0, $this->trash()->items());
		$this->assertArrayNotHasKey('badge', $this->menuEntry($area));
	}

	public function testEntriesWithoutMetaStayRemovableFromThePanel(): void
	{
		$this->createPage('note');
		$this->fresh()->page('note')->delete();

		// an interrupted deletion can leave the copied data behind
		// without a meta.json: invisible to items(), still on disk
		$trashId = $this->trash()->items()[0]['trashId'];
		F::remove($this->trash()->root() . '/' . $trashId . '/meta.json');
		$this->trash()->flushIndex();

		$this->assertCount(0, $this->trash()->items());
		$this->assertSame(1, $this->trash()->count());
		$this->assertGreaterThan(0, $this->trash()->totalSize());

		$area  = (App::plugin('sigtrygg-space/kirby-trash')->extends()['areas']['trash'])($this->kirby);
		$props = $area['views'][0]['action']()['props'];

		// the header button is gated on `total` rather than on the
		// table rows, so the one action that can remove such an entry
		// stays reachable instead of leaving it stuck on disk
		$this->assertCount(0, $props['items']);
		$this->assertSame(1, $props['total']);

		// ... and the view accounts for the entry instead of
		// claiming an empty trash right below an active button
		$this->assertStringContainsString('1 entry cannot be listed', $props['unlisted']);

		$dialogs = $area['dialogs'];
		$this->assertStringContainsString('1 item ', $dialogs['trash.empty']['load']()['props']['text']);
		$this->assertSame('The trash has been emptied', $dialogs['trash.empty']['submit']()['message']);
		$this->assertSame(0, $this->trash()->count());
	}

	public function testUnreadableSubfolderOnlyDropsItsOwnEntry(): void
	{
		$this->createPage('keeper');
		$this->fresh()->page('keeper')->delete();

		$root   = $this->trash()->root();
		$broken = $root . '/broken/data/sub';
		Dir::make($broken);
		F::write($broken . '/hidden.txt', 'x');
		chmod($broken, 0000);

		if (is_readable($broken) === true) {
			chmod($broken, 0755);
			$this->markTestSkipped('directory permissions are not enforced here');
		}

		try {
			$this->trash()->flushIndex();

			$keeper = $root . '/' . $this->trash()->items()[0]['trashId'];

			// Dir::size() recurses and throws somewhere inside
			// `broken`, but that must cost only its own bytes —
			// measuring the root in one call would return 0 here
			$this->assertSame(2, $this->trash()->count());
			$this->assertSame(Dir::size($keeper), $this->trash()->totalSize());
			$this->assertGreaterThan(0, $this->trash()->totalSize());
		} finally {
			chmod($broken, 0755);
		}
	}

	public function testUnlistedNoteCoversWhatTheTableCannotShow(): void
	{
		$this->createPage('one');
		$this->createPage('two');
		$this->fresh()->page('one')->delete();
		$this->fresh()->page('two')->delete();

		// everything on disk is listed: nothing to explain
		$this->assertNull($this->trash()->unlistedLabel());

		$break = function (): void {
			$root = $this->trash()->root() . '/' . $this->trash()->items()[0]['trashId'];
			F::remove($root . '/meta.json');
			$this->trash()->flushIndex();
		};

		// with one of the two broken the table still renders, so the
		// note is the only thing accounting for the rest of the badge
		$break();
		$this->assertCount(1, $this->trash()->items());
		$this->assertSame(2, $this->trash()->count());
		$this->assertStringContainsString(
			'1 entry cannot be listed',
			$this->trash()->unlistedLabel()
		);

		$break();
		$this->assertStringContainsString(
			'2 entries cannot be listed',
			$this->trash()->unlistedLabel()
		);
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
		$this->assertSame(
			['theme' => 'notice', 'text' => 1],
			$this->menuEntry($area)['badge']
		);

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
		$this->assertSame('orange', $this->trash()->badge()['theme']);

		// the column definition carries cell type and warn theme
		$columns = $this->trash()->panelColumns();
		$this->assertSame('remaining', $columns['remaining']['type']);
		$this->assertSame('orange', $columns['remaining']['warnTheme']);
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
		// (badge() is a pure getter — it does not delete anything)
		$this->assertSame(1, $trash->badge()['text']);
		$this->assertSame('notice', $trash->badge()['theme']);
		$this->assertFalse($trash->expiresSoon());
		$rows = array_column($trash->panelItems(), null, 'path');
		$this->assertFalse($rows['note']['expiresSoon']);

		// both expired: no future expiry, no warn state — the badge
		// turns into the red cleanup call to action instead
		$this->backdateItem('other', 40);
		$this->assertNull($this->trash()->nextExpiry());
		$this->assertFalse($this->trash()->expiresSoon());
		$this->assertSame(['theme' => 'negative', 'text' => 2], $this->trash()->badge());
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

	public function testImageFilePreviewsRenderAsJpegThumbs(): void
	{
		$this->requireGd();

		$page = $this->createPage('note');
		$this->createImageFile($page, 'photo.png');
		$this->fresh()->page('note')->file('photo.png')->delete();

		// the row links its image object to the preview route
		$row = $this->trash()->panelItems()[0];
		$this->assertSame(
			$this->kirby->url('panel') . '/trash/preview/' . $row['trashId'],
			$row['image']['src']
		);
		$this->assertTrue($row['image']['cover']);

		// the request route streams a JPEG regardless of the source
		// format and caches the thumb below the cache root
		$area     = (App::plugin('sigtrygg-space/kirby-trash')->extends()['areas']['trash'])($this->kirby);
		$response = $this->previewAction($area)($row['trashId']);

		$this->assertInstanceOf(Response::class, $response);
		$this->assertSame('image/jpeg', $response->type());

		$thumb = $this->trash()->previewRoot() . '/' . $row['trashId'] . '-fit.jpg';
		$this->assertFileExists($thumb);

		// not cropped: the 64x48 source keeps its 4:3 aspect ratio
		// (the list's square cells crop via CSS instead); integer
		// cross-multiplication avoids float comparison entirely
		$size = getimagesize($thumb);
		$this->assertSame('image/jpeg', $size['mime']);
		$this->assertSame($size[0] * 3, $size[1] * 4);

		// the details dialog receives the native ratio for its frame
		$this->assertSame('64/48', $this->trash()->previewRatio($row['trashId']));
		$load = $area['dialogs']['trash.details']['load']($row['trashId']);
		$this->assertSame('64/48', $load['props']['ratio']);
	}

	public function testNonImageItemsFallBackToTypedIcons(): void
	{
		$page = $this->createPage('note');
		$this->createFile($page, 'notes.md');
		// 2-4 character filenames trip F::type()'s literal-extension
		// heuristic when passed verbatim — the icon must not care
		$this->createFile($page, 'a.md');
		$this->fresh()->page('note')->file('notes.md')->delete();
		$this->fresh()->page('note')->file('a.md')->delete();
		$this->fresh()->page('note')->delete();

		$rows = array_column($this->trash()->panelItems(), 'image', 'title');

		// pages get the page icon, files the same type-based icons
		// as Kirby's own file panels; neither carries a `src`
		$this->assertSame('page', $rows['Note']['icon']);
		$this->assertSame('document', $rows['notes.md']['icon']);
		$this->assertSame('document', $rows['a.md']['icon']);
		$this->assertArrayNotHasKey('src', $rows['Note']);
		$this->assertArrayNotHasKey('src', $rows['notes.md']);

		// the preview endpoint refuses items without a previewable image
		$trashId = $this->trash()->items()[0]['trashId'];
		$this->expectException(NotFoundException::class);
		$this->trash()->preview($trashId);
	}

	public function testPreviewSniffsContentBeforeStreaming(): void
	{
		$this->requireGd();

		$page = $this->createPage('note');
		$this->createImageFile($page, 'photo.png');
		$this->fresh()->page('note')->file('photo.png')->delete();

		// swap the trashed payload for a text file wearing a .png
		// extension: the listing may still trust the mime recorded
		// at trash time, but the endpoint re-sniffs the actual bytes
		// and refuses to feed them to the thumb driver
		$trashId = $this->trash()->items()[0]['trashId'];
		F::write($this->trash()->root() . '/' . $trashId . '/data/photo.png', 'not an image');
		$this->trash()->flushIndex();

		$this->expectException(NotFoundException::class);
		$this->trash()->preview($trashId);
	}

	public function testListingUsesStoredMimeAndSniffsLegacyItems(): void
	{
		$this->requireGd();

		$page = $this->createPage('note');
		$this->createImageFile($page, 'photo.png');
		$this->fresh()->page('note')->file('photo.png')->delete();

		// freshly trashed items carry the mime sniffed at trash time
		$trashId = $this->trash()->items()[0]['trashId'];
		$file    = $this->trash()->root() . '/' . $trashId . '/meta.json';
		$meta    = Data::read($file, 'json');
		$this->assertSame('image/png', $meta['mime']);

		// items trashed before the mime field existed fall back to
		// sniffing the payload while building the list
		unset($meta['mime']);
		Data::write($file, $meta, 'json');
		$this->trash()->flushIndex();

		$this->assertArrayHasKey('src', $this->trash()->panelItems()[0]['image']);
	}

	public function testCorruptRelativePathDoesNotBreakTheListing(): void
	{
		$page = $this->createPage('note');
		$this->createFile($page, 'notes.md');
		$this->fresh()->page('note')->file('notes.md')->delete();

		$trashId = $this->trash()->items()[0]['trashId'];
		$file    = $this->trash()->root() . '/' . $trashId . '/meta.json';
		$meta    = Data::read($file, 'json');
		$meta['relativePath'] = ['not', 'a', 'string']; // corrupt meta
		Data::write($file, $meta, 'json');
		$this->trash()->flushIndex();

		// one corrupted entry must degrade to the generic icon,
		// not take the whole listing down with a TypeError
		$row = $this->trash()->panelItems()[0];
		$this->assertArrayNotHasKey('src', $row['image']);
		$this->assertSame('file', $row['image']['icon']);

		$this->expectException(NotFoundException::class);
		$this->trash()->preview($trashId);
	}

	public function testPageCoversPreviewInListAndDialog(): void
	{
		$this->requireGd();

		$page = $this->createPage('gallery');
		$this->createImageFile($page, 'photo.png');
		$this->fresh()->page('gallery')->delete(true);

		// the trashed page records its first image as cover …
		$row  = $this->trash()->panelItems()[0];
		$meta = Data::read($this->trash()->root() . '/' . $row['trashId'] . '/meta.json', 'json');
		$this->assertSame('photo.png', $meta['cover']);
		$this->assertSame('image/png', $meta['coverMime']);

		// … which drives the list thumbnail and the endpoint
		$this->assertSame(
			$this->kirby->url('panel') . '/trash/preview/' . $row['trashId'],
			$row['image']['src']
		);
		$this->assertSame('image/jpeg', $this->trash()->preview($row['trashId'])->type());

		// the details dialog receives the same image object for
		// its large preview
		$area = (App::plugin('sigtrygg-space/kirby-trash')->extends()['areas']['trash'])($this->kirby);
		$load = $area['dialogs']['trash.details']['load']($row['trashId']);
		$this->assertSame($row['image']['src'], $load['props']['image']['src']);
	}

	public function testPageCoverScanIsShallowAndLazyForLegacyItems(): void
	{
		$this->requireGd();

		// the image lives in a child page: the shallow scan must
		// not dig into the copied tree, so no cover is recorded
		$parent = $this->createPage('parent');
		$child  = $this->createPage('child', parent: $parent);
		$this->createImageFile($child, 'photo.png');
		$this->fresh()->page('parent')->delete(true);

		$row = $this->trash()->panelItems()[0];
		$this->assertArrayNotHasKey('src', $row['image']);
		$this->assertSame('page', $row['image']['icon']);

		// legacy items (trashed before covers were recorded) have
		// no cover key at all and scan their folder lazily
		$this->trash()->restore($row['trashId']);
		$this->createImageFile($this->fresh()->page('parent'), 'cover.png');
		$this->fresh()->page('parent')->delete(true);

		$trashId = $this->trash()->items()[0]['trashId'];
		$file    = $this->trash()->root() . '/' . $trashId . '/meta.json';
		$meta    = Data::read($file, 'json');
		unset($meta['cover'], $meta['coverMime']);
		Data::write($file, $meta, 'json');
		$this->trash()->flushIndex();

		$this->assertArrayHasKey('src', $this->trash()->panelItems()[0]['image']);
	}

	public function testCraftedCoverMetaIsRejected(): void
	{
		$this->requireGd();

		$page = $this->createPage('gallery');
		$this->createImageFile($page, 'photo.png');
		$this->fresh()->page('gallery')->delete(true);

		// a hand-edited cover must not escape the item's data folder
		$trashId = $this->trash()->items()[0]['trashId'];
		$file    = $this->trash()->root() . '/' . $trashId . '/meta.json';
		$meta    = Data::read($file, 'json');
		$meta['cover'] = '../../../etc/passwd';
		Data::write($file, $meta, 'json');
		$this->trash()->flushIndex();

		$this->assertArrayNotHasKey('src', $this->trash()->panelItems()[0]['image']);

		$this->expectException(NotFoundException::class);
		$this->trash()->preview($trashId);
	}

	public function testRowHeightRidesOnThePreviewsOption(): void
	{
		$this->requireGd();

		$page = $this->createPage('note');
		$this->createImageFile($page, 'photo.png');
		$this->fresh()->page('note')->file('photo.png')->delete();

		// booleans only switch previews, the height stays standard
		$this->assertNull($this->trash()->rowHeight());

		// numbers are treated as px, length strings pass verbatim —
		// both keep previews enabled
		foreach ([56 => '56px', '3.5rem' => '3.5rem'] as $option => $expected) {
			$this->kirby = $this->app([
				'sigtrygg-space.kirby-trash.previews' => $option,
			]);
			$this->assertSame($expected, $this->trash()->rowHeight());
			$this->assertArrayHasKey('src', $this->trash()->panelItems()[0]['image']);
		}

		// anything that is not a plain positive px/rem/em length is
		// discarded — the value ends up in a style attribute
		foreach ([false, 0, -20, '0px', '0.0rem', '50%', 'calc(2rem + 1px)', 'red;background:url(x)'] as $option) {
			$this->kirby = $this->app([
				'sigtrygg-space.kirby-trash.previews' => $option,
			]);
			$this->assertNull($this->trash()->rowHeight());
		}

		// the view hands the validated value to the table
		$this->kirby = $this->app([
			'sigtrygg-space.kirby-trash.previews' => '3rem',
		]);
		$area  = (App::plugin('sigtrygg-space/kirby-trash')->extends()['areas']['trash'])($this->kirby);
		$props = $area['views'][0]['action']()['props'];
		$this->assertSame('3rem', $props['rowHeight']);
	}

	public function testPreviewGenerationFailureDegradesToNotFound(): void
	{
		$this->requireGd();

		$page = $this->createPage('note');
		$this->createImageFile($page, 'photo.png');
		$this->fresh()->page('note')->file('photo.png')->delete();

		// block the previews folder with a file: generation cannot
		// write, and the endpoint must answer with not-found instead
		// of leaking the underlying filesystem error
		$root = $this->trash()->previewRoot();
		Dir::make(dirname($root));
		F::write($root, 'not a directory');

		$this->expectException(NotFoundException::class);
		$this->trash()->preview($this->trash()->items()[0]['trashId']);
	}

	public function testPreviewsCanBeDisabled(): void
	{
		$this->requireGd();

		$page = $this->createPage('note');
		$this->createImageFile($page, 'photo.png');
		$this->fresh()->page('note')->file('photo.png')->delete();

		$this->kirby = $this->app([
			'sigtrygg-space.kirby-trash.previews' => false,
		]);

		// with previews disabled, even image files fall back to
		// the icon and the endpoint refuses to stream
		$row = $this->trash()->panelItems()[0];
		$this->assertArrayNotHasKey('src', $row['image']);
		$this->assertSame('image', $row['image']['icon']);

		$this->expectException(NotFoundException::class);
		$this->trash()->preview($row['trashId']);
	}

	public function testPreviewsAreCleanedUpWithTheirItems(): void
	{
		$this->requireGd();

		$thumbFor = function (): string {
			$trashId = $this->trash()->items()[0]['trashId'];
			$this->trash()->preview($trashId);
			$thumb = $this->trash()->previewRoot() . '/' . $trashId . '-fit.jpg';
			$this->assertFileExists($thumb);

			// a leftover square crop from plugin versions up to 0.5.0
			// has to be cleaned up alongside the item as well
			F::write($this->trash()->previewRoot() . '/' . $trashId . '.jpg', 'legacy');

			return $thumb;
		};

		// restore removes the thumb alongside the item
		$page = $this->createPage('note');
		$this->createImageFile($page, 'photo.png');
		$this->fresh()->page('note')->file('photo.png')->delete();
		$trashId = $this->trash()->items()[0]['trashId'];
		$thumb   = $thumbFor();
		$this->trash()->restore($trashId);
		$this->assertFileDoesNotExist($thumb);
		$this->assertFileDoesNotExist($this->trash()->previewRoot() . '/' . $trashId . '.jpg');

		// delete removes it too (and thereby cleanup, which deletes)
		$this->fresh()->page('note')->file('photo.png')->delete();
		$trashId = $this->trash()->items()[0]['trashId'];
		$thumb   = $thumbFor();
		$this->trash()->delete($trashId);
		$this->assertFileDoesNotExist($thumb);
		$this->assertFileDoesNotExist($this->trash()->previewRoot() . '/' . $trashId . '.jpg');

		// emptying the trash drops the whole preview folder
		$this->createImageFile($this->fresh()->page('note'), 'other.png');
		$this->fresh()->page('note')->file('other.png')->delete();
		$thumbFor();
		$this->trash()->emptyTrash();
		$this->assertDirectoryDoesNotExist($this->trash()->previewRoot());
	}

	/**
	 * The trash menu entry, resolved the way Kirby resolves it on
	 * every Panel request: through Panel\Menu, which runs after the
	 * route action. Panel\Menu::entry() drops empty values, so a
	 * missing badge means no `badge` key at all.
	 */
	protected function menuEntry(array $area): array
	{
		$menu = new Menu(['trash' => Panel::area('trash', $area)]);

		foreach ($menu->entries() as $entry) {
			if (is_array($entry) === true && ($entry['link'] ?? null) === 'trash') {
				return $entry;
			}
		}

		$this->fail('the trash menu entry is missing');
	}

	protected function requireGd(): void
	{
		if (extension_loaded('gd') === false) {
			$this->markTestSkipped('the GD extension is not available');
		}
	}

	/**
	 * The preview request route's action, selected by pattern so
	 * tests don't depend on the order of the area's request routes
	 */
	protected function previewAction(array $area): Closure
	{
		foreach ($area['requests'] as $route) {
			if (($route['pattern'] ?? null) === 'trash/preview/(:any)') {
				return $route['action'];
			}
		}

		$this->fail('the preview request route is missing');
	}

	/**
	 * A real PNG, so the content sniff of the preview
	 * pipeline has something honest to detect
	 */
	protected function createImageFile(Page $page, string $filename): File
	{
		$source = $this->tmp . '/' . $filename;
		$image  = imagecreatetruecolor(64, 48);
		imagefill($image, 0, 0, imagecolorallocate($image, 40, 120, 200));
		imagepng($image, $source);
		imagedestroy($image);

		return File::create([
			'source'   => $source,
			'parent'   => $page,
			'filename' => $filename,
		]);
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
