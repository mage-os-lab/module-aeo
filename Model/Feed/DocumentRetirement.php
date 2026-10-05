<?php

declare(strict_types=1);

namespace MageOS\Aeo\Model\Feed;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Model\Rebuild\RegenerationRequester;
use Psr\Log\LoggerInterface;

/**
 * Takes a switched document out of service until its rebuild has run (issue #4).
 *
 * Its files go for every store view under the scope of the switch, its cached responses are purged,
 * and its group's rebuild is queued. Switched off, nothing can then serve the old document; switched
 * on, nothing serves a file from before — which may list products removed in the meantime — as
 * current. A store view whose own setting overrides the switch loses its file too, and answers
 * 503 or 404 until the rebuild writes it again: working out each store view's value before and
 * after is not worth it for a setting changed this rarely.
 *
 * The rebuild is queued directly, not through MageOS_Seo's Invalidator: that skips a group no store
 * view has enabled, which is exactly the state a switch-off leaves, and a rebuild of a disabled
 * group is what removes the files of store views this missed.
 *
 * @phpstan-import-type Document from FeedConfigDependencies
 */
class DocumentRetirement
{
    /**
     * @param FeedStorage $feedStorage
     * @param FeedCache $feedCache
     * @param RegenerationRequester $regenerationRequester
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly FeedStorage           $feedStorage,
        private readonly FeedCache             $feedCache,
        private readonly RegenerationRequester $regenerationRequester,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface       $logger
    ) {
    }

    /**
     * Retire a document for the store views under a configuration scope.
     *
     * @param Document $document From FeedConfigDependencies::documentSwitchedBy()
     * @param string $scope default, websites or stores, as configuration stores it
     * @param int $scopeId
     * @return void
     */
    public function retire(array $document, string $scope, int $scopeId): void
    {
        foreach ($this->storeIds($scope, $scopeId) as $storeId) {
            $this->feedStorage->deleteForStore($document['file'], $storeId);
        }

        try {
            $this->feedCache->purgeTags([$document['tag']]);
        } catch (\Throwable $e) {
            $this->logger->error(
                'MageOS_Aeo: could not purge the cached responses of a switched feed: ' . $e->getMessage(),
                ['exception' => $e, 'file' => $document['file']]
            );
        }

        $this->regenerationRequester->request($document['group']);
    }

    /**
     * The IDs of the store views a configuration scope covers.
     *
     * @param string $scope
     * @param int $scopeId
     * @return int[]
     */
    private function storeIds(string $scope, int $scopeId): array
    {
        if ($scope === ScopeInterface::SCOPE_STORES) {
            return [$scopeId];
        }

        $ids = [];
        foreach ($this->storeManager->getStores() as $store) {
            if ($scope === ScopeConfigInterface::SCOPE_TYPE_DEFAULT
                || ($scope === ScopeInterface::SCOPE_WEBSITES && (int) $store->getWebsiteId() === $scopeId)
            ) {
                $ids[] = (int) $store->getId();
            }
        }

        return $ids;
    }
}
