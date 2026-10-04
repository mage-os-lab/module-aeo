<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Integration\Model;

use Magento\Store\Model\ResourceModel\Website as WebsiteResource;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\WebsiteFactory;
use Magento\Store\Test\Fixture\Group as GroupFixture;
use Magento\Store\Test\Fixture\Store as StoreFixture;
use Magento\Store\Test\Fixture\Website as WebsiteFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Aeo\Model\Feed\FeedRegenerator;
use MageOS\Aeo\Model\Feed\FeedStorage;
use PHPUnit\Framework\TestCase;

/**
 * What happens to the feed files of store views that are deleted without anyone hearing of it.
 *
 * Deleting a website or a store group takes its store views with it through a database-level
 * cascade that dispatches no store_delete event, so their feed directories are swept by the next
 * full rebuild instead.
 *
 * Database isolation is disabled because creating websites and store views is not transactional.
 *
 * @magentoAppArea adminhtml
 * @magentoDbIsolation disabled
 */
class ScopeDeletionCleanupTest extends TestCase
{
    /**
     * Feed files of store views that disappeared with their website are swept by a full rebuild.
     *
     * @return void
     */
    #[DataFixture(WebsiteFixture::class, as: 'website')]
    #[DataFixture(GroupFixture::class, ['website_id' => '$website.id$'], 'group')]
    #[DataFixture(StoreFixture::class, ['store_group_id' => '$group.id$'], 'store')]
    public function testAFullRebuildRemovesFeedDirectoriesOfStoreViewsThatNoLongerExist(): void
    {
        $websiteId = (int) $this->fixture('website')->getId();
        $storeId   = (int) $this->fixture('store')->getId();

        $storage = Bootstrap::getObjectManager()->create(FeedStorage::class);
        $storage->write('llms.txt', $storeId, 'store that is about to go');

        $this->deleteWebsite($websiteId);

        // Nothing dispatched store_delete for it, so the files are still there.
        $this->assertSame('store that is about to go', $storage->read('llms.txt', $storeId));

        Bootstrap::getObjectManager()->create(FeedRegenerator::class)->regenerate();

        $this->assertNull($storage->read('llms.txt', $storeId), 'The orphaned directory was swept.');
        $this->assertNotContains(
            $storeId,
            Bootstrap::getObjectManager()->create(FeedStorage::class)->listStoreDirectories()
        );
    }

    /**
     * Delete a website through its resource; core cascades its groups and store views.
     *
     * @param int $websiteId
     * @return void
     */
    private function deleteWebsite(int $websiteId): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $resource      = $objectManager->get(WebsiteResource::class);

        $website = $objectManager->get(WebsiteFactory::class)->create();
        $resource->load($website, $websiteId);
        $resource->delete($website);
        $objectManager->get(StoreManagerInterface::class)->reinitStores();
    }

    /**
     * An entity created by a data fixture.
     *
     * @param string $name
     * @return \Magento\Framework\DataObject
     */
    private function fixture(string $name): \Magento\Framework\DataObject
    {
        return DataFixtureStorageManager::getStorage()->get($name);
    }
}
