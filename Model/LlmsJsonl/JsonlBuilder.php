<?php

declare(strict_types=1);

namespace MageOS\Aeo\Model\LlmsJsonl;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Aeo\Api\JsonlLineProviderInterface;
use MageOS\Aeo\Model\Config;
use MageOS\Aeo\Model\Feed\FeedRegenerator;
use MageOS\Seo\Api\Product\AvailabilityResolverInterface;
use MageOS\Seo\Api\Rebuild\ProblemLogInterface;
use MageOS\Seo\Api\Robots\PageDirectivesInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds the /llms.jsonl document: one JSON-LD Product node per line for the store's catalog, plus
 * any lines contributed by bridge JsonlLineProviderInterface implementations.
 *
 * The catalog is processed in pages, with URL rewrites loaded with the collection and availability
 * resolved for the whole page at once by MageOS_Seo's AvailabilityResolverInterface (the stock
 * index, as the storefront reads it: one or two queries a page), so large catalogs neither exhaust
 * memory nor run a stock lookup per product. Each product's price is still its own PriceInfo, the
 * price a visitor is shown, which is why the collection loads the special price attributes
 * PriceInfo reads.
 *
 * While Leave Out NOINDEX Pages is on (the default), a product whose page is NOINDEX is left out, as
 * search engines are told to (review M4): its directive is read with the page's, a page of products
 * at a time, through MageOS_Seo's PageDirectivesInterface.
 */
class JsonlBuilder
{
    private const PAGE_SIZE = 1000;

    /**
     * @param CollectionFactory $collectionFactory
     * @param ProductLineBuilder $productLineBuilder
     * @param StoreManagerInterface $storeManager
     * @param AvailabilityResolverInterface $availabilityResolver
     * @param LoggerInterface $logger
     * @param ProblemLogInterface $problemLog
     * @param Config $aeoConfig
     * @param PageDirectivesInterface $pageDirectives
     * @param array<mixed> $lineProviders
     */
    public function __construct(
        private readonly CollectionFactory             $collectionFactory,
        private readonly ProductLineBuilder            $productLineBuilder,
        private readonly StoreManagerInterface         $storeManager,
        private readonly AvailabilityResolverInterface $availabilityResolver,
        private readonly LoggerInterface               $logger,
        private readonly ProblemLogInterface           $problemLog,
        private readonly Config                        $aeoConfig,
        private readonly PageDirectivesInterface       $pageDirectives,
        private readonly array                         $lineProviders = []
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
        // The special price and its dates are what PriceInfo's SpecialPrice reads: without them the
        // regular price was published while the storefront showed the special (issue #5).
        $collection->addAttributeToSelect([
            'name', 'short_description', 'description', 'sku', 'image',
            'special_price', 'special_from_date', 'special_to_date',
        ]);
        $collection->addAttributeToFilter('status', ['eq' => Status::STATUS_ENABLED]);
        $collection->addAttributeToFilter('visibility', [
            'in' => [Visibility::VISIBILITY_IN_CATALOG, Visibility::VISIBILITY_BOTH],
        ]);
        $collection->addFinalPrice();
        // Loads request paths with the collection so getProductUrl() never falls back
        // to a per-product url_rewrite lookup.
        $collection->addUrlRewrite();
        $collection->setPageSize(self::PAGE_SIZE);

        $lastPage       = $collection->getLastPageNumber();
        $excludeNoindex = $this->aeoConfig->isNoindexExcluded($storeId);

        for ($page = 1; $page <= $lastPage; $page++) {
            $collection->setCurPage($page);
            $collection->clear();

            $products = [];
            foreach ($collection as $product) {
                if ($product instanceof ProductInterface) {
                    $products[] = $product;
                }
            }
            if ($excludeNoindex) {
                $products = $this->indexable($products, $storeId);
            }
            // The page's configurables' variants at once, instead of each configurable's on its own.
            $this->productLineBuilder->prefetch($products);
            // The page's availability at once, instead of a lookup per product; null when it failed.
            $availability = $this->resolveAvailability($products, $storeId);

            foreach ($products as $product) {
                $line = $this->encode($this->productLineBuilder->build(
                    $product,
                    $availability === null
                        ? null
                        : ($availability[(string) $product->getSku()] ?? AvailabilityResolverInterface::OUT_OF_STOCK)
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
     * The products whose pages are not NOINDEX.
     *
     * @param ProductInterface[] $products
     * @param int $storeId
     * @return ProductInterface[]
     */
    private function indexable(array $products, int $storeId): array
    {
        if ($products === []) {
            return [];
        }

        $directives = $this->pageDirectives->forProducts(
            array_map(static fn (ProductInterface $product): int => (int) $product->getId(), $products),
            $storeId
        );

        return array_values(array_filter(
            $products,
            fn (ProductInterface $product): bool
                => !$this->pageDirectives->isNoindex($directives[(int) $product->getId()] ?? '')
        ));
    }

    /**
     * Resolve the availability of a page of products at once, or null when the lookup failed.
     *
     * A product the stock index has no row for is out of stock. When the lookup fails, the page's
     * products are listed without an availability (decision D8a: not as out of stock, which nothing
     * backs) and the failure is logged rather than aborting the whole feed build. The admin is shown
     * the store view's llms.jsonl as incomplete until a rebuild gets through.
     *
     * @param ProductInterface[] $products
     * @param int $storeId
     * @return array<string, string>|null SKU => schema.org availability
     */
    private function resolveAvailability(array $products, int $storeId): ?array
    {
        if ($products === []) {
            return [];
        }

        try {
            return $this->availabilityResolver->resolveMany($products);
        } catch (\Exception $e) {
            $this->logger->error(
                'MageOS_Aeo: llms.jsonl stock lookup failed: ' . $e->getMessage(),
                ['exception' => $e]
            );
            $this->problemLog->degraded(
                FeedRegenerator::GROUP_JSONL,
                $storeId,
                __('The stock lookup failed, so some products are listed without their availability.')
            );

            return null;
        }
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
