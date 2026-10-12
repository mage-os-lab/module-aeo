<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Unit\Model\LlmsJsonl;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Type\AbstractType;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Aeo\Model\LlmsJsonl\ProductLineBuilder;
use MageOS\Seo\Api\Product\AvailabilityResolverInterface;
use MageOS\Seo\Api\Product\FinalPriceInterface;
use MageOS\Seo\Api\Product\OfferSummaryInterface;
use MageOS\Seo\Api\Product\VariantImageInterface;
use MageOS\Seo\Api\SchemaPriceInterface;
use PHPUnit\Framework\TestCase;

/**
 * A llms.jsonl line's offer says what the product page's offer says.
 *
 * Review M4: a configurable whose children are priced differently was a single Offer here, and took
 * its availability from its own stock index row, while its page had an AggregateOffer from the
 * lowest to the highest child and its best child's availability. A composite product's offer now
 * comes from MageOS_Seo's offer summary, the numbers the page's offer is built from.
 *
 * Decision D8a: a stock lookup that failed leaves availability out, instead of OutOfStock.
 */
class ProductLineBuilderOfferTest extends TestCase
{
    /**
     * @return void
     */
    public function testAConfigurableWhoseChildrenDifferInPriceIsAnAggregateOffer(): void
    {
        $summary = [
            'price'        => 40.0,
            'lowPrice'     => 40.0,
            'highPrice'    => 52.0,
            'availability' => AvailabilityResolverInterface::BACKORDER,
        ];

        $line = $this->builder($summary)->build(
            $this->product(composite: true),
            AvailabilityResolverInterface::IN_STOCK
        );

        $this->assertSame(
            [
                '@type'         => 'AggregateOffer',
                'lowPrice'      => '40.00',
                'highPrice'     => '52.00',
                'priceCurrency' => 'GBP',
                'availability'  => AvailabilityResolverInterface::BACKORDER,
                'url'           => 'https://example.com/tee.html',
            ],
            $line['offers']
        );
    }

    /**
     * @return void
     */
    public function testAConfigurableWhoseChildrenShareAPriceIsAnOffer(): void
    {
        $summary = [
            'price'        => 52.0,
            'lowPrice'     => null,
            'highPrice'    => null,
            'availability' => AvailabilityResolverInterface::IN_STOCK,
        ];

        $line = $this->builder($summary)->build($this->product(composite: true), null);

        $this->assertSame('Offer', $line['offers']['@type']);
        $this->assertSame('52.00', $line['offers']['price']);
        $this->assertSame(AvailabilityResolverInterface::IN_STOCK, $line['offers']['availability']);
    }

    /**
     * A simple product keeps the availability its page of the feed resolved together, without a
     * lookup of its own.
     *
     * @return void
     */
    public function testASimpleProductIsNotSummarisedOneByOne(): void
    {
        $offerSummary = $this->createMock(OfferSummaryInterface::class);
        $offerSummary->expects($this->never())->method('forProduct');

        $line = $this->builder(offerSummary: $offerSummary)->build(
            $this->product(composite: false),
            AvailabilityResolverInterface::IN_STOCK
        );

        $this->assertSame(AvailabilityResolverInterface::IN_STOCK, $line['offers']['availability']);
        $this->assertSame('29.99', $line['offers']['price']);
    }

    /**
     * @return void
     */
    public function testAnUnknownAvailabilityIsLeftOut(): void
    {
        $line = $this->builder()->build($this->product(composite: false), null);

        $this->assertArrayNotHasKey('availability', $line['offers']);
        $this->assertSame('29.99', $line['offers']['price'], 'The rest of the offer stays.');
    }

    /**
     * PLAN-G, "G2.3 follow-up": a page of products goes to the offer summary at once, which reads
     * their variants and stock in a few queries rather than per configurable.
     *
     * @return void
     */
    public function testAPageIsHandedToTheOfferSummaryAtOnce(): void
    {
        $products     = [$this->product(composite: true), $this->product(composite: false)];
        $offerSummary = $this->createMock(OfferSummaryInterface::class);
        $offerSummary->expects($this->once())->method('prefetch')->with($products);

        $this->builder(offerSummary: $offerSummary)->prefetch($products);
    }

    /**
     * The line builder over a summary for composite products, a final price of 29.99 for the others,
     * and prices in GBP.
     *
     * @param array{price: ?float, lowPrice: ?float, highPrice: ?float, availability: ?string}|null $summary
     * @param OfferSummaryInterface|null $offerSummary
     * @return ProductLineBuilder
     */
    private function builder(?array $summary = null, ?OfferSummaryInterface $offerSummary = null): ProductLineBuilder
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($this->createStub(Store::class));
        $schemaPrice = $this->createStub(SchemaPriceInterface::class);
        $schemaPrice->method('amount')->willReturnCallback(
            static fn (float $amount): string => number_format($amount, 2, '.', '')
        );
        $schemaPrice->method('currencyCode')->willReturn('GBP');
        $finalPrice = $this->createStub(FinalPriceInterface::class);
        $finalPrice->method('get')->willReturn(29.99);
        if ($offerSummary === null) {
            $offerSummary = $this->createStub(OfferSummaryInterface::class);
            if ($summary !== null) {
                $offerSummary->method('forProduct')->willReturn($summary);
            }
        }

        return new ProductLineBuilder(
            $storeManager,
            $schemaPrice,
            $finalPrice,
            $offerSummary,
            $this->createStub(VariantImageInterface::class)
        );
    }

    /**
     * A product at its URL, of a composite type (a configurable) or not.
     *
     * @param bool $composite
     * @return Product
     */
    private function product(bool $composite): Product
    {
        $type = $this->createStub(AbstractType::class);
        $type->method('isComposite')->willReturn($composite);

        $product = $this->createStub(Product::class);
        $product->method('getTypeId')->willReturn($composite ? 'configurable' : 'simple');
        $product->method('getTypeInstance')->willReturn($type);
        $product->method('getName')->willReturn('Tee');
        $product->method('getSku')->willReturn('TEE');
        $product->method('getProductUrl')->willReturn('https://example.com/tee.html');

        return $product;
    }
}
