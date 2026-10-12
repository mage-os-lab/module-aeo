<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Integration\Model\Feed;

use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\FlagManager;
use Magento\Store\Model\ScopeInterface;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Aeo\Model\Config as AeoConfig;
use MageOS\Aeo\Test\Integration\CommitsDeferredRequests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Review M4, decision D2a: the feeds leave NOINDEX pages out, so a change to a page's robots
 * directive queues the feed the page is in.
 *
 * A product's directive is in its MageOS_Seo override; llms.jsonl lists products. A category's is in
 * its MageOS_Seo category settings; the llms documents list categories. The events are MageOS_Seo's
 * model events, the contract between the two modules, so they are dispatched here directly.
 *
 * @magentoAppArea adminhtml
 * @magentoDbIsolation enabled
 */
class RobotsChangeInvalidationTest extends TestCase
{
    use CommitsDeferredRequests;

    private const PENDING = 'mageos_seo_feed_pending_';

    /**
     * Leave no pending request.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $flags = Bootstrap::getObjectManager()->get(FlagManager::class);
        $flags->deleteFlag(self::PENDING . 'llms');
        $flags->deleteFlag(self::PENDING . 'jsonl');
    }

    /**
     * MageOS_Seo event => the feed group whose documents list that kind of page.
     *
     * @return array<string, array{string, string}>
     */
    public static function robotsEvents(): array
    {
        return [
            'product override saved'   => ['mageos_seo_product_override_save_after', 'jsonl'],
            'product override deleted' => ['mageos_seo_product_override_delete_after', 'jsonl'],
            'category config saved'    => ['mageos_seo_category_config_save_after', 'llms'],
            'category config deleted'  => ['mageos_seo_category_config_delete_after', 'llms'],
        ];
    }

    /**
     * llms.jsonl is switched on: off, as it is by default, no store view can build it, and a feed no
     * store view can build is never queued. The llms documents are on by default.
     *
     * @dataProvider robotsEvents
     * @param string $eventName
     * @param string $group
     * @return void
     */
    #[DataProvider('robotsEvents')]
    #[Config(AeoConfig::XML_LLMS_JSONL_ENABLED, '1', ScopeInterface::SCOPE_STORE, 'default')]
    public function testARobotsChangeQueuesTheFeedThePageIsIn(string $eventName, string $group): void
    {
        $flags = Bootstrap::getObjectManager()->get(FlagManager::class);
        // Requests made inside the test's transaction wait for a commit that never comes: its
        // setup's are run and cleared first, so only the event's own count.
        $this->commitDeferredRequests();
        $flags->deleteFlag(self::PENDING . $group);

        Bootstrap::getObjectManager()->get(ManagerInterface::class)->dispatch($eventName);
        $this->commitDeferredRequests();

        $this->assertNotNull($flags->getFlagData(self::PENDING . $group), 'No rebuild of ' . $group . ' was queued.');
    }
}
