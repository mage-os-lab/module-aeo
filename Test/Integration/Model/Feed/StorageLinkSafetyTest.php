<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Integration\Model\Feed;

// phpcs:disable Magento2.Functions.DiscouragedFunction -- the test builds links and checks the disk directly

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Aeo\Model\Config;
use MageOS\Aeo\Model\Feed\FeedStorage;
use MageOS\Aeo\Model\Feed\StorageDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A symbolic link in feed storage never takes a read, a write or a deletion outside it (issue #2).
 *
 * The reviewer's four probes on the real filesystem, with nested and leaf links, a link in place of
 * the storage directory itself, and a store directory replaced by a link while a file is written.
 * They run on the default storage directory (var/mageos_aeo) and on a configured one. Every link
 * points into a directory outside storage, whose files must be there, unchanged, afterwards.
 *
 * Store IDs from 9000 up and 999 (the reviewer's orphan) keep away from real store views' files.
 *
 * @magentoAppArea adminhtml
 */
class StorageLinkSafetyTest extends TestCase
{
    /**
     * A directory outside storage that the links point into.
     *
     * @var string|null
     */
    private ?string $outside = null;

    /**
     * A configured storage directory inside var/, for the tests that use one.
     *
     * @var string|null
     */
    private ?string $configuredRoot = null;

    /**
     * Paths the test created, removed afterwards without following links.
     *
     * @var string[]|null
     */
    private ?array $created = [];

    /**
     * Where var/mageos_aeo was moved while a link stood in for it.
     *
     * @var string|null
     */
    private ?string $movedBase = null;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->outside = $this->track(sys_get_temp_dir() . '/mageos-aeo-outside-' . bin2hex(random_bytes(4)));
        mkdir($this->outside . '/nested', 0o750, true);
        file_put_contents($this->outside . '/secret.txt', 'outside');
        file_put_contents($this->outside . '/nested/deeper.txt', 'nested');
    }

    /**
     * Remove what the test created and put var/mageos_aeo back if it was moved.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if ($this->movedBase !== null) {
            $base = $this->varDirectory() . '/mageos_aeo';
            if (is_link($base)) {
                unlink($base);
            }
            rename($this->movedBase, $base);
            $this->movedBase = null;
        }

        foreach (array_reverse($this->created ?? []) as $path) {
            $this->remove($path);
        }
        $this->created        = [];
        $this->outside        = null;
        $this->configuredRoot = null;
    }

    /**
     * Each probe runs on the default storage directory and on a configured one.
     *
     * @return array<string, array{bool}>
     */
    public static function roots(): array
    {
        return [
            'default storage'    => [false],
            'configured storage' => [true],
        ];
    }

    /**
     * Probe 1: a feed file that is a link to a file outside storage is not read.
     *
     * @dataProvider roots
     * @param bool $configured
     * @return void
     */
    #[DataProvider('roots')]
    public function testALinkedFeedFileIsNotRead(bool $configured): void
    {
        $directory = $this->storeDirectory($configured, 9001);
        symlink($this->outside . '/secret.txt', $directory . '/llms.txt');

        $this->assertNull($this->storage($configured)->read('llms.txt', 9001));
        $this->assertOutsideUntouched();
    }

    /**
     * Probe 2: a store directory that is a link to a directory outside storage takes no write.
     *
     * @dataProvider roots
     * @param bool $configured
     * @return void
     */
    #[DataProvider('roots')]
    public function testALinkedStoreDirectoryTakesNoWrite(bool $configured): void
    {
        $link = $this->track($this->root($configured) . '/store_9002');
        symlink($this->outside, $link);

        $this->assertRefused(fn () => $this->storage($configured)->write('llms.txt', 9002, 'feed'));
        $this->assertOutsideUntouched();
    }

    /**
     * Probe 3: an orphaned store directory that is a link is listed for the cleanup, and deleting
     * it removes the link, not what it points at.
     *
     * @dataProvider roots
     * @param bool $configured
     * @return void
     */
    #[DataProvider('roots')]
    public function testDeletingALinkedOrphanRemovesOnlyTheLink(bool $configured): void
    {
        $link = $this->track($this->root($configured) . '/store_999');
        symlink($this->outside, $link);
        $storage = $this->storage($configured);
        $this->assertContains(999, $storage->listStoreDirectories());

        $storage->deleteStoreDirectory(999);

        $this->assertFalse(is_link($link), 'The link is removed.');
        $this->assertOutsideUntouched();
    }

    /**
     * A link inside a store directory is removed with it, never followed.
     *
     * @dataProvider roots
     * @param bool $configured
     * @return void
     */
    #[DataProvider('roots')]
    public function testANestedLinkIsNotFollowedWhenAStoreDirectoryIsDeleted(bool $configured): void
    {
        $directory = $this->storeDirectory($configured, 9003);
        file_put_contents($directory . '/llms.txt', 'feed');
        symlink($this->outside, $directory . '/inner');

        $this->storage($configured)->deleteStoreDirectory(9003);

        $this->assertFileDoesNotExist($directory, 'The store directory is removed.');
        $this->assertOutsideUntouched();
    }

    /**
     * A feed file that is a link is replaced by the written file and deleted as a link, so its
     * target is never written to or removed.
     *
     * @dataProvider roots
     * @param bool $configured
     * @return void
     */
    #[DataProvider('roots')]
    public function testALeafLinkIsReplacedOrRemovedButNeverFollowed(bool $configured): void
    {
        $directory = $this->storeDirectory($configured, 9004);
        symlink($this->outside . '/secret.txt', $directory . '/llms.txt');
        symlink($this->outside . '/secret.txt', $directory . '/llms-full.txt');
        $storage = $this->storage($configured);

        $storage->write('llms.txt', 9004, 'feed');
        $storage->deleteForStore('llms-full.txt', 9004);

        $this->assertFalse(is_link($directory . '/llms.txt'), 'The link was replaced.');
        $this->assertSame('feed', $storage->read('llms.txt', 9004));
        $this->assertFalse(is_link($directory . '/llms-full.txt'), 'The link was removed.');
        $this->assertOutsideUntouched();
    }

    /**
     * A store directory swapped for a link while a file is being written: the finished file is not
     * renamed into the link's target, and the temporary file there is not deleted.
     *
     * @dataProvider roots
     * @param bool $configured
     * @return void
     */
    #[DataProvider('roots')]
    public function testAStoreDirectoryReplacedByALinkDuringAWriteTakesNothingOutside(bool $configured): void
    {
        $directory = $this->storeDirectory($configured, 9005);
        $writer    = $this->storage($configured)->openForWrite(9005);
        $writer->write('feed');
        $temporary = $this->temporaryFileIn($directory);

        // The swap: the real directory moves away, and the link points at a copy of the temporary
        // file outside storage.
        rename($directory, $this->track($directory . '.moved'));
        copy($directory . '.moved/' . $temporary, $this->outside . '/' . $temporary);
        symlink($this->outside, $directory);

        $refused = false;
        try {
            $writer->commit('llms.txt');
        } catch (\Exception) {
            $refused = true;
            $writer->discard();
        }

        $this->assertTrue($refused, 'The commit through a swapped store directory was refused.');
        $this->assertFileDoesNotExist($this->outside . '/llms.txt', 'Nothing was written outside storage.');
        $this->assertFileExists($this->outside . '/' . $temporary, 'Nothing outside storage was deleted.');
    }

    /**
     * A world-writable store directory is not read: anyone could have put the file there.
     *
     * @dataProvider roots
     * @param bool $configured
     * @return void
     */
    #[DataProvider('roots')]
    public function testAStoreDirectoryEveryUserCanWriteToIsNotRead(bool $configured): void
    {
        $directory = $this->storeDirectory($configured, 9006);
        file_put_contents($directory . '/llms.txt', 'feed');
        chmod($directory, 0o777);

        $this->assertNull($this->storage($configured)->read('llms.txt', 9006));
    }

    /**
     * A link in place of var/mageos_aeo is neither read, written, listed nor deleted through.
     *
     * @return void
     */
    public function testALinkedDefaultStorageDirectoryIsNotUsed(): void
    {
        $base = $this->root(false);
        $this->movedBase = $base . '.moved-' . bin2hex(random_bytes(4));
        rename($base, $this->movedBase);
        mkdir($this->outside . '/store_9007');
        file_put_contents($this->outside . '/store_9007/llms.txt', 'outside');
        symlink($this->outside, $base);
        $storage = $this->storage(false);

        $this->assertNull($storage->read('llms.txt', 9007));
        $this->assertSame([], $storage->listStoreDirectories());
        $this->assertRefused(fn () => $storage->write('llms.txt', 9008, 'feed'));
        $storage->deleteForStore('llms*', 9007);
        $storage->deleteStoreDirectory(9007);

        $this->assertSame('outside', file_get_contents($this->outside . '/store_9007/llms.txt'));
        $this->assertDirectoryDoesNotExist($this->outside . '/store_9008');
        $this->assertOutsideUntouched();
    }

    /**
     * Probe 4: a configured directory whose visible name links to a hidden one is refused, and the
     * feeds stay in var/mageos_aeo.
     *
     * @return void
     */
    public function testAVisibleAliasOfAHiddenDirectoryIsRefused(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $hidden = $this->track($this->varDirectory() . '/.mageos-aeo-hidden-' . $suffix);
        $alias  = $this->track($this->varDirectory() . '/mageos-aeo-alias-' . $suffix);
        mkdir($hidden, 0o750);
        symlink($hidden, $alias);
        $this->track($this->root(false) . '/store_9009');

        $this->assertFalse(Bootstrap::getObjectManager()->get(StorageDirectory::class)->isAllowed($alias));

        $this->storage(false, $alias)->write('llms.txt', 9009, 'feed');

        $this->assertSame(['.', '..'], scandir($hidden), 'Nothing was written to the hidden directory.');
        $this->assertSame('feed', file_get_contents($this->root(false) . '/store_9009/llms.txt'));
    }

    /**
     * Assert that a storage operation was refused with an exception.
     *
     * @param callable $operation
     * @return void
     */
    private function assertRefused(callable $operation): void
    {
        try {
            $operation();
        } catch (\Exception) {
            return;
        }

        $this->fail('The operation was not refused.');
    }

    /**
     * Assert that the outside directory holds exactly what setUp() put there.
     *
     * @return void
     */
    private function assertOutsideUntouched(): void
    {
        foreach (['secret.txt' => 'outside', 'nested/deeper.txt' => 'nested'] as $file => $content) {
            $this->assertFileExists($this->outside . '/' . $file);
            $this->assertSame($content, file_get_contents($this->outside . '/' . $file), $file);
        }
        $this->assertFileDoesNotExist($this->outside . '/llms.txt', 'No feed file was written outside storage.');
    }

    /**
     * Feed storage on the default directory, or on the configured test directory.
     *
     * @param bool $configured
     * @param string|null $directory A configured directory other than the test's own
     * @return FeedStorage
     */
    private function storage(bool $configured, ?string $directory = null): FeedStorage
    {
        $config = $this->createStub(Config::class);
        $config->method('getFeedStorageDir')->willReturn(
            $directory ?? ($configured ? $this->root(true) : '')
        );

        return Bootstrap::getObjectManager()->create(FeedStorage::class, ['aeoConfig' => $config]);
    }

    /**
     * The storage directory: var/mageos_aeo, or a configured directory inside var/.
     *
     * @param bool $configured
     * @return string
     */
    private function root(bool $configured): string
    {
        if (!$configured) {
            $base = $this->varDirectory() . '/mageos_aeo';
            if (!is_dir($base)) {
                mkdir($base, 0o750);
            }

            return $base;
        }

        if ($this->configuredRoot === null) {
            $this->configuredRoot = $this->track(
                $this->varDirectory() . '/mageos-aeo-root-' . bin2hex(random_bytes(4))
            );
            mkdir($this->configuredRoot, 0o750);
        }

        return $this->configuredRoot;
    }

    /**
     * Create a real store directory in the storage directory.
     *
     * @param bool $configured
     * @param int $storeId
     * @return string
     */
    private function storeDirectory(bool $configured, int $storeId): string
    {
        $directory = $this->track($this->root($configured) . '/store_' . $storeId);
        mkdir($directory, 0o750);

        return $directory;
    }

    /**
     * The name of the one temporary file in a store directory.
     *
     * @param string $directory
     * @return string
     */
    private function temporaryFileIn(string $directory): string
    {
        $temporary = array_values(array_filter(
            (array) scandir($directory),
            static fn ($name): bool => (bool) preg_match('/^\..+\.tmp$/', (string) $name)
        ));
        $this->assertCount(1, $temporary, 'The writer has one temporary file.');

        return (string) $temporary[0];
    }

    /**
     * The sandbox's var/, resolved.
     *
     * @return string
     */
    private function varDirectory(): string
    {
        return (string) realpath(
            Bootstrap::getObjectManager()->get(DirectoryList::class)->getPath(DirectoryList::VAR_DIR)
        );
    }

    /**
     * Remember a path to remove afterwards.
     *
     * @param string $path
     * @return string
     */
    private function track(string $path): string
    {
        $this->created[] = $path;

        return $path;
    }

    /**
     * Remove a path: a link or file is unlinked, a real directory emptied first. Links are never
     * followed, so cleaning up cannot reach outside what the test made.
     *
     * @param string $path
     * @return void
     */
    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        chmod($path, 0o750);
        foreach ((array) scandir($path) as $name) {
            if ($name !== '.' && $name !== '..') {
                $this->remove($path . '/' . $name);
            }
        }
        rmdir($path);
    }
}
