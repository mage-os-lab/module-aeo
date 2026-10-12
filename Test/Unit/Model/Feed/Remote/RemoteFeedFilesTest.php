<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Unit\Model\Feed\Remote;

// phpcs:disable Magento2.Functions.DiscouragedFunction -- the test checks the stand-in storage directly

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\Write;
use Magento\Framework\Filesystem\DriverPool;
use Magento\Framework\Filesystem\File\WriteFactory;
use Magento\RemoteStorage\Model\Config as RemoteStorageConfig;
use MageOS\Aeo\Exception\FeedStorageUnavailableException;
use MageOS\Aeo\Model\Config;
use MageOS\Aeo\Model\Feed\Remote\DriverFeedReader;
use MageOS\Aeo\Model\Feed\Remote\RemoteFeedFiles;
use MageOS\Aeo\Model\Feed\Remote\RemoteStoragePath;
use MageOS\Aeo\Model\Feed\Remote\S3FeedReader;
use MageOS\Aeo\Model\Feed\StorageLog;
use MageOS\Seo\Model\Rebuild\ProblemLog;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Feed files in remote storage, on a real directory standing in for the bucket — the remote
 * storage's root — through Magento's directory API, as on S3.
 */
class RemoteFeedFilesTest extends TestCase
{
    /**
     * The stand-in for the remote storage's root.
     *
     * @var string
     */
    private string $root = '';

    /**
     * @var FailingFileDriver
     */
    private FailingFileDriver $driver;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->root   = sys_get_temp_dir() . '/mageos-aeo-remote-' . bin2hex(random_bytes(6));
        $this->driver = new FailingFileDriver();
        mkdir($this->root);
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    /**
     * With no directory configured, the feeds are under mageos_aeo/ from the root.
     *
     * @return void
     */
    public function testAFeedIsWrittenAndReadInTheDefaultDirectory(): void
    {
        $files = $this->files();

        $files->write('llms.txt', 1, 'feed');

        $this->assertSame('feed', file_get_contents($this->root . '/mageos_aeo/store_1/llms.txt'));
        $this->assertSame('feed', $files->read('llms.txt', 1));
        $this->assertTrue($files->exists('llms.txt', 1));
        $this->assertFalse($files->exists('llms.jsonl', 1));
        $this->assertSame('mageos_aeo/store_1/llms.txt', $files->pathOf('llms.txt', 1));
    }

    /**
     * Decision 3: the configured directory is taken from the remote storage's root.
     *
     * @return void
     */
    public function testTheConfiguredDirectoryIsTakenFromTheRoot(): void
    {
        $this->files('/mnt/shared/feeds/')->write('llms.txt', 1, 'feed');

        $this->assertSame('feed', file_get_contents($this->root . '/mnt/shared/feeds/store_1/llms.txt'));
    }

    /**
     * A configured directory that cannot be a path in remote storage falls back to mageos_aeo/, and
     * a rebuild says so.
     *
     * @return void
     */
    public function testADirectoryThatCannotBeAPathFallsBackAndIsReported(): void
    {
        $problemLog = $this->createMock(ProblemLog::class);
        $problemLog->expects($this->atLeastOnce())->method('degradedWhileRebuilding');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())->method('error')
            ->with($this->stringContains('cannot be a path in remote storage'));

        $this->files('/feeds/../etc', problemLog: $problemLog, logger: $logger)->write('llms.txt', 1, 'feed');

        $this->assertFileExists($this->root . '/mageos_aeo/store_1/llms.txt');
    }

    /**
     * A streamed feed appears only when it is committed, and every file opened is closed — Magento's
     * S3 driver uploads a file when it is closed.
     *
     * @return void
     */
    public function testAStreamedFeedAppearsOnlyWhenCommitted(): void
    {
        $files  = $this->files();
        $writer = $files->openForWrite(1);
        $writer->write('{"a":1}' . "\n");
        $writer->write('{"b":2}' . "\n");
        $this->assertFileDoesNotExist($this->root . '/mageos_aeo/store_1/llms.jsonl');

        $writer->commit('llms.jsonl');

        $this->assertSame('{"a":1}' . "\n" . '{"b":2}' . "\n", $files->read('llms.jsonl', 1));
        $this->assertSame(0, $this->driver->openFiles());
        $this->assertSame(['llms.jsonl'], $this->namesIn('mageos_aeo/store_1'), 'No temporary file is left.');
    }

    /**
     * Until it is committed, a feed is written to a hidden ".tmp" object in the store view's
     * directory, which no served name or clean-up pattern matches but the one for such files.
     *
     * @return void
     */
    public function testAFeedIsWrittenToAHiddenTemporaryObjectBesideTheServedOne(): void
    {
        $writer = $this->files()->openForWrite(1);
        $writer->write('{"a":1}');

        $names = $this->namesIn('mageos_aeo/store_1');
        $this->assertCount(1, $names);
        $this->assertMatchesRegularExpression('/^\.[0-9a-f]{12}\.tmp$/', $names[0]);

        $writer->discard();
    }

    /**
     * A feed with nothing in it is still written: an empty file.
     *
     * @return void
     */
    public function testAFeedWithNothingWrittenIsAnEmptyFile(): void
    {
        $files = $this->files();

        $files->openForWrite(1)->commit('llms.jsonl');

        $this->assertSame('', file_get_contents($this->root . '/mageos_aeo/store_1/llms.jsonl'));
    }

    /**
     * A feed that is not there is no feed.
     *
     * @return void
     */
    public function testAFeedThatIsNotThereIsNoFeed(): void
    {
        $this->assertNull($this->files()->read('llms.txt', 1));
    }

    /**
     * A storage library's own failure is reported as a FileSystemException, as on local disk; the
     * framework's own is reported as it is.
     *
     * @return void
     */
    public function testAFailureIsReportedAsAFileSystemException(): void
    {
        $files    = $this->files();
        $original = new \RuntimeException('Unable to write file at location: mageos_aeo/store_1.');

        $this->driver->failAt(1, $original);
        try {
            $files->write('llms.txt', 1, 'feed');
            $this->fail('The write did not fail.');
        } catch (FileSystemException $e) {
            $this->assertSame(
                'Remote storage could not write a feed file: Unable to write file at location: mageos_aeo/store_1.',
                $e->getMessage()
            );
            $this->assertSame($original, $e->getPrevious());
        }

        $this->driver->failAt(1);
        try {
            $files->write('llms.txt', 1, 'feed');
            $this->fail('The write did not fail.');
        } catch (FileSystemException $e) {
            $this->assertStringStartsWith('Injected failure', $e->getMessage());
        }
    }

    /**
     * Reading through Magento's driver, which holds the file whole, says so in the log.
     *
     * @return void
     */
    public function testReadingThroughMagentosDriverSaysSo(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('notice')
            ->with($this->stringContains('read whole'), ['driver' => 'file']);
        $files = $this->files(logger: $logger);
        $files->write('llms.txt', 1, 'feed');

        $this->assertSame('feed', $files->read('llms.txt', 1));
    }

    /**
     * A feed given up leaves the served one and no temporary file.
     *
     * @return void
     */
    public function testADiscardedFeedLeavesTheServedOne(): void
    {
        $files = $this->files();
        $files->write('llms.jsonl', 1, 'served');

        $writer = $files->openForWrite(1);
        $writer->write('half');
        $writer->discard();

        $this->assertSame('served', $files->read('llms.jsonl', 1));
        $this->assertSame(['llms.jsonl'], $this->namesIn('mageos_aeo/store_1'));
    }

    /**
     * Whichever operation fails, the served feed is the old one or the new one, never part of one.
     *
     * @return void
     */
    public function testAFailureAtAnyStepLeavesTheServedFeedWhole(): void
    {
        $files = $this->files();
        for ($operation = 1; $operation < 50; $operation++) {
            $storeId = 100 + $operation;
            $files->write('llms.txt', $storeId, 'old');

            $this->driver->failAt($operation);
            try {
                $files->write('llms.txt', $storeId, 'new');
                $completed = true;
            } catch (FileSystemException) {
                $completed = false;
            } finally {
                $this->driver->failAt(null);
            }

            $this->assertContains($files->read('llms.txt', $storeId), ['old', 'new'], "Operation $operation");
            $this->assertSame(
                ['llms.txt'],
                $this->namesIn('mageos_aeo/store_' . $storeId),
                "Operation $operation leaves no temporary file."
            );
            if ($completed) {
                $this->assertGreaterThan(1, $operation, 'No operation was failed.');
                $this->assertSame('new', $files->read('llms.txt', $storeId));
                return;
            }
        }

        $this->fail('The write never ran through.');
    }

    /**
     * Deleting by pattern touches one store view's files only.
     *
     * @return void
     */
    public function testDeletingByPatternTouchesOneStoreView(): void
    {
        $files = $this->files();
        $files->write('llms.txt', 1, 'a');
        $files->write('llms-full.txt', 1, 'b');
        $files->write('llms.jsonl', 1, 'c');
        $files->write('llms.txt', 2, 'd');

        $files->deleteForStore('llms*.txt', 1);

        $this->assertSame(['llms.jsonl'], $this->namesIn('mageos_aeo/store_1'));
        $this->assertSame('d', $files->read('llms.txt', 2));
    }

    /**
     * A deleted store view's directory goes with its feeds; one holding anything else stays, with a
     * notice, and keeps it.
     *
     * @return void
     */
    public function testDeletingAStoreDirectoryRemovesOnlyWhatFeedStorageWrote(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('notice')->with($this->stringContains('was kept'));
        $files = $this->files(logger: $logger);
        $files->write('llms.txt', 2, 'a');
        $files->write('llms.txt', 3, 'b');
        file_put_contents($this->root . '/mageos_aeo/store_3/not-a-feed.txt', 'kept');

        $files->deleteStoreDirectory(2);
        $files->deleteStoreDirectory(3);

        $this->assertDirectoryDoesNotExist($this->root . '/mageos_aeo/store_2');
        $this->assertSame(['not-a-feed.txt'], $this->namesIn('mageos_aeo/store_3'));
    }

    /**
     * The store views with a directory, by ID; nothing else in the directory counts.
     *
     * @return void
     */
    public function testTheStoreViewsWithADirectoryAreListed(): void
    {
        $files = $this->files();
        $this->assertSame([], $files->listStoreDirectories(), 'Nothing there yet.');
        $files->write('llms.txt', 12, 'a');
        $files->write('llms.txt', 4, 'b');
        mkdir($this->root . '/mageos_aeo/not_a_store');
        mkdir($this->root . '/mageos_aeo/old_store_5');
        mkdir($this->root . '/mageos_aeo/store_6.bak');

        $this->assertSame([4, 12], $files->listStoreDirectories());
    }

    /**
     * On S3, feeds are read through the streaming reader, at their path from the root.
     *
     * @return void
     */
    public function testOnS3TheStreamingReaderReadsTheFeed(): void
    {
        $reader = $this->createMock(S3FeedReader::class);
        $reader->expects($this->once())->method('open')->with('mageos_aeo/store_1/llms.txt')->willReturn(null);

        $this->assertNull($this->files(driverCode: 'aws-s3', s3FeedReader: $reader)->open('llms.txt', 1));
    }

    /**
     * Remote storage that cannot be read is logged and reported, not taken for a missing feed.
     *
     * @return void
     */
    public function testUnreadableRemoteStorageIsLoggedAndReported(): void
    {
        $reader = $this->createStub(S3FeedReader::class);
        $reader->method('open')->willThrowException(new FeedStorageUnavailableException(__('down')));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('cannot be read'));

        $this->expectException(FeedStorageUnavailableException::class);

        $this->files(driverCode: 'aws-s3', s3FeedReader: $reader, logger: $logger)->open('llms.txt', 1);
    }

    /**
     * Whether the feeds are kept in remote storage is whether Magento's remote storage is on.
     *
     * @return void
     */
    public function testRemoteStorageDecides(): void
    {
        $this->assertTrue($this->files()->isEnabled());
        $this->assertFalse($this->files(enabled: false)->isEnabled());
    }

    /**
     * The remote feed files over the stand-in directory.
     *
     * @param string $configured The storage directory setting
     * @param string $driverCode Magento's remote driver
     * @param bool $enabled Whether remote storage is on
     * @param S3FeedReader|null $s3FeedReader
     * @param ProblemLog|null $problemLog
     * @param LoggerInterface|null $logger
     * @return RemoteFeedFiles
     */
    private function files(
        string $configured = '',
        string $driverCode = 'file',
        bool $enabled = true,
        ?S3FeedReader $s3FeedReader = null,
        ?ProblemLog $problemLog = null,
        ?LoggerInterface $logger = null
    ): RemoteFeedFiles {
        $directory  = new Write(new WriteFactory(new DriverPool()), $this->driver, $this->root);
        $filesystem = $this->createStub(Filesystem::class);
        $filesystem->method('getDirectoryWrite')->willReturn($directory);
        $filesystem->method('getDirectoryRead')->willReturn($directory);

        $remoteStorage = $this->createStub(RemoteStorageConfig::class);
        $remoteStorage->method('isEnabled')->willReturn($enabled);
        $remoteStorage->method('getDriver')->willReturn($driverCode);

        $config = $this->createStub(Config::class);
        $config->method('getFeedStorageDir')->willReturn($configured);

        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturn(false);
        $logger ??= $this->createStub(LoggerInterface::class);
        $storageLog = new StorageLog($logger, $cache);

        return new RemoteFeedFiles(
            $filesystem,
            $remoteStorage,
            $config,
            new RemoteStoragePath(),
            $s3FeedReader ?? $this->createStub(S3FeedReader::class),
            new DriverFeedReader($filesystem, $remoteStorage, $storageLog),
            $storageLog,
            $logger,
            $problemLog ?? $this->createStub(ProblemLog::class)
        );
    }

    /**
     * The names in a directory of the stand-in, sorted.
     *
     * @param string $path From the root
     * @return string[]
     */
    private function namesIn(string $path): array
    {
        $entries = scandir($this->root . '/' . $path);
        if ($entries === false) {
            $this->fail('No directory ' . $path . '.');
        }
        $names = array_values(array_diff($entries, ['.', '..']));
        sort($names);

        return $names;
    }

    /**
     * Remove a directory and everything in it.
     *
     * @param string $path
     * @return void
     */
    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
