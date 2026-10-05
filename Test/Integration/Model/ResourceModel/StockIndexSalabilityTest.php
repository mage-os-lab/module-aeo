<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Integration\Model\ResourceModel;

use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Aeo\Model\ResourceModel\StockIndexSalability;
use MageOS\Seo\Model\Product\AvailabilityResolver;
use PHPUnit\Framework\TestCase;

/**
 * Salability for /llms.jsonl is read from the stock index for a whole page of SKUs at once, and
 * agrees with the stock the products were saved with.
 *
 * Database isolation is off so the saves' commit callbacks run, and with them the inventory indexer.
 * The fixtures remove themselves.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation disabled
 */
class StockIndexSalabilityTest extends TestCase
{
    /**
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'inStock')]
    #[DataFixture(
        ProductFixture::class,
        ['extension_attributes' => ['stock_item' => ['qty' => 0, 'is_in_stock' => false]]],
        'outOfStock'
    )]
    public function testEachSkuOfThePageIsSalableAsItsStockIs(): void
    {
        $inStock    = (string) DataFixtureStorageManager::getStorage()->get('inStock')->getSku();
        $outOfStock = (string) DataFixtureStorageManager::getStorage()->get('outOfStock')->getSku();
        $stockId    = Bootstrap::getObjectManager()->get(AvailabilityResolver::class)->getCurrentStockId();

        $salable = Bootstrap::getObjectManager()->get(StockIndexSalability::class)
            ->salable([$inStock, $outOfStock, 'no-such-sku-' . uniqid()], $stockId);

        $this->assertTrue($salable[$inStock] ?? null);
        $this->assertFalse($salable[$outOfStock] ?? null);
        $this->assertCount(2, $salable, 'A SKU the index does not know is left out.');
    }

    /**
     * @return void
     */
    public function testNoSkusAskForNothing(): void
    {
        $this->assertSame([], Bootstrap::getObjectManager()->get(StockIndexSalability::class)->salable([], 1));
    }
}
