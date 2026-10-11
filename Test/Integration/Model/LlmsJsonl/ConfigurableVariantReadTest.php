<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Integration\Model\LlmsJsonl;

use Magento\Catalog\Model\Product\Action as ProductAction;
use Magento\Catalog\Test\Fixture\Product as ProductFixture;
use Magento\ConfigurableProduct\Test\Fixture\Product as ConfigurableProductFixture;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\ScopeInterface;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Fixture\DataFixture;
use Magento\TestFramework\Fixture\DataFixtureStorageManager;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Aeo\Model\Config as AeoConfig;
use MageOS\Aeo\Model\LlmsJsonl\JsonlBuilder;
use MageOS\Seo\Test\Integration\Fixture\ProductAttribute as AttributeFixture;
use PHPUnit\Framework\TestCase;

/**
 * PLAN-G, "G2.3 follow-up": llms.jsonl reads a page of products' variants at once.
 *
 * A configurable's line has its product page's offer, built from its variants. Read one
 * configurable at a time through core's provider, each paid core's whole child load: ten queries,
 * its variants' media gallery and its configurable attributes among them, which an offer does not
 * use. The build took 2,447 queries for the rig's 179 lines, where it took 816 before. A page's
 * variants are now read in one collection, without the gallery.
 *
 * A configurable without an image of its own lends its first sold variant's. Core's `getImage()`
 * loaded all its variants for that, gallery included; the line now takes it from the page read.
 *
 * Only this test's variants are counted: the catalogue it runs against may hold others.
 *
 * Database isolation is off so the saves' commit callbacks run, and with them the price index the
 * feed lists products from. The fixtures remove themselves.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation disabled
 */
class ConfigurableVariantReadTest extends TestCase
{
    /**
     * @return void
     */
    #[Config(AeoConfig::XML_LLMS_JSONL_ENABLED, '1', ScopeInterface::SCOPE_STORE, 'default')]
    #[DataFixture(AttributeFixture::class, as: 'attribute')]
    #[DataFixture(ProductFixture::class, ['price' => 20], 'low')]
    #[DataFixture(ProductFixture::class, ['price' => 40], 'high')]
    #[DataFixture(
        ConfigurableProductFixture::class,
        ['_options' => ['$attribute$'], '_links' => ['$low$', '$high$']],
        'configurable'
    )]
    public function testAConfigurablesVariantsAreReadWithoutTheirGallery(): void
    {
        $storage = DataFixtureStorageManager::getStorage();
        $low     = (int) $storage->get('low')->getId();
        $high    = (int) $storage->get('high')->getId();
        // Only the low variant has an image; the configurable has none of its own.
        Bootstrap::getObjectManager()->get(ProductAction::class)
            ->updateAttributes([$low], ['image' => '/l/o/low.jpg'], 0);

        $profiler = Bootstrap::getObjectManager()->get(ResourceConnection::class)->getConnection()->getProfiler();
        $profiler->clear();
        $profiler->setEnabled(true);
        try {
            $lines = iterator_to_array(
                Bootstrap::getObjectManager()->create(JsonlBuilder::class)->stream(),
                false
            );
            // This test's variants' gallery: a query whose ID list names one of them.
            $ours           = '/\bIN\s*\(([^)]*\b(?:' . $low . '|' . $high . ')\b[^)]*)\)/';
            $galleryQueries = array_filter(
                $profiler->getQueryProfiles() ?: [],
                static fn ($profile): bool
                    => str_contains((string) $profile->getQuery(), 'catalog_product_entity_media_gallery')
                    && preg_match($ours, (string) $profile->getQuery()) === 1
            );
        } finally {
            $profiler->setEnabled(false);
        }

        $sku  = (string) $storage->get('configurable')->getSku();
        $line = [];
        foreach ($lines as $encoded) {
            $node = json_decode($encoded, true);
            if (($node['sku'] ?? null) === $sku) {
                $line = $node;
            }
        }

        $this->assertSame('AggregateOffer', $line['offers']['@type'] ?? null, 'The configurable has its offer.');
        $this->assertSame('20.00', $line['offers']['lowPrice'] ?? null);
        $this->assertSame('40.00', $line['offers']['highPrice'] ?? null);
        $this->assertStringEndsWith('/catalog/product/l/o/low.jpg', (string) ($line['image'] ?? ''));
        $this->assertSame([], array_values($galleryQueries), 'The variants\' media gallery was read.');
    }
}
