<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Integration\Model\LlmsTxt;

use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Aeo\Model\LlmsTxt\LlmsTxtBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The category tree in /llms-full.txt counts what each category page lists: products that are
 * enabled and visible in the catalogue, an anchor category's including its subcategories'.
 *
 * Database isolation is off so the saves' commit callbacks run, and with them the category product
 * indexer the counts are read from. The fixtures remove themselves.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation disabled
 */
class CategoryProductCountTest extends TestCase
{
    /**
     * @return void
     */
    #[DataFixture(CategoryFixture::class, ['custom_attributes' => ['is_anchor' => '1']], 'parent')]
    #[DataFixture(
        CategoryFixture::class,
        ['parent_id' => '$parent.id$', 'custom_attributes' => ['is_anchor' => '0']],
        'shirts'
    )]
    #[DataFixture(
        CategoryFixture::class,
        ['parent_id' => '$parent.id$', 'custom_attributes' => ['is_anchor' => '0']],
        'hats'
    )]
    #[DataFixture(ProductFixture::class, ['category_ids' => ['$shirts.id$']])]
    #[DataFixture(ProductFixture::class, ['category_ids' => ['$shirts.id$']])]
    #[DataFixture(
        ProductFixture::class,
        ['category_ids' => ['$shirts.id$'], 'visibility' => Visibility::VISIBILITY_NOT_VISIBLE]
    )]
    #[DataFixture(ProductFixture::class, ['category_ids' => ['$shirts.id$'], 'status' => Status::STATUS_DISABLED])]
    #[DataFixture(ProductFixture::class, ['category_ids' => ['$hats.id$']])]
    #[DataFixture(ProductFixture::class, ['category_ids' => ['$hats.id$']])]
    public function testEachCategoryCountsWhatItsPageLists(): void
    {
        $document = Bootstrap::getObjectManager()->get(LlmsTxtBuilder::class)->buildFull();

        // The product not visible on its own and the disabled one are not listed.
        $this->assertProductCount('shirts', 2, $document);
        $this->assertProductCount('hats', 2, $document);
        // An anchor category lists its subcategories' products.
        $this->assertProductCount('parent', 4, $document);
    }

    /**
     * Assert the category's line in the tree ends in the product count.
     *
     * @param string $fixture
     * @param int $count
     * @param string $document
     * @return void
     */
    private function assertProductCount(string $fixture, int $count, string $document): void
    {
        $name = (string) DataFixtureStorageManager::getStorage()->get($fixture)->getName();

        $this->assertMatchesRegularExpression(
            '/^ *- \[' . preg_quote($name, '/') . '\]\([^)]+\): ' . $count . ' products$/m',
            $document,
            "\"$name\" does not list $count products."
        );
    }
}
