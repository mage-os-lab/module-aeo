<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Integration\Model\LlmsJsonl;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\Framework\Registry;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Aeo\Model\LlmsJsonl\JsonlBuilder;
use MageOS\Seo\Model\Product\AvailabilityResolver;
use PHPUnit\Framework\TestCase;

/**
 * Each /llms.jsonl line offers the price a shopper is shown and says what the stock index says.
 *
 * Issue #5: the collection never loaded the special price, so PriceInfo fell back to the regular
 * price. Issue #30: a line could say InStock or OutOfStock only.
 *
 * Database isolation is off so the saves' commit callbacks run, and with them the price and stock
 * indexers; the feed only lists products the price index has. The fixtures remove themselves.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation disabled
 */
class JsonlPriceAndAvailabilityTest extends TestCase
{
    /**
     * A special price in effect is the price; one that has ended or not yet begun is not.
     *
     * The products are made here rather than by attributes, whose arguments cannot hold today's date.
     *
     * @return void
     */
    public function testALineIsPricedAsTheStorefrontPricesIt(): void
    {
        $fixture  = Bootstrap::getObjectManager()->get(ProductFixture::class);
        $day      = static fn (int $days): string => date('Y-m-d', strtotime($days . ' days'));
        $products = [
            'active'  => $fixture->apply(['price' => 100, 'special_price' => 70,
                'special_from_date' => $day(-10), 'special_to_date' => $day(10)]),
            'expired' => $fixture->apply(['price' => 100, 'special_price' => 70,
                'special_from_date' => $day(-20), 'special_to_date' => $day(-10)]),
            'future'  => $fixture->apply(['price' => 100, 'special_price' => 70,
                'special_from_date' => $day(10), 'special_to_date' => $day(20)]),
        ];

        try {
            $offers = $this->offers();
            foreach (['active' => '70.00', 'expired' => '100.00', 'future' => '100.00'] as $name => $expected) {
                $sku = (string) $products[$name]->getSku();
                $this->assertSame($expected, $offers[$sku]['price'] ?? null, $name);
                $this->assertSame($this->storefrontPrice($sku), $offers[$sku]['price'] ?? null, $name);
            }
        } finally {
            // Outside the admin, core deletes a product only in a secure area, as the test framework
            // sets one up for the fixtures it reverts itself.
            $registry = Bootstrap::getObjectManager()->get(Registry::class);
            $registry->unregister('isSecureArea');
            $registry->register('isSecureArea', true);
            try {
                foreach ($products as $product) {
                    $fixture->revert($product);
                }
            } finally {
                $registry->unregister('isSecureArea');
            }
        }
    }

    /**
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'onHand')]
    #[DataFixture(
        ProductFixture::class,
        ['extension_attributes' => ['stock_item' => [
            'qty' => 0, 'is_in_stock' => true, 'backorders' => 1, 'use_config_backorders' => false,
        ]]],
        'backorder'
    )]
    public function testALineSaysWhetherItsProductIsOnBackorder(): void
    {
        $offers = $this->offers();

        $this->assertSame(AvailabilityResolver::IN_STOCK, $offers[$this->sku('onHand')]['availability'] ?? null);
        $this->assertSame(AvailabilityResolver::BACKORDER, $offers[$this->sku('backorder')]['availability'] ?? null);
    }

    /**
     * The feed's offers for the current store, by SKU.
     *
     * @return array<string, array<string, mixed>>
     */
    private function offers(): array
    {
        $offers = [];
        foreach (Bootstrap::getObjectManager()->create(JsonlBuilder::class)->stream() as $line) {
            $node = json_decode($line, true);
            if (\is_array($node) && isset($node['sku'])) {
                $offers[(string) $node['sku']] = $node['offers'] ?? [];
            }
        }

        return $offers;
    }

    /**
     * The final price a fully loaded product shows, as the feed formats prices.
     *
     * @param string $sku
     * @return string
     */
    private function storefrontPrice(string $sku): string
    {
        $product = Bootstrap::getObjectManager()->get(ProductRepositoryInterface::class)->get($sku, false, 1, true);

        $amount = $product->getPriceInfo()->getPrice('final_price')->getAmount()->getValue();

        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * @param string $name
     * @return string
     */
    private function sku(string $name): string
    {
        return (string) DataFixtureStorageManager::getStorage()->get($name)->getSku();
    }
}
