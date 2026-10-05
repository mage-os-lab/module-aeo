<?php

declare(strict_types=1);

namespace MageOS\Aeo\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\Context;
use Magento\InventoryIndexer\Model\StockIndexTableNameResolverInterface;
use MageOS\Seo\Model\ResourceModel\AbstractConnectedResource;

/**
 * Whether products are salable on a stock, for a page of /llms.jsonl at a time.
 *
 * Read from MSI's stock index (`is_salable`), the column the storefront's category listings filter
 * on, in one query for the whole page. MSI's AreProductsSalableInterface takes a list of SKUs but
 * checks them one at a time — six queries or so each — which made salability most of the cost of
 * building /llms.jsonl.
 *
 * The index does not subtract reservations (orders placed but not yet shipped), as the per-SKU check
 * does: a product whose last units are all reserved reads as in stock until shipping deducts them.
 */
class StockIndexSalability extends AbstractConnectedResource
{
    /**
     * @param StockIndexTableNameResolverInterface $stockIndexTableNameResolver
     * @param Context $context
     * @param string|null $connectionName
     */
    public function __construct(
        private readonly StockIndexTableNameResolverInterface $stockIndexTableNameResolver,
        Context $context,
        ?string $connectionName = null
    ) {
        parent::__construct($context, $connectionName);
    }

    /**
     * Initialize the main table and primary key.
     *
     * The default stock's index; salable() reads the given stock's own.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init('inventory_stock_1', 'sku');
    }

    /**
     * Salability per SKU on the stock: SKU => salable.
     *
     * A SKU the index has no row for is left out: it is not salable on the stock.
     *
     * @param string[] $skus
     * @param int $stockId
     * @return array<string, bool>
     */
    public function salable(array $skus, int $stockId): array
    {
        if ($skus === []) {
            return [];
        }

        $connection = $this->connection();
        $select     = $connection->select()
            ->from($this->stockIndexTableNameResolver->execute($stockId), ['sku', 'is_salable'])
            ->where('sku IN (?)', array_values($skus));

        $salable = [];
        foreach ($connection->fetchPairs($select) as $sku => $isSalable) {
            $salable[(string) $sku] = (bool) $isSalable;
        }

        return $salable;
    }
}
