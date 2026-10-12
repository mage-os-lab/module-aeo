<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Integration\Model\LlmsTxt;

use Magento\Catalog\Test\Fixture\Category as CategoryFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Aeo\Model\LlmsTxt\LlmsTxtBuilder;
use MageOS\Seo\Model\Category\ConfigRepository;
use PHPUnit\Framework\TestCase;

/**
 * Review M4, decisions D2a and D3a: a category a merchant keeps out of search engines with NOINDEX is
 * kept out of the llms documents too, while Leave Out NOINDEX Pages is on (the default).
 *
 * The directive is the one the category page gets: its own, its nearest ancestor's, the Category
 * Pages default, else core's. A category that is itself indexable moves up under the nearest listed
 * ancestor rather than going with its NOINDEX parent.
 *
 * Database isolation is off, as for the other category tree tests; the categories revert
 * themselves, and their MageOS_Seo settings go with them (the foreign key cascades).
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation disabled
 */
class NoindexCategoriesTest extends TestCase
{
    /**
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'hidden')]
    #[DataFixture(CategoryFixture::class, as: 'shown')]
    public function testANoindexCategoryIsLeftOut(): void
    {
        $this->robots('hidden', 'NOINDEX,FOLLOW');

        $document = $this->document();

        $this->assertStringContainsString('[' . $this->categoryName('shown') . ']', $document);
        $this->assertStringNotContainsString('[' . $this->categoryName('hidden') . ']', $document);
    }

    /**
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'parent')]
    #[DataFixture(CategoryFixture::class, ['parent_id' => '$parent.id$'], 'child')]
    public function testANoindexInheritedFromTheParentIsLeftOutToo(): void
    {
        $this->robots('parent', 'NOINDEX,FOLLOW');

        $document = $this->document();

        $this->assertStringNotContainsString('[' . $this->categoryName('parent') . ']', $document);
        $this->assertStringNotContainsString('[' . $this->categoryName('child') . ']', $document);
    }

    /**
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'parent')]
    #[DataFixture(CategoryFixture::class, ['parent_id' => '$parent.id$'], 'child')]
    public function testAnIndexableSubcategoryOfANoindexCategoryMovesUp(): void
    {
        $this->robots('parent', 'NOINDEX,FOLLOW');
        $this->robots('child', 'INDEX,FOLLOW');

        $document = $this->document();

        $this->assertStringNotContainsString('[' . $this->categoryName('parent') . ']', $document);
        // Top level: the parent was level 2, so its child, moved up, is not indented.
        $this->assertMatchesRegularExpression(
            '/^- \[' . preg_quote($this->categoryName('child'), '/') . '\]/m',
            $document
        );
    }

    /**
     * @magentoConfigFixture current_store mageos_aeo/llms_txt/exclude_noindex 0
     * @return void
     */
    #[DataFixture(CategoryFixture::class, as: 'hidden')]
    public function testWithTheSettingOffANoindexCategoryStays(): void
    {
        $this->robots('hidden', 'NOINDEX,FOLLOW');

        $this->assertStringContainsString('[' . $this->categoryName('hidden') . ']', $this->document());
    }

    /**
     * Set a fixture category's own robots directive, as the category's SEO tab saves it.
     *
     * @param string $fixture
     * @param string $directive
     * @return void
     */
    private function robots(string $fixture, string $directive): void
    {
        $categoryId = (int) DataFixtureStorageManager::getStorage()->get($fixture)->getId();
        Bootstrap::getObjectManager()->get(ConfigRepository::class)
            ->save($categoryId, ['robots_meta' => $directive]);
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
