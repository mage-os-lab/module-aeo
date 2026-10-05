<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Integration\Model\LlmsTxt;

use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Aeo\Model\LlmsTxt\LlmsTxtBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The category tree in /llms-full.txt follows the storefront menu: what Include in Menu leaves out
 * is left out with its subcategories, and categories come in the menu's order.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation disabled
 */
class CategoryTreeMenuTest extends TestCase
{
    /**
     * @return void
     */
    #[DataFixture(CategoryFixture::class, ['position' => 2], 'second')]
    #[DataFixture(CategoryFixture::class, ['position' => 1], 'first')]
    public function testCategoriesComeInTheMenusOrder(): void
    {
        $document = $this->document();

        // Created the other way round, so their IDs, and paths, run against their positions.
        $this->assertLessThan(
            strpos($document, '[' . $this->categoryName('second') . ']'),
            strpos($document, '[' . $this->categoryName('first') . ']')
        );
    }

    /**
     * @return void
     */
    #[DataFixture(CategoryFixture::class, ['include_in_menu' => false], 'hidden')]
    #[DataFixture(CategoryFixture::class, ['parent_id' => '$hidden.id$'], 'underHidden')]
    #[DataFixture(CategoryFixture::class, as: 'shown')]
    public function testACategoryLeftOutOfTheMenuIsLeftOutWithItsSubcategories(): void
    {
        $document = $this->document();

        $this->assertStringContainsString('[' . $this->categoryName('shown') . ']', $document);
        $this->assertStringNotContainsString('[' . $this->categoryName('hidden') . ']', $document);
        $this->assertStringNotContainsString('[' . $this->categoryName('underHidden') . ']', $document);
    }

    /**
     * @return string
     */
    private function document(): string
    {
        return Bootstrap::getObjectManager()->get(LlmsTxtBuilder::class)->buildFull();
    }

    /**
     * @param string $fixture
     * @return string
     */
    private function categoryName(string $fixture): string
    {
        return (string) DataFixtureStorageManager::getStorage()->get($fixture)->getName();
    }
}
