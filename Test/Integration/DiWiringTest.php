<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Integration;

use Magento\Framework\App\RouterList;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Aeo\Model\Feed\LlmsRebuildHandler;
use MageOS\Aeo\Model\LlmsJsonl\JsonlBuilder;
use MageOS\Aeo\Model\LlmsTxt\LlmsTxtBuilder;
use MageOS\Seo\Model\Rebuild\HandlerPool;
use MageOS\Seo\Model\Router\PublicPaths;
use PHPUnit\Framework\TestCase;

/**
 * This module's registrations, as the merged DI configuration builds them.
 *
 * @magentoAppArea frontend
 */
class DiWiringTest extends TestCase
{
    /**
     * @return void
     */
    public function testTheLlmsTxtBuilderIsInstantiableViaDi(): void
    {
        $instance = Bootstrap::getObjectManager()->get(LlmsTxtBuilder::class);
        $this->assertInstanceOf(LlmsTxtBuilder::class, $instance);
    }

    /**
     * @return void
     */
    public function testTheLlmsJsonlBuilderIsInstantiableViaDi(): void
    {
        $instance = Bootstrap::getObjectManager()->get(JsonlBuilder::class);
        $this->assertInstanceOf(JsonlBuilder::class, $instance);
    }

    /**
     * The llms documents are a group in MageOS_Seo's rebuild queue: the consumer, the seo:rebuild
     * command and every setup run find them there.
     *
     * @return void
     */
    public function testTheLlmsDocumentsAreRegisteredWithTheRebuildQueue(): void
    {
        $pool = Bootstrap::getObjectManager()->get(HandlerPool::class);

        $this->assertInstanceOf(LlmsRebuildHandler::class, $pool->get('llms'));
        $this->assertInstanceOf(LlmsRebuildHandler::class, $pool->get('jsonl'));
        $this->assertSame(['llms', 'jsonl'], $pool->getGroups());
    }

    /**
     * While an llms file is enabled, its path is this module's: a URL rewrite for it (a redirect,
     * or a CMS page with that identifier) must not take it. RouterList sorts on sortOrder alone,
     * so sharing core's URL-rewrite sortOrder left the order to how the di.xml files merged.
     *
     * @return void
     */
    public function testTheLlmsRouterRunsBeforeTheUrlRewriteRouter(): void
    {
        $routerIds = array_keys(iterator_to_array(Bootstrap::getObjectManager()->create(RouterList::class)));

        $this->assertSame(
            ['mageos_aeo_llms', 'urlrewrite'],
            array_values(array_intersect($routerIds, ['mageos_aeo_llms', 'urlrewrite']))
        );
    }

    /**
     * The documents and their internal URL are registered with MageOS_Seo as public, so no session
     * is started for them.
     *
     * @return void
     */
    public function testTheLlmsPathsArePublic(): void
    {
        $publicPaths = Bootstrap::getObjectManager()->get(PublicPaths::class);

        foreach (['/llms.txt', '/llms-full.txt', '/llms.jsonl', '/mageos-aeo/llms/index'] as $path) {
            $this->assertTrue($publicPaths->isPublicPath($path), $path);
        }
    }
}
