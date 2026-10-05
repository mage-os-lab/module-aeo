<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Unit\Model\Feed;

use MageOS\Aeo\Model\Feed\FeedCache;
use MageOS\Aeo\Model\Feed\FeedConfigDependencies;
use MageOS\Aeo\Model\Feed\FeedRegenerator;
use PHPUnit\Framework\TestCase;

/**
 * Issue #4: which configuration each feed depends on.
 */
class FeedConfigDependenciesTest extends TestCase
{
    public function testEachDocumentHasItsOwnSwitch(): void
    {
        $dependencies = new FeedConfigDependencies();

        $this->assertSame(
            ['group' => FeedRegenerator::GROUP_LLMS, 'file' => 'llms.txt', 'tag' => FeedCache::TAG_LLMS],
            $dependencies->documentSwitchedBy('mageos_aeo/llms_txt/enabled')
        );
        $this->assertSame(
            ['group' => FeedRegenerator::GROUP_LLMS, 'file' => 'llms-full.txt', 'tag' => FeedCache::TAG_LLMS_FULL],
            $dependencies->documentSwitchedBy('mageos_aeo/llms_txt/full_enabled')
        );
        $this->assertSame(
            ['group' => FeedRegenerator::GROUP_JSONL, 'file' => 'llms.jsonl', 'tag' => FeedCache::TAG_LLMS_JSONL],
            $dependencies->documentSwitchedBy('mageos_aeo/llms_txt/jsonl_enabled')
        );
        $this->assertNull($dependencies->documentSwitchedBy('mageos_aeo/llms_txt/faq_groups'));
    }

    public function testASwitchIsNotAlsoAValueTheOtherGroupShows(): void
    {
        // llms.jsonl's switch sits under the llms settings, and once queued the llms rebuild for it.
        $this->assertSame([], (new FeedConfigDependencies())->groupsShowing('mageos_aeo/llms_txt/jsonl_enabled'));
    }

    public function testTheValuesEachFeedShows(): void
    {
        $dependencies = new FeedConfigDependencies();
        $llms         = [FeedRegenerator::GROUP_LLMS];
        $jsonl        = [FeedRegenerator::GROUP_JSONL];
        $both         = [FeedRegenerator::GROUP_LLMS, FeedRegenerator::GROUP_JSONL];

        $expected = [
            'general/locale/code'                        => $llms,
            'trans_email/ident_support/email'            => $llms,
            'mageos_aeo/llms_txt/faq_groups'             => $llms,
            'mageos_aeo/llms_txt/policy_pages'           => $llms,
            'mageos_seo_merchant/return/url'             => $llms,
            'web/unsecure/base_url'                      => $both,
            'web/seo/use_rewrites'                       => $both,
            'catalog/seo/category_url_suffix'            => $both,
            'catalog/seo/product_url_suffix'             => $both,
            'currency/options/default'                   => $jsonl,
            'currency/options/base'                      => $jsonl,
            'catalog/price/scope'                        => $jsonl,
            'cataloginventory/options/show_out_of_stock' => $jsonl,
            'contact/email/recipient_email'              => [],
            'trans_email/ident_sales/email'              => [],
            // Only the return policy appears in the documents.
            'mageos_seo_merchant/shipping/enabled'       => [],
            // The llms settings moved to mageos_aeo; the old path is read by nothing.
            'mageos_seo_general/llms_txt/faq_groups'     => [],
        ];

        foreach ($expected as $path => $groups) {
            $this->assertSame($groups, $dependencies->groupsShowing($path), $path);
        }
    }
}
