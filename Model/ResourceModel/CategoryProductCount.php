<?php

declare(strict_types=1);

namespace MageOS\Aeo\Model\ResourceModel;

use Magento\Catalog\Model\Indexer\Category\Product\AbstractAction;
use Magento\Catalog\Model\Indexer\Category\Product\TableMaintainer;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\Model\ResourceModel\Db\Context;
use MageOS\Seo\Model\ResourceModel\AbstractConnectedResource;

/**
 * How many products each category page lists, for the category tree in /llms-full.txt.
 *
 * Read from the store view's category product index, the table the category page itself reads:
 * products that are enabled, in the store view's website and visible in the catalogue, an anchor
 * category's including those of its subcategories. One grouped query for the whole tree.
 *
 * Core's Category\Collection::loadProductCount() is not used: it counts a category that is not an
 * anchor by its raw assignments (disabled products and a configurable's children included) and an
 * anchor one from this index, with a query of its own for each anchor the index has nothing for.
 *
 * Stock is not taken into account: the index has no stock column. With Display Out of Stock
 * Products at No, the page can list fewer.
 */
class CategoryProductCount extends AbstractConnectedResource
{
    /**
     * @param TableMaintainer $tableMaintainer
     * @param Visibility $visibility
     * @param Context $context
     * @param string|null $connectionName
     */
    public function __construct(
        private readonly TableMaintainer $tableMaintainer,
        private readonly Visibility $visibility,
        Context $context,
        ?string $connectionName = null
    ) {
        parent::__construct($context, $connectionName);
    }

    /**
     * Initialize the main table and primary key.
     *
     * The table of the default store dimension; countListed() reads the store view's own.
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(AbstractAction::MAIN_INDEX_TABLE, 'category_id');
    }

    /**
     * Products listed per category in the store view: category ID => count.
     *
     * A category that lists nothing is left out.
     *
     * @param int $storeId
     * @param int[] $categoryIds
     * @return array<int, int>
     */
    public function countListed(int $storeId, array $categoryIds): array
    {
        if ($categoryIds === []) {
            return [];
        }

        $connection = $this->connection();
        $select     = $connection->select()
            ->from(
                $this->tableMaintainer->getMainTable($storeId),
                ['category_id', 'listed' => new \Zend_Db_Expr('COUNT(DISTINCT product_id)')]
            )
            ->where('category_id IN (?)', array_map('intval', $categoryIds))
            ->where('visibility IN (?)', $this->visibility->getVisibleInCatalogIds())
            ->group('category_id');

        $counts = [];
        foreach ($connection->fetchPairs($select) as $categoryId => $listed) {
            $counts[(int) $categoryId] = (int) $listed;
        }

        return $counts;
    }
}
