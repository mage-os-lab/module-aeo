<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Unit\Setup\Patch\Data;

use MageOS\Aeo\Model\Feed\FeedCache;
use MageOS\Aeo\Model\Feed\FeedStorage;
use MageOS\Aeo\Setup\Patch\Data\RemoveHreflangSitemap;
use MageOS\Seo\Model\Rebuild\RegenerationRequester;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The cached responses; the files and the flag are covered against a real install by
 * Test/Integration/Setup/Patch/Data/RemoveHreflangSitemapTest.
 */
class RemoveHreflangSitemapTest extends TestCase
{
    public function testCachedCopiesOfTheRetiredPathArePurged(): void
    {
        $feedCache = $this->createMock(FeedCache::class);
        $feedCache->expects($this->once())->method('purgeTags')->with(['MAGEOS_SEO_HREFLANG_SITEMAP']);

        $this->patch($feedCache)->apply();
    }

    public function testAPurgeFailureIsLoggedAndSetupCarriesOn(): void
    {
        $feedCache = $this->createStub(FeedCache::class);
        $feedCache->method('purgeTags')->willThrowException(new \RuntimeException('varnish down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('varnish down'));

        $this->patch($feedCache, $logger)->apply();
    }

    /**
     * Core skips a patch whose alias is already in patch_list, so an installation that applied it
     * as MageOS_Seo's, before the module split, does not apply it again.
     *
     * @return void
     */
    public function testItAnswersToTheNameItHadInMageOsSeo(): void
    {
        $this->assertSame(
            [str_replace('MageOS\\Aeo\\', 'MageOS\\Seo\\', RemoveHreflangSitemap::class)],
            $this->patch($this->createStub(FeedCache::class))->getAliases()
        );
    }

    /**
     * @param FeedCache $feedCache
     * @param LoggerInterface|null $logger
     * @return RemoveHreflangSitemap
     */
    private function patch(FeedCache $feedCache, ?LoggerInterface $logger = null): RemoveHreflangSitemap
    {
        return new RemoveHreflangSitemap(
            $this->createStub(FeedStorage::class),
            $this->createStub(RegenerationRequester::class),
            $feedCache,
            $logger ?? $this->createStub(LoggerInterface::class)
        );
    }
}
