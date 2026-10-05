<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Integration\Model\Feed;

// phpcs:disable Magento2.Functions.DiscouragedFunction -- the test builds directories and checks the disk directly

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\FlagManager;
use Magento\Store\Model\ScopeInterface;
use Magento\TestFramework\Fixture\Config as ConfigFixture;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Aeo\Controller\Llmsjsonl\Index as LlmsJsonl;
use MageOS\Aeo\Model\Config;
use MageOS\Aeo\Model\Feed\FeedRegenerator;
use MageOS\Aeo\Model\Feed\FeedStorage;
use MageOS\Aeo\Model\Feed\StorageDirectory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Feed storage a host can only read, storage that is not there, and storage shared with other files
 * (issues #7 and #6).
 *
 * A web host may only read the shared directory the cron host writes; a mount missing on one host
 * is a fault a rebuild cannot fix; and a directory the feeds share keeps what the module did not
 * write there.
 *
 * Store IDs from 9100 up keep away from real store views' files.
 *
 * @magentoAppArea frontend
 */
class StorageAvailabilityTest extends TestCase
{
    /**
     * Paths the test created, removed afterwards.
     *
     * @var string[]|null
     */
    private ?array $created = [];

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        foreach (array_reverse($this->created ?? []) as $path) {
            $this->remove($path);
        }
        $this->created = [];
        Bootstrap::getObjectManager()->get(FlagManager::class)->deleteFlag('mageos_seo_feed_pending_jsonl');
    }

    /**
     * Issue #7: a directory the web host can read but not write serves the feeds written into it.
     *
     * @return void
     */
    public function testAReadOnlyDirectoryIsServed(): void
    {
        $root = $this->directoryInVar('mageos-aeo-readonly-');
        $this->storage($root)->write('llms.txt', 9101, 'feed');
        chmod($root, 0o550);

        $this->assertSame('feed', $this->storage($root)->read('llms.txt', 9101));
    }

    /**
     * Issue #7: a rebuild into a directory it cannot write fails, and does not write elsewhere.
     *
     * @return void
     */
    public function testARebuildIntoADirectoryItCannotWriteFails(): void
    {
        $root = $this->directoryInVar('mageos-aeo-readonly-');
        chmod($root, 0o550);
        $fallback = $this->varDirectory() . '/mageos_aeo/store_9102';
        $this->created[] = $fallback;

        $failed = false;
        try {
            $this->storage($root)->write('llms.txt', 9102, 'feed');
        } catch (\Exception) {
            $failed = true;
        }

        $this->assertTrue($failed, 'The write was refused.');
        $this->assertDirectoryDoesNotExist($fallback, 'Nothing was written to var/mageos_aeo instead.');
    }

    /**
     * Issue #7: a configured directory missing on this host answers 503, queues no rebuild — one
     * cannot bring a mount back — and is logged once, however many requests meet it.
     *
     * @return void
     */
    #[ConfigFixture('mageos_aeo/llms_txt/jsonl_enabled', 1, ScopeInterface::SCOPE_STORE, 'default')]
    public function testAMissingDirectoryAnswers503WithoutQueuingARebuildAndIsLoggedOnce(): void
    {
        $missing = $this->varDirectory() . '/mageos-aeo-missing-' . bin2hex(random_bytes(4));
        $logger  = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');
        $storage = $this->storage($missing, $logger);
        Bootstrap::getObjectManager()->get(FlagManager::class)->deleteFlag('mageos_seo_feed_pending_jsonl');
        Bootstrap::getObjectManager()->get(RequestInterface::class)->setPathInfo('/llms.jsonl');

        foreach ([1, 2] as $request) {
            $result = Bootstrap::getObjectManager()->create(LlmsJsonl::class, ['feedStorage' => $storage])->execute();
            $response = Bootstrap::getObjectManager()->create(\Magento\Framework\App\Response\Http::class);
            $result->renderResult($response);
            $this->assertSame(503, $response->getHttpResponseCode(), "request {$request}");
        }

        $this->assertNull(
            Bootstrap::getObjectManager()->get(FlagManager::class)->getFlagData('mageos_seo_feed_pending_jsonl'),
            'No rebuild was queued.'
        );
    }

    /**
     * Issue #6: var/ itself is not a storage directory; it holds everything else's files too.
     *
     * @return void
     */
    public function testVarItselfIsRefused(): void
    {
        $this->assertFalse(
            Bootstrap::getObjectManager()->get(StorageDirectory::class)->isAllowed($this->varDirectory())
        );
    }

    /**
     * Issue #6: removing a store's feed directory removes the feed files, and keeps a file the
     * module did not write — and so the directory it is in.
     *
     * @return void
     */
    public function testRemovingAStoreDirectoryKeepsWhatTheModuleDidNotWrite(): void
    {
        $directory = $this->storeDirectory(9103);
        file_put_contents($directory . '/llms.txt', 'feed');
        file_put_contents($directory . '/keep.txt', 'another tool');

        $this->storage('')->deleteStoreDirectory(9103);

        $this->assertFileDoesNotExist($directory . '/llms.txt');
        $this->assertSame('another tool', file_get_contents($directory . '/keep.txt'));
    }

    /**
     * Issue #6: the full rebuild's sweep of directories without a store view keeps what the module
     * did not write.
     *
     * @return void
     */
    public function testTheFullRebuildsSweepKeepsWhatTheModuleDidNotWrite(): void
    {
        $directory = $this->storeDirectory(9104);
        file_put_contents($directory . '/keep.txt', 'another tool');

        Bootstrap::getObjectManager()->create(FeedRegenerator::class)->regenerate();

        $this->assertSame('another tool', file_get_contents($directory . '/keep.txt'));
    }

    /**
     * Feed storage on a configured directory, or on var/mageos_aeo for ''.
     *
     * @param string $directory
     * @param LoggerInterface|null $logger
     * @return FeedStorage
     */
    private function storage(string $directory, ?LoggerInterface $logger = null): FeedStorage
    {
        $config = $this->createStub(Config::class);
        $config->method('getFeedStorageDir')->willReturn($directory);
        $arguments = ['aeoConfig' => $config];
        if ($logger !== null) {
            $arguments['logger'] = $logger;
        }

        return Bootstrap::getObjectManager()->create(FeedStorage::class, $arguments);
    }

    /**
     * Create a directory in var/ for the test.
     *
     * @param string $prefix
     * @return string
     */
    private function directoryInVar(string $prefix): string
    {
        $directory = $this->varDirectory() . '/' . $prefix . bin2hex(random_bytes(4));
        mkdir($directory, 0o750);
        $this->created[] = $directory;

        return $directory;
    }

    /**
     * Create a store directory in var/mageos_aeo.
     *
     * @param int $storeId
     * @return string
     */
    private function storeDirectory(int $storeId): string
    {
        $directory = $this->varDirectory() . '/mageos_aeo/store_' . $storeId;
        if (!is_dir($directory)) {
            mkdir($directory, 0o750, true);
        }
        $this->created[] = $directory;

        return $directory;
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
     * Remove a path the test made, without following links.
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
