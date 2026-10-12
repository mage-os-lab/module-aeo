<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Integration\Model\Feed;

use Magento\Config\Model\Config as ConfigModel;
use Magento\Config\Model\ResourceModel\Config\Data\CollectionFactory as ConfigDataCollectionFactory;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\FlagManager;
use Magento\PageCache\Model\Cache\Type as FullPageCache;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Test\Fixture\Store as StoreFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Aeo\Model\Feed\FeedCache;
use MageOS\Aeo\Model\Feed\FeedRegenerator;
use MageOS\Aeo\Model\Feed\FeedStorage;
use PHPUnit\Framework\TestCase;

/**
 * A configuration change brings the feeds in line once it is committed (issue #4).
 *
 * Switching a document off or on removes its files for the store views under the saved scope,
 * purges its cached responses and queues its rebuild, whatever the configuration in memory still
 * says. Changing a value a feed shows queues that feed's rebuild. Values are saved through the config
 * model, as the admin and `bin/magento config:set` save them, at the default, website and store
 * scope, and removed as the admin's "Use Default" removes them.
 *
 * The work waits for the commit, so database isolation is off. Every value the test saves is put
 * back as it was, and the files, cache entries and pending flags it made are removed.
 *
 * @magentoAppArea adminhtml
 * @magentoDbIsolation disabled
 * @magentoCache full_page enabled
 */
class FeedConfigChangeTest extends TestCase
{
    private const ENABLED       = 'mageos_aeo/llms_txt/enabled';
    private const FULL_ENABLED  = 'mageos_aeo/llms_txt/full_enabled';
    private const JSONL_ENABLED = 'mageos_aeo/llms_txt/jsonl_enabled';

    private const CACHE_ID = 'MAGEOS_AEO_CONFIG_CHANGE_TEST_';

    /**
     * The stored value of every path and scope the test saved, null where there was none.
     *
     * @var array<string, array{0: string, 1: string, 2: int, 3: string|null}>|null
     */
    private ?array $originals = [];

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->clearPending();
    }

    /**
     * Put the saved values back, and remove the files, cache entries and requests the test made.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $writer        = $objectManager->get(WriterInterface::class);
        foreach ($this->originals ?? [] as [$path, $scope, $scopeId, $value]) {
            if ($value === null) {
                $writer->delete($path, $scope, $scopeId);
            } else {
                $writer->save($path, $value, $scope, $scopeId);
            }
        }
        $this->originals = [];
        $objectManager->get(ReinitableConfigInterface::class)->reinit();

        $storage = $objectManager->create(FeedStorage::class);
        foreach ($objectManager->get(StoreManagerInterface::class)->getStores() as $store) {
            $storage->deleteForStore('llms*', (int) $store->getId());
            $storage->deleteForStore('.*.tmp', (int) $store->getId());
        }
        foreach ([FeedCache::TAG_LLMS, FeedCache::TAG_LLMS_FULL, FeedCache::TAG_LLMS_JSONL] as $tag) {
            $objectManager->get(FullPageCache::class)->remove(self::CACHE_ID . $tag);
        }
        $this->clearPending();
    }

    /**
     * Switching llms.jsonl off at the default scope: no file and no cached response can serve the
     * old document, and only its own group is rebuilt.
     *
     * @return void
     */
    public function testSwitchingLlmsJsonlOffRetiresItEverywhere(): void
    {
        $this->save(self::JSONL_ENABLED, '1');
        $this->write(['llms.jsonl', 'llms.txt'], $this->defaultStoreId());
        $this->warmCache();
        $this->clearPending();

        $this->save(self::JSONL_ENABLED, '0');

        $this->assertNull($this->read('llms.jsonl', $this->defaultStoreId()), 'llms.jsonl is removed.');
        $this->assertNotNull($this->read('llms.txt', $this->defaultStoreId()), 'llms.txt stays.');
        $this->assertSame([FeedCache::TAG_LLMS, FeedCache::TAG_LLMS_FULL], $this->cachedTags());
        $this->assertSame([FeedRegenerator::GROUP_JSONL], $this->pendingGroups());
    }

    /**
     * Switching llms.jsonl back on before any rebuild: the file from before is not served as current.
     *
     * @return void
     */
    public function testSwitchingLlmsJsonlOnServesNothingFromBefore(): void
    {
        $this->save(self::JSONL_ENABLED, '0');
        $this->write(['llms.jsonl'], $this->defaultStoreId());
        $this->warmCache();
        $this->clearPending();

        $this->save(self::JSONL_ENABLED, '1');

        $this->assertNull($this->read('llms.jsonl', $this->defaultStoreId()), 'The old llms.jsonl is removed.');
        $this->assertNotContains(FeedCache::TAG_LLMS_JSONL, $this->cachedTags());
        $this->assertSame([FeedRegenerator::GROUP_JSONL], $this->pendingGroups());
    }

    /**
     * A store-scope switch retires that store view's document only.
     *
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'second_store')]
    public function testAStoreScopeSwitchRetiresOnlyThatStoreView(): void
    {
        $second = (int) DataFixtureStorageManager::getStorage()->get('second_store')->getId();
        $this->write(['llms.txt'], $this->defaultStoreId());
        $this->write(['llms.txt'], $second);

        $this->save(self::ENABLED, '0', ScopeInterface::SCOPE_STORES, $second);

        $this->assertNull($this->read('llms.txt', $second), 'The store view switched off has no file.');
        $this->assertNotNull($this->read('llms.txt', $this->defaultStoreId()), 'The others keep theirs.');
        $this->assertSame([FeedRegenerator::GROUP_LLMS], $this->pendingGroups());
    }

    /**
     * A website-scope switch retires the document of every store view in the website, and only
     * that document.
     *
     * @return void
     */
    #[DataFixture(StoreFixture::class, as: 'second_store')]
    public function testAWebsiteScopeSwitchRetiresTheDocumentOfItsStoreViews(): void
    {
        $second    = (int) DataFixtureStorageManager::getStorage()->get('second_store')->getId();
        $websiteId = (int) Bootstrap::getObjectManager()->get(StoreManagerInterface::class)
            ->getStore($second)->getWebsiteId();
        $this->write(['llms.txt', 'llms-full.txt'], $this->defaultStoreId());
        $this->write(['llms.txt', 'llms-full.txt'], $second);
        $this->warmCache();

        $current = (string) Bootstrap::getObjectManager()->get(ScopeConfigInterface::class)
            ->getValue(self::FULL_ENABLED, ScopeInterface::SCOPE_WEBSITES, $websiteId);
        $this->save(self::FULL_ENABLED, $current === '1' ? '0' : '1', ScopeInterface::SCOPE_WEBSITES, $websiteId);

        foreach ([$this->defaultStoreId(), $second] as $storeId) {
            $this->assertNull($this->read('llms-full.txt', $storeId), "llms-full.txt of store {$storeId}");
            $this->assertNotNull($this->read('llms.txt', $storeId), "llms.txt of store {$storeId}");
        }
        $this->assertSame([FeedCache::TAG_LLMS, FeedCache::TAG_LLMS_JSONL], $this->cachedTags());
        $this->assertSame([FeedRegenerator::GROUP_LLMS], $this->pendingGroups());
    }

    /**
     * "Use Default" deletes the store's own value: the switch is as much a change as a save.
     *
     * @return void
     */
    public function testUsingTheDefaultAgainRetiresTheDocument(): void
    {
        $this->save(self::JSONL_ENABLED, '1', ScopeInterface::SCOPE_STORES, $this->defaultStoreId());
        $this->write(['llms.jsonl'], $this->defaultStoreId());
        $this->clearPending();

        $config = Bootstrap::getObjectManager()->create(ConfigModel::class, ['data' => [
            'section'    => 'mageos_aeo',
            'scope'      => ScopeInterface::SCOPE_STORES,
            'scope_id'   => $this->defaultStoreId(),
            'groups'     => ['llms_txt' => ['fields' => ['jsonl_enabled' => ['value' => '1', 'inherit' => 1]]]],
        ]]);
        $config->save();

        $this->assertNull($this->read('llms.jsonl', $this->defaultStoreId()));
        $this->assertSame([FeedRegenerator::GROUP_JSONL], $this->pendingGroups());
    }

    /**
     * A changed value a feed shows queues the rebuild of each feed that shows it.
     *
     * Only settings without side effects are saved here, since nothing is rolled back: the URL
     * suffixes regenerate URL rewrites, and the price scope and the out-of-stock display mark
     * indexers invalid. FeedConfigDependenciesTest lists every path.
     *
     * @return void
     */
    public function testAValueAFeedShowsQueuesTheRebuildOfEachFeedShowingIt(): void
    {
        $this->save(self::JSONL_ENABLED, '1');
        $both    = [FeedRegenerator::GROUP_JSONL, FeedRegenerator::GROUP_LLMS];
        $changes = [
            // Product URLs, with or without the category path; the category tree's URLs too.
            'catalog/seo/product_use_categories' => [$this->flipped('catalog/seo/product_use_categories'), $both],
            'web/seo/use_rewrites'               => [$this->flipped('web/seo/use_rewrites'), $both],
            // What llms.txt and llms-full.txt show, and llms.jsonl does not.
            'general/locale/code'                => ['en_GB', [FeedRegenerator::GROUP_LLMS]],
            'trans_email/ident_support/email'    => ['help@shop.test', [FeedRegenerator::GROUP_LLMS]],
            'mageos_aeo/llms_txt/faq_groups'     => ['global,shipping', [FeedRegenerator::GROUP_LLMS]],
        ];

        foreach ($changes as $path => [$value, $groups]) {
            $this->assertNotSame($this->current($path), $value, "The test value for {$path} is not a change.");
            $this->clearPending();
            $this->save($path, $value);
            $this->assertSame($groups, $this->pendingGroups(), $path);
        }
    }

    /**
     * Configuration no feed shows, and a value saved unchanged, queue nothing.
     *
     * @return void
     */
    public function testOtherConfigurationAndUnchangedValuesQueueNothing(): void
    {
        $this->save(self::JSONL_ENABLED, '1');

        $this->clearPending();
        $this->save('contact/email/recipient_email', 'x@shop.test');
        $this->assertSame([], $this->pendingGroups(), 'An unrelated path queued a rebuild.');

        $this->save('general/locale/code', $this->current('general/locale/code'));
        $this->save(self::JSONL_ENABLED, '1');
        $this->assertSame([], $this->pendingGroups(), 'An unchanged value queued a rebuild.');
    }

    /**
     * The stored value at the default scope.
     *
     * @param string $path
     * @return string
     */
    private function current(string $path): string
    {
        return (string) Bootstrap::getObjectManager()->get(ScopeConfigInterface::class)->getValue($path);
    }

    /**
     * The other value of a yes/no setting: installs differ on which one they have.
     *
     * @param string $path
     * @return string
     */
    private function flipped(string $path): string
    {
        return $this->current($path) === '1' ? '0' : '1';
    }

    /**
     * Save one value through the config model, remembering what was stored before.
     *
     * @param string $path
     * @param string $value
     * @param string $scope
     * @param int $scopeId
     * @return void
     */
    private function save(
        string $path,
        string $value,
        string $scope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT,
        int $scopeId = 0
    ): void {
        $key = $path . '|' . $scope . '|' . $scopeId;
        if (!isset($this->originals[$key])) {
            $stored = Bootstrap::getObjectManager()->get(ConfigDataCollectionFactory::class)->create()
                ->addFieldToFilter('path', $path)
                ->addFieldToFilter('scope', $scope)
                ->addFieldToFilter('scope_id', $scopeId)
                ->getFirstItem();
            $this->originals[$key] = [$path, $scope, $scopeId, $stored->getId() ? (string) $stored->getValue() : null];
        }

        $config = Bootstrap::getObjectManager()->create(ConfigModel::class, ['data' => [
            'scope'    => $scope,
            'scope_id' => $scope === ScopeConfigInterface::SCOPE_TYPE_DEFAULT ? null : $scopeId,
        ]]);
        $config->setDataByPath($path, $value);
        $config->save();
    }

    /**
     * Write placeholder feed files for a store view.
     *
     * @param string[] $files
     * @param int $storeId
     * @return void
     */
    private function write(array $files, int $storeId): void
    {
        $storage = Bootstrap::getObjectManager()->create(FeedStorage::class);
        foreach ($files as $file) {
            $storage->write($file, $storeId, 'written before the change');
        }
    }

    /**
     * @param string $file
     * @param int $storeId
     * @return string|null
     */
    private function read(string $file, int $storeId): ?string
    {
        return Bootstrap::getObjectManager()->create(FeedStorage::class)->read($file, $storeId);
    }

    /**
     * Put one cached response per feed tag into the built-in full page cache.
     *
     * @return void
     */
    private function warmCache(): void
    {
        $cache = Bootstrap::getObjectManager()->get(FullPageCache::class);
        foreach ([FeedCache::TAG_LLMS, FeedCache::TAG_LLMS_FULL, FeedCache::TAG_LLMS_JSONL] as $tag) {
            $cache->save('cached response', self::CACHE_ID . $tag, [$tag]);
            $this->assertNotFalse($cache->load(self::CACHE_ID . $tag), "The {$tag} entry was cached.");
        }
    }

    /**
     * The feed tags whose cached test response is still in the full page cache.
     *
     * @return string[]
     */
    private function cachedTags(): array
    {
        $cache  = Bootstrap::getObjectManager()->get(FullPageCache::class);
        $cached = [];
        foreach ([FeedCache::TAG_LLMS, FeedCache::TAG_LLMS_FULL, FeedCache::TAG_LLMS_JSONL] as $tag) {
            if ($cache->load(self::CACHE_ID . $tag) !== false) {
                $cached[] = $tag;
            }
        }

        return $cached;
    }

    /**
     * The feed groups with a rebuild request pending, sorted.
     *
     * @return string[]
     */
    private function pendingGroups(): array
    {
        $flags   = Bootstrap::getObjectManager()->get(FlagManager::class);
        $pending = [];
        foreach (FeedRegenerator::GROUPS as $group) {
            if ($flags->getFlagData('mageos_seo_feed_pending_' . $group) !== null) {
                $pending[] = $group;
            }
        }
        sort($pending);

        return $pending;
    }

    /**
     * Remove every pending feed rebuild request.
     *
     * @return void
     */
    private function clearPending(): void
    {
        $flags = Bootstrap::getObjectManager()->get(FlagManager::class);
        foreach (FeedRegenerator::GROUPS as $group) {
            $flags->deleteFlag('mageos_seo_feed_pending_' . $group);
        }
    }

    /**
     * @return int
     */
    private function defaultStoreId(): int
    {
        return (int) Bootstrap::getObjectManager()->get(StoreManagerInterface::class)->getStore('default')->getId();
    }
}
