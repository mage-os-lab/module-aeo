<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Unit\Model\Feed;

use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Aeo\Model\Feed\DocumentRetirement;
use MageOS\Aeo\Model\Feed\FeedCache;
use MageOS\Aeo\Model\Feed\FeedRegenerator;
use MageOS\Aeo\Model\Feed\FeedStorage;
use MageOS\Seo\Model\Rebuild\RegenerationRequester;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Issue #4: a switched document is taken out of service for the store views under the switch's
 * scope until its rebuild has run.
 */
class DocumentRetirementTest extends TestCase
{
    private const DOCUMENT = [
        'group' => FeedRegenerator::GROUP_JSONL,
        'file'  => 'llms.jsonl',
        'tag'   => FeedCache::TAG_LLMS_JSONL,
    ];

    /**
     * Store view IDs whose file was removed.
     *
     * @var int[]
     */
    private array $removed = [];

    public function testTheDefaultScopeCoversEveryStoreView(): void
    {
        $this->retirement()->retire(self::DOCUMENT, 'default', 0);

        $this->assertSame([1, 2, 3], $this->removed);
    }

    public function testAWebsiteScopeCoversItsStoreViews(): void
    {
        $this->retirement()->retire(self::DOCUMENT, 'websites', 1);

        $this->assertSame([1, 2], $this->removed);
    }

    public function testAStoreScopeCoversThatStoreView(): void
    {
        $this->retirement()->retire(self::DOCUMENT, 'stores', 3);

        $this->assertSame([3], $this->removed);
    }

    public function testItsCachedResponsesArePurgedAndItsRebuildQueuedWithoutAsking(): void
    {
        // Queued directly, not through the invalidator, which skips a group nothing has enabled.
        $cache = $this->createMock(FeedCache::class);
        $cache->expects($this->once())->method('purgeTags')->with([FeedCache::TAG_LLMS_JSONL]);
        $requester = $this->createMock(RegenerationRequester::class);
        $requester->expects($this->once())->method('request')->with(FeedRegenerator::GROUP_JSONL);

        $this->retirement($cache, $requester)->retire(self::DOCUMENT, 'stores', 1);
    }

    public function testAFailedPurgeIsLoggedAndTheRebuildStillQueued(): void
    {
        $cache = $this->createStub(FeedCache::class);
        $cache->method('purgeTags')->willThrowException(new \RuntimeException('Varnish is down'));
        $requester = $this->createMock(RegenerationRequester::class);
        $requester->expects($this->once())->method('request');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('Varnish is down'));

        $this->retirement($cache, $requester, $logger)->retire(self::DOCUMENT, 'stores', 1);
    }

    /**
     * A retirement over store views 1 and 2 in website 1 and store view 3 in website 2.
     *
     * @param FeedCache|null $cache
     * @param RegenerationRequester|null $requester
     * @param LoggerInterface|null $logger
     * @return DocumentRetirement
     */
    private function retirement(
        ?FeedCache $cache = null,
        ?RegenerationRequester $requester = null,
        ?LoggerInterface $logger = null
    ): DocumentRetirement {
        $stores = [];
        foreach ([1 => 1, 2 => 1, 3 => 2] as $storeId => $websiteId) {
            $store = $this->createStub(StoreInterface::class);
            $store->method('getId')->willReturn($storeId);
            $store->method('getWebsiteId')->willReturn($websiteId);
            $stores[$storeId] = $store;
        }
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn($stores);

        $storage = $this->createStub(FeedStorage::class);
        $storage->method('deleteForStore')->willReturnCallback(
            function (string $file, int $storeId): void {
                $this->assertSame('llms.jsonl', $file);
                $this->removed[] = $storeId;
            }
        );

        return new DocumentRetirement(
            $storage,
            $cache ?? $this->createStub(FeedCache::class),
            $requester ?? $this->createStub(RegenerationRequester::class),
            $storeManager,
            $logger ?? $this->createStub(LoggerInterface::class)
        );
    }
}
