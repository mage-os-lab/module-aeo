<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Integration\Model\LlmsJsonl;

use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Aeo\Model\LlmsJsonl\JsonlBuilder;
use MageOS\Seo\Model\Product\OverrideRepository;
use PHPUnit\Framework\TestCase;

/**
 * Review M4, decision D2a: a product a merchant keeps out of search engines with NOINDEX is kept out
 * of /llms.jsonl too, while Leave Out NOINDEX Pages is on (the default). The review found one set to
 * NOINDEX in the admin listed all the same.
 *
 * The directive is the one the product page gets: its MageOS_Seo override, the Product Pages default,
 * else core's.
 *
 * Database isolation is off so the saves' commit callbacks run, and with them the price index the
 * feed lists products from. The fixtures remove themselves, and their overrides go with them (the
 * foreign key cascades).
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation disabled
 */
class NoindexProductsTest extends TestCase
{
    /**
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'hidden')]
    #[DataFixture(ProductFixture::class, as: 'shown')]
    public function testANoindexProductIsLeftOut(): void
    {
        $this->robots('hidden', 'NOINDEX,FOLLOW');

        $skus = $this->skus();

        $this->assertContains($this->sku('shown'), $skus);
        $this->assertNotContains($this->sku('hidden'), $skus);
    }

    /**
     * @magentoConfigFixture current_store mageos_seo_general/robots_meta/product_default NOINDEX,NOFOLLOW
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'product')]
    public function testTheProductPagesDefaultCountsToo(): void
    {
        $this->assertNotContains($this->sku('product'), $this->skus());
    }

    /**
     * @magentoConfigFixture current_store mageos_aeo/llms_txt/exclude_noindex 0
     * @return void
     */
    #[DataFixture(ProductFixture::class, as: 'hidden')]
    public function testWithTheSettingOffANoindexProductStays(): void
    {
        $this->robots('hidden', 'NOINDEX,FOLLOW');

        $this->assertContains($this->sku('hidden'), $this->skus());
    }

    /**
     * Set a fixture product's robots directive for all store views, as its SEO fieldset saves it.
     *
     * @param string $fixture
     * @param string $directive
     * @return void
     */
    private function robots(string $fixture, string $directive): void
    {
        $productId = (int) DataFixtureStorageManager::getStorage()->get($fixture)->getId();
        Bootstrap::getObjectManager()->get(OverrideRepository::class)
            ->save($productId, 0, ['robots_meta' => $directive]);
    }

    /**
     * The SKUs the document lists, in order.
     *
     * @return string[]
     */
    private function skus(): array
    {
        $skus = [];
        foreach (Bootstrap::getObjectManager()->create(JsonlBuilder::class)->stream() as $line) {
            $node = json_decode($line, true);
            if (isset($node['sku'])) {
                $skus[] = (string) $node['sku'];
            }
        }

        return $skus;
    }

    /**
     * @param string $fixture
     * @return string
     */
    private function sku(string $fixture): string
    {
        return (string) DataFixtureStorageManager::getStorage()->get($fixture)->getSku();
    }
}
