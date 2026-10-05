<?php

declare(strict_types=1);

namespace MageOS\Aeo\Observer;

use Magento\Config\Model\ResourceModel\Config\Data as ConfigDataResource;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value as ConfigValue;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use MageOS\Aeo\Model\Feed\DocumentRetirement;
use MageOS\Aeo\Model\Feed\FeedConfigDependencies;
use MageOS\Seo\Model\Rebuild\Invalidator;

/**
 * Brings the feeds in line with a saved or deleted configuration value, once it is committed (issue #4).
 *
 * - A document's switch: the document is retired for the store views under the value's scope
 *   (Model\Feed\DocumentRetirement), whichever way it was switched.
 * - A value a feed shows: each feed showing it is rebuilt, which purges its cached responses once
 *   the new files are in place.
 *
 * Nothing here reads configuration. While the save runs, the configuration in memory is the old
 * one — the config model reloads it only after its transaction — so whether a group is enabled
 * cannot be answered yet; a switch is acted on without asking. Whether the value changed is the
 * value model's own comparison with the stored one, made during the save as core makes it, and the
 * work waits for the commit, so a save that is rolled back changes nothing.
 *
 * The admin's configuration pages and `bin/magento config:set` save through the value models. A
 * value model run outside a transaction — `app:config:import` calls afterSave() with no save — is
 * acted on at once, as there is no commit to wait for. Values locked into app/etc/env.php never
 * reach the database, and so are not seen.
 */
class RefreshFeedsOnConfigChange implements ObserverInterface
{
    /**
     * @param FeedConfigDependencies $dependencies
     * @param DocumentRetirement $documentRetirement
     * @param Invalidator $invalidator
     * @param ConfigDataResource $configDataResource
     */
    public function __construct(
        private readonly FeedConfigDependencies $dependencies,
        private readonly DocumentRetirement     $documentRetirement,
        private readonly Invalidator            $invalidator,
        private readonly ConfigDataResource     $configDataResource
    ) {
    }

    /**
     * Schedule the feeds' update for the commit when the value is one they depend on and it changed.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        $value = $event->getData('data_object');
        if (!$value instanceof ConfigValue) {
            return;
        }

        $path     = (string) $value->getData('path');
        $document = $this->dependencies->documentSwitchedBy($path);
        $groups   = $this->dependencies->groupsShowing($path);
        if ($document === null && $groups === []) {
            return;
        }

        // The admin saves every field of a section, changed or not; a deletion always changes.
        $deleted = str_ends_with((string) $event->getName(), '_delete_after');
        if (!$deleted && !$value->isValueChanged()) {
            return;
        }

        $scope   = (string) ($value->getScope() ?: ScopeConfigInterface::SCOPE_TYPE_DEFAULT);
        $scopeId = (int) $value->getScopeId();

        $refresh = function () use ($document, $groups, $scope, $scopeId): void {
            if ($document !== null) {
                $this->documentRetirement->retire($document, $scope, $scopeId);
            }
            foreach ($groups as $group) {
                $this->invalidator->invalidate($group);
            }
        };

        // Without a connection there is no transaction either, so nothing to wait for.
        $connection = $this->configDataResource->getConnection();
        if ($connection === false || $connection->getTransactionLevel() === 0) {
            $refresh();
            return;
        }
        $this->configDataResource->addCommitCallback($refresh);
    }
}
