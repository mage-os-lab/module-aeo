<?php

declare(strict_types=1);

namespace MageOS\Aeo\Model\LlmsJsonl;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Seo\Api\Product\FinalPriceInterface;
use MageOS\Seo\Api\Product\OfferSummaryInterface;
use MageOS\Seo\Api\Product\VariantImageInterface;
use MageOS\Seo\Api\SchemaPriceInterface;

/**
 * Builds a compact JSON-LD Product node (one line of /llms.jsonl) for a single product.
 *
 * Deliberately leaner than the full product schema builders: just the fields an AI catalog consumer
 * needs. Omits empty values to keep each line small.
 *
 * A price that is not known (MageOS_Seo's FinalPriceInterface: a lookup that threw, or a composite
 * product nothing can price) is left out of the offer, with its currency, rather than written as
 * 0.00, which reads as free. The offer stays: its availability and URL are still true. So is an
 * availability that is not known (decision D8a), rather than written as OutOfStock.
 *
 * A composite product's offer is the one its page has (review M4): MageOS_Seo's offer summary gives
 * a configurable's price range across its sellable children, an AggregateOffer when they differ, and
 * its best child's availability. A simple product keeps the availability the feed resolved for its
 * whole page, with no lookup of its own.
 */
class ProductLineBuilder
{
    /**
     * Maximum description length in characters, ellipsis excluded.
     */
    private const DESCRIPTION_MAX = 300;

    /**
     * @param StoreManagerInterface $storeManager
     * @param SchemaPriceInterface $schemaPrice
     * @param FinalPriceInterface $finalPrice
     * @param OfferSummaryInterface $offerSummary
     * @param VariantImageInterface $variantImage
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly SchemaPriceInterface  $schemaPrice,
        private readonly FinalPriceInterface   $finalPrice,
        private readonly OfferSummaryInterface $offerSummary,
        private readonly VariantImageInterface $variantImage
    ) {
    }

    /**
     * Read what a page of products' offers need at once, before build() is asked for each line.
     *
     * The configurables' variants in one collection and their stock in one lookup (MageOS_Seo's
     * OfferSummaryInterface::prefetch()), where each line would otherwise read its configurable's own.
     *
     * @param ProductInterface[] $products
     * @return void
     */
    public function prefetch(array $products): void
    {
        $this->offerSummary->prefetch($products);
    }

    /**
     * Build the JSON-LD Product node for a product.
     *
     * Availability is passed in (resolved a page of products at a time by JsonlBuilder, through
     * MageOS_Seo's AvailabilityResolverInterface) rather than looked up per line: InStock, BackOrder
     * or OutOfStock, a schema.org URI, or null when the lookup failed. A composite product's comes
     * from its children instead.
     *
     * @param ProductInterface $product
     * @param string|null $availability AvailabilityResolverInterface::IN_STOCK, BACKORDER or OUT_OF_STOCK
     * @return array<string, mixed>
     */
    public function build(ProductInterface $product, ?string $availability): array
    {
        /** @var \Magento\Catalog\Model\Product $product */
        $url = $product->getProductUrl();

        $node = [
            '@context' => 'https://schema.org',
            '@type'    => 'Product',
            '@id'      => $url,
            'name'     => (string) $product->getName(),
            'url'      => $url,
            'offers'   => $this->offer($product, $availability, $url),
        ];

        $sku = (string) $product->getSku();
        if ($sku !== '') {
            $node['sku'] = $sku;
        }

        $description = $this->description($product);
        if ($description !== '') {
            $node['description'] = $description;
        }

        $image = $this->image($product);
        if ($image !== '') {
            $node['image'] = $image;
        }

        return $node;
    }

    /**
     * The product's offer: an Offer, or an AggregateOffer for a configurable priced in a range.
     *
     * A configurable whose children are priced differently gets an AggregateOffer from the lowest to
     * the highest.
     *
     * The amounts are already in the current (display) currency emitted with them: converting again
     * would apply the rate twice.
     *
     * @param \Magento\Catalog\Model\Product $product
     * @param string|null $availability
     * @param string $url
     * @return array<string, mixed>
     */
    private function offer(ProductInterface $product, ?string $availability, string $url): array
    {
        $low = $high = null;
        if ($product->getTypeInstance()->isComposite($product)) {
            $summary      = $this->offerSummary->forProduct($product);
            $price        = $summary['price'];
            $low          = $summary['lowPrice'];
            $high         = $summary['highPrice'];
            $availability = $summary['availability'];
        } else {
            $price = $this->finalPrice->get($product);
        }

        $offer = ['@type' => 'Offer'];
        if ($low !== null && $high !== null) {
            $offer['@type']         = 'AggregateOffer';
            $offer['lowPrice']      = $this->schemaPrice->amount($low);
            $offer['highPrice']     = $this->schemaPrice->amount($high);
            $offer['priceCurrency'] = $this->schemaPrice->currencyCode();
        } elseif ($price !== null) {
            $offer['price']         = $this->schemaPrice->amount($price);
            $offer['priceCurrency'] = $this->schemaPrice->currencyCode();
        }
        if ($availability !== null) {
            $offer['availability'] = $availability;
        }
        $offer['url'] = $url;

        return $offer;
    }

    /**
     * Resolve a plain-text description (short, then full), trimmed to 300 chars.
     *
     * @param \Magento\Catalog\Model\Product $product
     * @return string
     */
    private function description(ProductInterface $product): string
    {
        $raw = (string) ($product->getShortDescription() ?: $product->getDescription());
        if ($raw === '') {
            return '';
        }
        // phpcs:ignore Magento2.Functions.DiscouragedFunction.Discouraged -- plain-text from rich HTML
        $text = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // One line of text: tags leave runs of newlines and indentation behind.
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= self::DESCRIPTION_MAX) {
            return $text;
        }

        // Cut at the last word boundary so a word is never split; fall back to a hard
        // cut only for a single very long word.
        $cut   = mb_substr($text, 0, self::DESCRIPTION_MAX + 1);
        $space = mb_strrpos($cut, ' ');
        $cut   = $space !== false && $space > (int) (self::DESCRIPTION_MAX * 0.6)
            ? mb_substr($cut, 0, $space)
            : mb_substr($text, 0, self::DESCRIPTION_MAX);

        return rtrim($cut, " \t,.;:-") . '…';
    }

    /**
     * Resolve an absolute product image URL, or empty string.
     *
     * @param \Magento\Catalog\Model\Product $product
     * @return string
     */
    private function image(ProductInterface $product): string
    {
        // Not getImage(): for a configurable without its own, core loads all its variants to borrow
        // one's. MageOS_Seo lends the first sold variant's from the page already read.
        $image = (string) $this->variantImage->forProduct($product);
        if ($image === '') {
            return '';
        }

        try {
            /** @var Store $store */
            $store    = $this->storeManager->getStore();
            $mediaUrl = rtrim((string) $store->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_MEDIA), '/');
        } catch (\Exception) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch -- no media url
            return '';
        }

        return $mediaUrl . '/catalog/product' . $image;
    }
}
