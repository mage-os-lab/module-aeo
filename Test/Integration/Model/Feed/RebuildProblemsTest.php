<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Integration\Model\Feed;

use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Framework\FlagManager;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\InventorySalesApi\Api\AreProductsSalableInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Aeo\Model\Feed\FeedRegenerator;
use MageOS\Aeo\Model\Feed\FeedStorage;
use MageOS\Aeo\Model\LlmsJsonl\JsonlBuilder;
use MageOS\Seo\Model\Rebuild\ProblemFormatter;
use MageOS\Seo\Model\Rebuild\ProblemLog;
use MageOS\Seo\Model\Rebuild\RetrySchedule;
use PHPUnit\Framework\TestCase;

/**
 * The llms documents' rebuild problems reach the admin: by their file names, retried by the
 * nightly cron, and an incomplete llms.jsonl says why.
 *
 * @magentoAppArea adminhtml
 * @magentoDbIsolation enabled
 */
class RebuildProblemsTest extends TestCase
{
    /**
     * @return void
     */
    protected function setUp(): void
    {
        Bootstrap::getObjectManager()->get(FlagManager::class)->deleteFlag(ProblemLog::FLAG);
    }

    /**
     * Remove the feed files the tests wrote; the storage directory is not rolled back.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $storage = Bootstrap::getObjectManager()->get(FeedStorage::class);
        $storage->deleteForStore('llms*', $this->storeId());
        $storage->deleteForStore('.*.tmp', $this->storeId());
    }

    /**
     * The retry time is the nightly job's next run, read from this module's crontab.xml through
     * core's cron configuration.
     *
     * @return void
     */
    public function testAFeedIsRetriedByTheNightlyCron(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $next          = $objectManager->get(RetrySchedule::class)->nextAttempt(FeedRegenerator::GROUP_JSONL);
        $zone          = (string) $objectManager->get(TimezoneInterface::class)->getConfigTimezone();

        $this->assertNotNull($next, 'No scheduled retry was found for llms.jsonl.');
        $local = (new \DateTimeImmutable('@' . $next))->setTimezone(new \DateTimeZone($zone));
        $this->assertSame('02:30', $local->format('H:i'));
        $this->assertLessThanOrEqual(86400, $next - time(), 'The next run is within a day.');
    }

    /**
     * When the stock lookup fails, every product of the batch is written as out of stock: the file
     * is served, and the admin is told it is incomplete until a rebuild gets through.
     *
     * @return void
     */
    #[Config('mageos_aeo/llms_txt/jsonl_enabled', 1, ScopeInterface::SCOPE_STORE, 'default')]
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testAFailedStockLookupShowsLlmsJsonlAsIncompleteUntilACleanRebuild(): void
    {
        $failing = $this->createStub(AreProductsSalableInterface::class);
        $failing->method('execute')->willThrowException(new \RuntimeException('Inventory is down'));

        $this->regeneratorWith($failing)->regenerate(FeedRegenerator::GROUP_JSONL);

        $problems = $this->problemLog()->all();
        $this->assertSame(ProblemLog::KIND_DEGRADED, $problems['jsonl'][$this->storeId()]['kind'] ?? null);

        $line = Bootstrap::getObjectManager()->get(ProblemFormatter::class)
            ->line('jsonl', (string) $this->storeId(), $problems['jsonl'][$this->storeId()], false);
        $this->assertStringStartsWith('llms.jsonl for store "Default Store View" is incomplete (since ', $line);
        $this->assertStringContainsString('It will be retried automatically at ', $line);
        $this->assertStringEndsWith(
            'Reason: The stock lookup failed, so some products are listed as out of stock.',
            $line
        );

        Bootstrap::getObjectManager()->create(FeedRegenerator::class)->regenerate(FeedRegenerator::GROUP_JSONL);

        $this->assertArrayNotHasKey('jsonl', $this->problemLog()->all());
    }

    /**
     * A regenerator whose llms.jsonl builder asks the given salability service.
     *
     * @param AreProductsSalableInterface $areProductsSalable
     * @return FeedRegenerator
     */
    private function regeneratorWith(AreProductsSalableInterface $areProductsSalable): FeedRegenerator
    {
        $objectManager = Bootstrap::getObjectManager();
        $jsonlBuilder  = $objectManager->create(JsonlBuilder::class, ['areProductsSalable' => $areProductsSalable]);

        return $objectManager->create(FeedRegenerator::class, ['jsonlBuilder' => $jsonlBuilder]);
    }

    /**
     * @return ProblemLog
     */
    private function problemLog(): ProblemLog
    {
        return Bootstrap::getObjectManager()->get(ProblemLog::class);
    }

    /**
     * @return int
     */
    private function storeId(): int
    {
        return (int) Bootstrap::getObjectManager()->get(StoreManagerInterface::class)->getStore('default')->getId();
    }
}
