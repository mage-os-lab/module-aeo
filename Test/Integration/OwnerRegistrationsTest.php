<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Integration;

use Magento\Config\Model\Config\Structure;
use Magento\Cron\Model\ConfigInterface as CronConfig;
use Magento\Framework\Acl\Builder as AclBuilder;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Aeo\Cron\RegenerateFeeds;
use PHPUnit\Framework\TestCase;

/**
 * The identifiers this module owns, as Magento sees them once the configuration is merged: the
 * settings under `mageos_aeo`, the ACL resource `MageOS_Aeo::config` and the nightly cron job. A
 * store's data, admin roles and deployment configuration refer to these, so each is pinned here.
 *
 * @magentoAppArea adminhtml
 */
class OwnerRegistrationsTest extends TestCase
{
    /**
     * @return void
     */
    public function testTheAiInformationSettingsHaveASectionOfTheirOwn(): void
    {
        $structure = Bootstrap::getObjectManager()->get(Structure::class);
        $paths     = array_keys($structure->getFieldPaths());

        foreach ([
            'llms_txt/enabled',
            'llms_txt/full_enabled',
            'llms_txt/jsonl_enabled',
            'llms_txt/faq_groups',
            'feeds/storage_dir',
            'ai_robots/enabled',
            'ai_robots/disallowed',
        ] as $field) {
            $this->assertContains('mageos_aeo/' . $field, $paths);
            $this->assertNotContains('mageos_seo_general/' . $field, $paths);
        }

        $section = $structure->getElement('mageos_aeo');
        $this->assertSame('AI Information & Crawlers', (string) $section->getLabel());
        $this->assertSame('MageOS_Aeo::config', $section->getAttribute('resource'));
    }

    /**
     * The resource sits under MageOS_Seo's SEO resource, where admin roles already find it.
     *
     * @return void
     */
    public function testTheAclResourceIsRegisteredUnderSeo(): void
    {
        $acl = Bootstrap::getObjectManager()->get(AclBuilder::class)->getAcl();

        $this->assertTrue($acl->hasResource('MageOS_Aeo::config'));
        $this->assertTrue($acl->inheritsResource('MageOS_Aeo::config', 'MageOS_Seo::seo'));
    }

    /**
     * @return void
     */
    public function testTheNightlyFeedRebuildIsAnAeoCronJob(): void
    {
        $jobs = Bootstrap::getObjectManager()->get(CronConfig::class)->getJobs()['default'] ?? [];

        $this->assertSame(RegenerateFeeds::class, $jobs['mageos_aeo_regenerate_feeds']['instance'] ?? null);
        $this->assertArrayNotHasKey('mageos_seo_regenerate_feeds', $jobs);
    }
}
