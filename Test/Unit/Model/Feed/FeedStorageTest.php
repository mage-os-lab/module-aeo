<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Unit\Model\Feed;

// phpcs:disable Magento2.Functions.DiscouragedFunction -- the test checks the disk directly

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Framework\Phrase;
use MageOS\Aeo\Exception\FeedStorageUnavailableException;
use MageOS\Aeo\Model\Config;
use MageOS\Aeo\Model\Feed\FeedStorage;
use MageOS\Aeo\Model\Feed\LinkSafeFilesystem;
use MageOS\Aeo\Model\Feed\StorageDirectory;
use MageOS\Seo\Model\Rebuild\ProblemLog;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Where feed files go and how they are replaced, on real directories.
 *
 * What makes a configured directory permitted is StorageDirectoryTest's subject; what a link does
 * to each operation is LinkSafeFilesystemTest's and the integration StorageLinkSafetyTest's.
 */
class FeedStorageTest extends TestCase
{
    /**
     * The test's var/.
     *
     * @var string
     */
    private string $var = '';

    /**
     * A configured storage directory, outside var/.
     *
     * @var string
     */
    private string $custom = '';

    protected function setUp(): void
    {
        $base         = (string) realpath((string) sys_get_temp_dir()) . '/mageos-aeo-storage-' . uniqid();
        $this->var    = $base . '/var';
        $this->custom = $base . '/shared/feeds';
        mkdir($this->var, 0o750, true);
        mkdir($this->custom, 0o750, true);
    }

    protected function tearDown(): void
    {
        (new FileDriver())->deleteDirectory(\dirname($this->var));
    }

    public function testWriteReplacesTheFileThroughATemporaryFileInTheSameDirectory(): void
    {
        $storage = $this->storage();
        $storage->write('llms.txt', 1, 'first');
        $writer = $storage->openForWrite(1);
        $writer->write('second');

        $temporary = array_values(array_filter(
            (array) scandir($this->var . '/mageos_aeo/store_1'),
            static fn ($name): bool => (bool) preg_match('/^\.[0-9a-f]{12}\.tmp$/', (string) $name)
        ));
        $this->assertCount(1, $temporary, 'The new content is in a hidden temporary file.');
        $this->assertSame('first', $storage->read('llms.txt', 1), 'The served file is the old one until the commit.');

        $writer->commit('llms.txt');

        $this->assertSame('second', $storage->read('llms.txt', 1));
        $this->assertSame(['.', '..', 'llms.txt'], scandir($this->var . '/mageos_aeo/store_1'));
    }

    public function testDirectoriesAndFilesGetTheFeedModes(): void
    {
        mkdir($this->var . '/mageos_aeo', 0o777);
        chmod($this->var . '/mageos_aeo', 0o777);

        $this->storage()->write('llms.txt', 1, 'feed');

        $this->assertSame(0o750, fileperms($this->var . '/mageos_aeo') & 0o777, 'An existing directory converges.');
        $this->assertSame(0o750, fileperms($this->var . '/mageos_aeo/store_1') & 0o777);
        $this->assertSame(0o640, fileperms($this->var . '/mageos_aeo/store_1/llms.txt') & 0o777);
    }

    public function testAConfiguredDirectoryIsUsedWithoutThePrefixAndKeepsItsOwnMode(): void
    {
        chmod($this->custom, 0o770);

        $this->storage($this->custom)->write('llms.txt', 1, 'feed');

        $this->assertSame('feed', file_get_contents($this->custom . '/store_1/llms.txt'));
        $this->assertSame(0o770, fileperms($this->custom) & 0o777);
        $this->assertDirectoryDoesNotExist($this->var . '/mageos_aeo');
    }

    public function testADirectoryTheInstallationDoesNotPermitIsIgnored(): void
    {
        // The admin field is validated on save, but a configuration row can arrive by other
        // routes — a data patch, a deployment tool, a direct database write — so the value is
        // checked again here. Refusing it falls back to var/mageos_aeo rather than failing.
        $this->storage('/etc', false)->write('llms.txt', 1, 'feed');

        $this->assertSame('feed', file_get_contents($this->var . '/mageos_aeo/store_1/llms.txt'));
    }

    public function testFallingBackFromARefusedDirectoryIsReportedToTheRebuildsUnderWay(): void
    {
        // On a multi-server install the web servers may not see this host's var/, so the admin
        // is told rather than only the log.
        $problemLog = $this->createMock(ProblemLog::class);
        $problemLog->expects($this->atLeastOnce())->method('degradedWhileRebuilding')
            ->with($this->callback(
                static fn (Phrase $reason): bool => str_contains($reason->getText(), 'var/mageos_aeo')
            ));

        $this->storage('/etc', false, $problemLog)->deleteForStore('llms.txt', 1);
    }

    public function testAPermittedDirectoryReportsNothing(): void
    {
        $problemLog = $this->createMock(ProblemLog::class);
        $problemLog->expects($this->never())->method('degradedWhileRebuilding');

        $this->storage($this->custom, true, $problemLog)->deleteForStore('llms.txt', 1);
    }

    public function testAFailedWriteRemovesTheTemporaryFileRethrowsAndLeavesTheServedFile(): void
    {
        $storage = $this->storage();
        $storage->write('llms.txt', 1, 'served');
        // Renaming a file over a directory fails.
        mkdir($this->var . '/mageos_aeo/store_1/llms-full.txt');

        try {
            $storage->write('llms-full.txt', 1, 'new');
            $this->fail('The failed rename was not reported.');
        } catch (\Magento\Framework\Exception\FileSystemException) {
            // Reported.
        }

        $this->assertSame(['.', '..', 'llms-full.txt', 'llms.txt'], scandir($this->var . '/mageos_aeo/store_1'));
        $this->assertSame('served', $storage->read('llms.txt', 1));
    }

    public function testReadIsNullWithoutAFile(): void
    {
        $this->assertNull($this->storage()->read('llms.txt', 1), 'no storage directory yet');

        $this->storage()->write('llms.txt', 1, 'feed');
        $this->assertNull($this->storage()->read('llms-full.txt', 1), 'no such file');
    }

    public function testOpenGivesTheFileAndItsSize(): void
    {
        $this->storage()->write('llms.jsonl', 1, "{}\n{}\n");

        $file = $this->storage()->open('llms.jsonl', 1);

        $this->assertNotNull($file);
        $this->assertSame(6, $file->size());
        $this->assertSame("{}\n{}\n", $file->contents());
    }

    public function testDeleteForStoreScopesTheGlobToOneStore(): void
    {
        $storage = $this->storage();
        foreach (['llms.txt', 'llms-full.txt', 'llms.jsonl'] as $file) {
            $storage->write($file, 2, 'feed');
            $storage->write($file, 3, 'feed');
        }

        $storage->deleteForStore('llms*.txt', 2);

        $this->assertSame(['.', '..', 'llms.jsonl'], scandir($this->var . '/mageos_aeo/store_2'));
        $this->assertCount(5, (array) scandir($this->var . '/mageos_aeo/store_3'), 'Another store keeps its files.');
    }

    public function testListStoreDirectoriesReturnsTheStoreIdsThatHaveOne(): void
    {
        $this->assertSame([], $this->storage()->listStoreDirectories(), 'no storage directory yet');

        mkdir($this->var . '/mageos_aeo/store_12', 0o750, true);
        mkdir($this->var . '/mageos_aeo/store_1');
        // Anything that is not a store directory is ignored.
        mkdir($this->var . '/mageos_aeo/store_notanumber');
        mkdir($this->var . '/mageos_aeo/store_7.moved');
        mkdir($this->var . '/mageos_aeo/old_store_8');
        file_put_contents($this->var . '/mageos_aeo/README.md', 'x');

        $this->assertSame([1, 12], $this->storage()->listStoreDirectories());
    }

    public function testDeleteStoreDirectoryRemovesOnlyThatStoresDirectory(): void
    {
        $storage = $this->storage();
        $storage->write('llms.txt', 3, 'feed');
        $storage->write('llms.txt', 4, 'feed');

        $storage->deleteStoreDirectory(3);
        $storage->deleteStoreDirectory(5);

        $this->assertSame([4], $storage->listStoreDirectories());
    }

    public function testALinkInPlaceOfTheStorageDirectoryIsNeverUsed(): void
    {
        // Issue #2: var/mageos_aeo as a link to a directory with a real store directory in it.
        $outside = \dirname($this->var) . '/outside';
        mkdir($outside . '/store_1', 0o750, true);
        file_put_contents($outside . '/store_1/llms.txt', 'outside');
        symlink($outside, $this->var . '/mageos_aeo');
        $storage = $this->storage();

        $this->assertNull($storage->read('llms.txt', 1));
        $this->assertSame([], $storage->listStoreDirectories());
        $storage->deleteForStore('llms*', 1);
        $storage->deleteStoreDirectory(1);
        try {
            $storage->write('llms.txt', 2, 'feed');
            $this->fail('A write through a linked storage directory was not refused.');
        } catch (\Magento\Framework\Exception\FileSystemException) {
            // Refused.
        }

        $this->assertSame('outside', file_get_contents($outside . '/store_1/llms.txt'));
        $this->assertDirectoryDoesNotExist($outside . '/store_2');
        unlink($this->var . '/mageos_aeo');
    }

    public function testADirectoryHoldingWhatFeedStorageDidNotWriteIsKeptWithANotice(): void
    {
        // Issue #6: the feed files go, everything else stays, and so does the directory.
        $storage = $this->storage();
        $storage->write('llms.txt', 3, 'feed');
        mkdir($this->var . '/mageos_aeo/store_3/unexpected');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('notice')->with($this->stringContains('was kept'));

        $this->storage('', true, null, $logger)->deleteStoreDirectory(3);

        $this->assertFileDoesNotExist($this->var . '/mageos_aeo/store_3/llms.txt');
        $this->assertDirectoryExists($this->var . '/mageos_aeo/store_3/unexpected');
    }

    public function testUnusableStorageIsReportedAndLoggedOncePerInterval(): void
    {
        // Issue #7: a mount missing on this host is not "no file yet", and every request meets it.
        // A cache that keeps what it is given, for the two requests.
        $memory = new class {
            /**
             * @var array<string, string>
             */
            public array $entries = [];
        };
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturnCallback(
            static fn (string $key): string|false => $memory->entries[$key] ?? false
        );
        $cache->method('save')->willReturnCallback(
            static function (string $data, string $key) use ($memory): bool {
                $memory->entries[$key] = $data;
                return true;
            }
        );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('cannot be used'));
        $storage = $this->storage($this->custom, true, null, $logger, $cache, true);

        foreach ([1, 2] as $request) {
            try {
                $storage->open('llms.txt', 1);
                $this->fail("Request {$request} did not report the storage.");
            } catch (FeedStorageUnavailableException) {
                // Reported.
            }
        }
    }

    /**
     * Storage over the test's var/, with a configured directory if given.
     *
     * @param string $configured
     * @param bool $permitted Whether the installation permits the configured directory
     * @param ProblemLog|null $problemLog
     * @param LoggerInterface|null $logger
     * @param CacheInterface|null $cache
     * @param bool $unavailable Whether this host cannot use the configured directory
     * @return FeedStorage
     */
    private function storage(
        string $configured = '',
        bool $permitted = true,
        ?ProblemLog $problemLog = null,
        ?LoggerInterface $logger = null,
        ?CacheInterface $cache = null,
        bool $unavailable = false
    ): FeedStorage {
        $directoryList = $this->createStub(DirectoryList::class);
        $directoryList->method('getPath')->willReturnMap([[DirectoryList::VAR_DIR, $this->var]]);
        $config = $this->createStub(Config::class);
        $config->method('getFeedStorageDir')->willReturn($configured);
        $storageDirectory = $this->createStub(StorageDirectory::class);
        if ($unavailable) {
            $storageDirectory->method('locate')->willThrowException(
                new FeedStorageUnavailableException(__('The feed storage directory does not exist: %1', [$configured]))
            );
        } else {
            $storageDirectory->method('locate')->willReturn($permitted && $configured !== '' ? $configured : null);
        }

        return new FeedStorage(
            $directoryList,
            new FileDriver(),
            new LinkSafeFilesystem(),
            $config,
            $storageDirectory,
            $logger ?? $this->createStub(LoggerInterface::class),
            $problemLog ?? $this->createStub(ProblemLog::class),
            $cache ?? $this->createStub(CacheInterface::class)
        );
    }
}
