<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Integration\Model\Feed;

// phpcs:disable Magento2.Functions.DiscouragedFunction -- the test checks the disk directly

use Magento\Framework\App\Config\MutableScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\FlagManager;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Aeo\Model\Config;
use MageOS\Aeo\Model\Feed\FeedRegenerator;
use MageOS\Aeo\Model\Feed\FeedStorage;
use MageOS\Aeo\Model\Feed\Remote\RemoteFeedFiles;
use PHPUnit\Framework\TestCase;

/**
 * With Magento's remote storage on, the feeds are kept in it: written, read and removed through the
 * remote backend, at the storage directory taken from the remote storage's root.
 *
 * The integration installation has no bucket. The remote backend is given a remote-storage
 * configuration that reports remote storage on, over Magento's local driver: the code path is the
 * one an S3 installation takes, and the files land under var/, the root of that stand-in. A
 * configured storage directory tells the two backends apart: the local one refuses
 * `/mageos-aeo-remote-…` — outside the installation, declared nowhere — and falls back to
 * var/mageos_aeo; the remote one takes it from the root.
 *
 * Store IDs from 9300 up keep away from real store views' files.
 *
 * @magentoAppArea frontend
 * @magentoAppIsolation enabled
 */
class RemoteStorageFeedsTest extends TestCase
{
    /**
     * The storage directory configured for the test, taken from the root.
     *
     * @var string|null
     */
    private ?string $directory = null;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->directory = 'mageos-aeo-remote-' . bin2hex(random_bytes(4));

        $objectManager = Bootstrap::getObjectManager();
        $objectManager->configure([
            RemoteFeedFiles::class => [
                'arguments' => ['remoteStorageConfig' => ['instance' => EnabledRemoteStorageConfig::class]],
            ],
        ]);
        $objectManager->get(MutableScopeConfigInterface::class)
            ->setValue(Config::XML_FEEDS_STORAGE_DIR, '/' . $this->directory, 'default');
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->remove($this->varDirectory() . '/' . $this->directory);
        foreach (FeedRegenerator::GROUPS as $group) {
            Bootstrap::getObjectManager()->get(FlagManager::class)->deleteFlag('mageos_seo_feed_pending_' . $group);
        }
    }

    /**
     * A feed written is kept at the configured directory from the root, and read back from there.
     *
     * @return void
     */
    public function testAFeedIsWrittenAndReadAtTheConfiguredDirectoryFromTheRoot(): void
    {
        $storage = Bootstrap::getObjectManager()->create(FeedStorage::class);

        $storage->write('llms.txt', 9301, 'kept in remote storage');

        $path = $this->varDirectory() . '/' . $this->directory . '/store_9301/llms.txt';
        $this->assertFileExists($path);
        $this->assertSame('kept in remote storage', file_get_contents($path));
        $this->assertSame('kept in remote storage', $storage->read('llms.txt', 9301));
    }

    /**
     * A rebuild writes the store views' feeds there too.
     *
     * @return void
     */
    public function testARebuildWritesTheFeedsToRemoteStorage(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $storeId       = (int) $objectManager->get(StoreManagerInterface::class)->getStore('default')->getId();
        $objectManager->get(MutableScopeConfigInterface::class)
            ->setValue(Config::XML_LLMS_ENABLED, '1', 'store', 'default');

        $objectManager->create(FeedRegenerator::class)->regenerate(FeedRegenerator::GROUP_LLMS);

        $this->assertFileExists($this->varDirectory() . '/' . $this->directory . '/store_' . $storeId . '/llms.txt');
    }

    /**
     * A deleted store view's feeds are removed from remote storage, and what the module did not write
     * stays.
     *
     * @return void
     */
    public function testADeletedStoreViewsFeedsAreRemovedFromRemoteStorage(): void
    {
        $storage = Bootstrap::getObjectManager()->create(FeedStorage::class);
        $storage->write('llms.txt', 9302, 'feed');
        $storage->write('llms.jsonl', 9302, '{}');
        $storage->write('llms.txt', 9303, 'another store view');

        $storage->deleteStoreDirectory(9302);

        $this->assertDirectoryDoesNotExist($this->varDirectory() . '/' . $this->directory . '/store_9302');
        $this->assertSame([9303], $storage->listStoreDirectories());
    }

    /**
     * The installation's var/ directory, resolved.
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
        foreach ((array) scandir($path) as $name) {
            if ($name !== '.' && $name !== '..') {
                $this->remove($path . '/' . $name);
            }
        }
        rmdir($path);
    }
}
