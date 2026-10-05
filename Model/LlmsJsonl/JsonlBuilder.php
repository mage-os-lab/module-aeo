<?php

declare(strict_types=1);

namespace MageOS\Aeo\Model\LlmsJsonl;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Aeo\Api\JsonlLineProviderInterface;
use MageOS\Aeo\Model\Feed\FeedRegenerator;
use MageOS\Aeo\Model\ResourceModel\StockIndexSalability;
use MageOS\Seo\Model\Product\AvailabilityResolver;
use MageOS\Seo\Model\Rebuild\ProblemLog;
use Psr\Log\LoggerInterface;

/**
 * Builds the /llms.jsonl document: one JSON-LD Product node per line for the store's catalog, plus
 * any lines contributed by bridge JsonlLineProviderInterface implementations.
 *
 * The catalog is processed in pages, with URL rewrites loaded with the collection and salability read
 * from the stock index in one query per page (StockIndexSalability), so large catalogs neither
 * exhaust memory nor run a salability lookup per product. Each product's price is still its own
 * PriceInfo, the price a visitor is shown.
 */
class JsonlBuilder
{
    private const PAGE_SIZE = 1000;

    /**
     * @param CollectionFactory $collectionFactory
     * @param ProductLineBuilder $productLineBuilder
     * @param StoreManagerInterface $storeManager
     * @param StockIndexSalability $stockIndexSalability
     * @param AvailabilityResolver $availabilityResolver
     * @param LoggerInterface $logger
     * @param ProblemLog $problemLog
     * @param array<mixed> $lineProviders
     */
    public function __construct(
        private readonly CollectionFactory     $collectionFactory,
        private readonly ProductLineBuilder    $productLineBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly StockIndexSalability  $stockIndexSalability,
        private readonly AvailabilityResolver  $availabilityResolver,
        private readonly LoggerInterface       $logger,
        private readonly ProblemLog            $problemLog,
        private readonly array                 $lineProviders = []
    ) {
    }

    /**
     * Stream the NDJSON document for the current store, one line at a time.
     *
     * The document is never assembled in memory: at 100k SKUs it runs to tens of megabytes.
     *
     * @return \Generator<string> Lines, each ending in a newline
     */
    public function stream(): \Generator
    {
        $storeId = (int) $this->storeManager->getStore()->getId();

        $collection = $this->collectionFactory->create();
        $collection->setStoreId($storeId);
        $collection->addStoreFilter($storeId);
        $collection->addAttributeToSelect(['name', 'short_description', 'description', 'sku', 'image']);
        $collection->addAttributeToFilter('status', ['eq' => Status::STATUS_ENABLED]);
        $collection->addAttributeToFilter('visibility', [
            'in' => [Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_BOTH],
        ]);
        $collection->addFinalPrice();
        // Loads request paths with the collection so getProductUrl() never falls back
        // to a per-product url_rewrite lookup.
        $collection->addUrlRewrite();
        $collection->setPageSize(self::PAGE_SIZE);

        $lastPage = $collection->getLastPageNumber();

        for ($page = 1; $page <= $lastPage; $page++) {
            $collection->setCurPage($page);
            $collection->clear();

            // One salability query per page instead of a lookup per product.
            $skus = [];
            foreach ($collection as $product) {
                if ($product instanceof ProductInterface && (string) $product->getSku() !== '') {
                    $skus[] = (string) $product->getSku();
                }
            }
            $salability = $this->resolveSalability($skus, $storeId);

            foreach ($collection as $product) {
                if (!$product instanceof ProductInterface) {
                    continue;
                }
                $line = $this->encode($this->productLineBuilder->build(
                    $product,
                    $salability[(string) $product->getSku()] ?? false
                ));
                if ($line !== '') {
                    yield $line . "\n";
                }
            }
        }

        foreach ($this->lineProviders as $provider) {
            if (!$provider instanceof JsonlLineProviderInterface) {
                continue;
            }
            foreach ($provider->getAdditionalLines($storeId) as $node) {
                $line = $this->encode($node);
                if ($line !== '') {
                    yield $line . "\n";
                }
            }
        }
    }

    /**
     * Resolve salability for a page of SKUs from the stock index, in one query.
     *
     * A SKU the stock index has no row for is not salable. When the lookup fails, every product
     * in the page is reported not salable (matching AvailabilityResolver's OutOfStock default) and
     * the failure is logged rather than aborting the whole feed build. The admin is shown the store
     * view's llms.jsonl as incomplete until a rebuild gets through.
     *
     * @param string[] $skus
     * @param int $storeId
     * @return array<string, bool> sku => salable
     */
    private function resolveSalability(array $skus, int $storeId): array
    {
        if (empty($skus)) {
            return [];
        }

        $salability = [];
        try {
            $salability = $this->stockIndexSalability->salable(
                $skus,
                $this->availabilityResolver->getCurrentStockId()
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'MageOS_Aeo: llms.jsonl salability batch failed: ' . $e->getMessage(),
                ['exception' => $e]
            );
            $this->problemLog->degraded(
                FeedRegenerator::GROUP_JSONL,
                $storeId,
                __('The stock lookup failed, so some products are listed as out of stock.')
            );
        }

        return $salability;
    }

    /**
     * Encode one node to a compact JSON line, or empty string on failure.
     *
     * A node that cannot be encoded (invalid UTF-8 in a product name, say) is left out rather than
     * failing the whole feed, and logged as a notice so the missing line can be traced.
     *
     * @param array<string,mixed> $node
     * @return string
     */
    private function encode(array $node): string
    {
        $json = json_encode($node, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $this->logger->notice(
                'MageOS_Aeo: llms.jsonl left out a line that could not be encoded as JSON: ' . json_last_error_msg(),
                ['id' => $node['@id'] ?? null, 'sku' => $node['sku'] ?? null]
            );
            return '';
        }

        return $json;
    }
}
