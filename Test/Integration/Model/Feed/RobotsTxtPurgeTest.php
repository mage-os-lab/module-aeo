<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Integration\Model\Feed;

use Magento\Config\Model\Config;
use Magento\Framework\App\Config\ReinitableConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\PageCache\Model\Cache\Type as FullPageCache;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Review M5: changing the AI crawler settings purges every store view's cached robots.txt.
 *
 * Core caches robots.txt under `robots_<store ID>` (Magento\Robots\Block\Data). The two settings had
 * no way to purge it, so the old directives were served until the whole page cache was flushed.
 * They are default-scope settings, and every store view's robots.txt carries them.
 *
 * The cached robots.txt is seeded under the tag the real response carries, and the purge is the
 * built-in cache's.
 *
 * Database isolation is off: the purge waits for the save's commit, which an isolated test never
 * reaches. The settings saved are deleted afterwards.
 *
 * @magentoAppArea adminhtml
 * @magentoDbIsolation disabled
 * @magentoCache full_page enabled
 */
class RobotsTxtPurgeTest extends TestCase
{
    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $writer = Bootstrap::getObjectManager()->get(WriterInterface::class);
        $writer->delete('mageos_aeo/ai_robots/enabled');
        $writer->delete('mageos_aeo/ai_robots/disallowed');
        Bootstrap::getObjectManager()->get(ReinitableConfigInterface::class)->reinit();

        parent::tearDown();
    }

    /**
     * The two settings, each with a value it does not have by default.
     *
     * @return array<string, array{string, string|string[]}>
     */
    public static function settings(): array
    {
        return [
            'Append AI Crawler Directives' => ['enabled', '1'],
            'Disallow These AI Crawlers'   => ['disallowed', ['GPTBot']],
        ];
    }

    /**
     * @dataProvider settings
     * @param string $field
     * @param string|string[] $value
     * @return void
     */
    #[DataProvider('settings')]
    public function testChangingASettingPurgesEveryStoreViewsRobotsTxt(string $field, string|array $value): void
    {
        $storeIds = array_map(
            static fn ($store): int => (int) $store->getId(),
            Bootstrap::getObjectManager()->get(StoreManagerInterface::class)->getStores()
        );
        foreach ($storeIds as $storeId) {
            $this->cache()->save('User-agent: *', 'robots-' . $storeId, ['robots_' . $storeId]);
        }
        $this->cache()->save('<html>home</html>', 'home', ['cms_p_1']);

        $config = Bootstrap::getObjectManager()->create(Config::class);
        $config->setSection('mageos_aeo');
        $config->setGroups(['ai_robots' => ['fields' => [$field => ['value' => $value]]]]);
        $config->save();

        foreach ($storeIds as $storeId) {
            $this->assertFalse(
                $this->cache()->load('robots-' . $storeId),
                \sprintf('Store %d\'s robots.txt was not purged.', $storeId)
            );
        }
        $this->assertSame('<html>home</html>', $this->cache()->load('home'), 'Other pages stay cached.');
    }

    /**
     * @return FullPageCache
     */
    private function cache(): FullPageCache
    {
        return Bootstrap::getObjectManager()->get(FullPageCache::class);
    }
}
