<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Unit\Model\LlmsJsonl;

use Magento\Catalog\Model\Product;
use Magento\Framework\Pricing\Amount\AmountInterface;
use Magento\Framework\Pricing\Price\PriceInterface;
use Magento\Framework\Pricing\PriceInfoInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Aeo\Model\LlmsJsonl\ProductLineBuilder;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Product\AvailabilityResolver;
use MageOS\Seo\Model\Product\FinalPrice;
use MageOS\Seo\Service\CurrencyService;
use MageOS\Seo\Test\Unit\Service\CurrencyServices;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProductLineBuilderTest extends TestCase
{
    use CurrencyServices;

    /**
     * @var Product&Stub
     */
    private Product&Stub $product;

    /**
     * @var ProductLineBuilder
     */
    private ProductLineBuilder $builder;

    protected function setUp(): void
    {
        $displayed = $this->createStub(AmountInterface::class);
        $displayed->method('getValue')->willReturn(29.99);
        $finalPrice = $this->createStub(PriceInterface::class);
        $finalPrice->method('getValue')->willReturn(29.99);
        $finalPrice->method('getAmount')->willReturn($displayed);
        $priceInfo = $this->createMock(PriceInfoInterface::class);
        $priceInfo->method('getPrice')->with('final_price')->willReturn($finalPrice);

        $this->product = $this->createStub(Product::class);
        $this->product->method('getPriceInfo')->willReturn($priceInfo);
        $this->product->method('getName')->willReturn('Blue Mug');
        $this->product->method('getSku')->willReturn('MUG-001');
        $this->product->method('getProductUrl')->willReturn('https://example.com/blue-mug.html');

        $this->builder = $this->builder();
    }

    /**
     * The line builder over the given store and currency service, or a stub store and a real
     * CurrencyService in GBP.
     *
     * @param Store|null $store
     * @param CurrencyService|null $currencyService
     * @return ProductLineBuilder
     */
    private function builder(?Store $store = null, ?CurrencyService $currencyService = null): ProductLineBuilder
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store ?? $this->createStub(Store::class));

        return new ProductLineBuilder(
            $storeManager,
            $currencyService ?? $this->currencyService(),
            new FinalPrice($this->createStub(LoggerInterface::class), $this->createStub(Config::class))
        );
    }

    /**
     * A price that is not known is left out with its currency, rather than written as 0.00; the
     * offer keeps its availability and URL. Here, a lookup that throws.
     */
    public function testAnUnknownPriceIsLeftOutOfTheOffer(): void
    {
        // Built on its own: a stubbed method keeps the first behaviour it is given.
        $product = $this->createStub(Product::class);
        $product->method('getPriceInfo')->willThrowException(new \RuntimeException('No price index'));
        $product->method('getProductUrl')->willReturn('https://example.com/blue-mug.html');

        $offer = $this->builder->build($product, AvailabilityResolver::OUT_OF_STOCK)['offers'];

        $this->assertSame(
            [
                '@type'        => 'Offer',
                'availability' => 'https://schema.org/OutOfStock',
                'url'          => 'https://example.com/blue-mug.html',
            ],
            $offer
        );
    }

    public function testBuildsRequiredFields(): void
    {
        $node = $this->builder->build($this->product, AvailabilityResolver::IN_STOCK);

        $this->assertSame('https://schema.org', $node['@context']);
        $this->assertSame('Product', $node['@type']);
        $this->assertSame('https://example.com/blue-mug.html', $node['@id']);
        $this->assertSame('Blue Mug', $node['name']);
        $this->assertSame('MUG-001', $node['sku']);
        $this->assertSame('Offer', $node['offers']['@type']);
        $this->assertSame('29.99', $node['offers']['price']);
        $this->assertSame('GBP', $node['offers']['priceCurrency']);
        $this->assertSame('https://schema.org/InStock', $node['offers']['availability']);
    }

    public function testPriceInfoAmountIsNotConvertedASecondTime(): void
    {
        // PriceInfo amounts are already in the display currency; a base→display rate
        // of 2 must not be applied to them again.
        $node = $this->builder(currencyService: $this->currencyService('GBP', 2.0))
            ->build($this->product, AvailabilityResolver::IN_STOCK);

        $this->assertSame('29.99', $node['offers']['price']);
    }

    public function testTheAvailabilityGivenIsTheOffersAvailability(): void
    {
        // Availability is supplied by the caller (resolved a page at a time in JsonlBuilder),
        // never derived from the product itself; issue #30: a line can say BackOrder.
        foreach ([
            AvailabilityResolver::IN_STOCK,
            AvailabilityResolver::BACKORDER,
            AvailabilityResolver::OUT_OF_STOCK,
        ] as $availability) {
            $node = $this->builder->build($this->product, $availability);
            $this->assertSame($availability, $node['offers']['availability']);
        }
    }

    public function testLongDescriptionIsCutAtAWordBoundaryOnOneLine(): void
    {
        $long = "<p>Hand thrown\n\n   stoneware</p>" . str_repeat(' glazed mug', 40);
        $this->product->method('__call')->willReturnCallback(
            fn (string $m) => $m === 'getShortDescription' ? $long : null
        );

        $description = $this->builder->build($this->product, AvailabilityResolver::IN_STOCK)['description'];

        $this->assertStringStartsWith('Hand thrown stoneware glazed mug', $description);
        $this->assertStringEndsWith('…', $description);
        $this->assertLessThanOrEqual(301, mb_strlen($description));
        $this->assertMatchesRegularExpression('/(glazed|mug)…$/', $description, 'No split word');
        $this->assertStringNotContainsString("\n", $description);
    }

    public function testDescriptionStrippedAndTruncated(): void
    {
        $this->product->method('__call')->willReturnCallback(
            fn (string $m) => $m === 'getShortDescription' ? '<p>A <strong>great</strong> mug</p>' : null
        );
        $node = $this->builder->build($this->product, AvailabilityResolver::IN_STOCK);
        $this->assertSame('A great mug', $node['description']);
    }

    public function testImageBuiltFromMediaUrl(): void
    {
        $this->product->method('getImage')->willReturn('/m/u/mug.jpg');
        $store = $this->createMock(Store::class);
        $store->method('getBaseUrl')->with(UrlInterface::URL_TYPE_MEDIA)
            ->willReturn('https://example.com/media/');

        $node = $this->builder($store)->build($this->product, AvailabilityResolver::IN_STOCK);
        $this->assertSame('https://example.com/media/catalog/product/m/u/mug.jpg', $node['image']);
    }

    public function testImageOmittedWhenNoSelection(): void
    {
        $this->product->method('getImage')->willReturn('no_selection');
        $node = $this->builder->build($this->product, AvailabilityResolver::IN_STOCK);
        $this->assertArrayNotHasKey('image', $node);
    }
}
