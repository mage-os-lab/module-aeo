<?php

declare(strict_types=1);

namespace MageOS\Aeo\Observer;

use Magento\Config\Model\ResourceModel\Config\Data as ConfigDataResource;
use Magento\Framework\App\Config\Value as ConfigValue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Robots\Model\Config\Value as RobotsConfigValue;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Aeo\Model\Feed\FeedCache;

/**
 * Purges every store view's cached robots.txt when the AI crawler settings change (review M5).
 *
 * Core caches robots.txt under `robots_<store ID>` (Robots\Block\Data), and only its own Design
 * setting's value model purged it: the old directives were served until the whole page cache was
 * flushed. The two settings are default-scope only, and every store view's robots.txt carries them,
 * so every store view's is purged.
 *
 * Only a changed value, or a deleted one, counts, and the purge waits for the commit, as
 * RefreshFeedsOnConfigChange's work does: purged before it, a request could cache the old directives
 * again.
 */
class PurgeRobotsTxtOnConfigChange implements ObserverInterface
{
    /**
     * The settings robots.txt carries.
     */
    private const PATH_PREFIX = 'mageos_aeo/ai_robots/';

    /**
     * @param StoreManagerInterface $storeManager
     * @param FeedCache $feedCache
     * @param ConfigDataResource $configDataResource
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly FeedCache             $feedCache,
        private readonly ConfigDataResource    $configDataResource
    ) {
    }

    /**
     * Schedule the purge for the commit when an AI crawler setting changed.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        $value = $event->getData('data_object');
        if (!$value instanceof ConfigValue || !str_starts_with((string) $value->getData('path'), self::PATH_PREFIX)) {
            return;
        }

        // The admin saves every field of a section, changed or not; a deletion always changes.
        $deleted = str_ends_with((string) $event->getName(), '_delete_after');
        if (!$deleted && !$value->isValueChanged()) {
            return;
        }

        $purge = function (): void {
            $tags = [];
            foreach ($this->storeManager->getStores() as $store) {
                $tags[] = RobotsConfigValue::CACHE_TAG . '_' . (int) $store->getId();
            }
            $this->feedCache->purgeTags($tags);
        };

        // Without a connection there is no transaction either, so nothing to wait for.
        $connection = $this->configDataResource->getConnection();
        if ($connection === false || $connection->getTransactionLevel() === 0) {
            $purge();
            return;
        }
        $this->configDataResource->addCommitCallback($purge);
    }
}
