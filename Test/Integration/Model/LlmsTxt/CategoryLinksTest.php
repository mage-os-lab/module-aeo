<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Integration\Model\LlmsTxt;

use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\CatalogUrlRewrite\Model\CategoryUrlRewriteGenerator;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\UrlRewrite\Model\UrlPersistInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use MageOS\Aeo\Model\LlmsTxt\LlmsTxtBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Review low item: a category's link in /llms-full.txt is the URL its page answers at.
 *
 * The link was the category's url_path with the category suffix, a guess that holds only while the
 * URL rewrite it assumes exists. It is now the rewrite's own request path, and for a category with
 * no rewrite its catalog/category/view URL, which answers, rather than a path that 404s.
 *
 * Database isolation is off, as for the other category tree tests; the category reverts itself.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation disabled
 */
class CategoryLinksTest extends TestCase
{
    /**
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'category')]
    public function testACategoryWithoutARewriteLinksToAUrlThatAnswers(): void
    {
        $category = DataFixtureStorageManager::getStorage()->get('category');
        Bootstrap::getObjectManager()->get(UrlPersistInterface::class)->deleteByData([
            UrlRewrite::ENTITY_ID   => (int) $category->getId(),
            UrlRewrite::ENTITY_TYPE => CategoryUrlRewriteGenerator::ENTITY_TYPE,
        ]);

        $document = Bootstrap::getObjectManager()->get(LlmsTxtBuilder::class)->buildFull();

        $this->assertMatchesRegularExpression(
            '#\[' . preg_quote((string) $category->getName(), '#') . '\]\([^)]*catalog/category/view/[^)]*id/'
                . (int) $category->getId() . '\b#',
            $document
        );
    }

    /**
     * A category with its rewrite keeps its friendly URL.
     *
     * @return void
     */
    #[DataFixture(CategoryFixture::class, ['url_key' => 'llms-links-friendly'], 'category')]
    public function testACategoryWithARewriteLinksToItsRequestPath(): void
    {
        $category = DataFixtureStorageManager::getStorage()->get('category');

        $document = Bootstrap::getObjectManager()->get(LlmsTxtBuilder::class)->buildFull();

        $this->assertStringContainsString(
            '[' . $category->getName() . '](',
            $document
        );
        $this->assertMatchesRegularExpression('#\]\([^)]*/llms-links-friendly\.html\)#', $document);
    }
}
