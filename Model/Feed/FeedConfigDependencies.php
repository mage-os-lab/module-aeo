<?php

declare(strict_types=1);

namespace MageOS\Aeo\Model\Feed;

use MageOS\Aeo\Model\Config;

/**
 * The configuration each feed depends on: the switch of each document, and the values the documents
 * show.
 *
 * A switch is acted on whichever way it goes (Observer\RefreshFeedsOnConfigChange): switched off,
 * the document's files and cached responses go; switched on, nothing from before may be served. A
 * shown value only needs the feeds that show it rebuilt.
 *
 * @phpstan-type Document array{group: string, file: string, tag: string}
 */
class FeedConfigDependencies
{
    /**
     * Each document's switch: its group, its file and the tag of its cached responses.
     */
    private const SWITCHES = [
        Config::XML_LLMS_ENABLED       => [
            'group' => FeedRegenerator::GROUP_LLMS,
            'file'  => 'llms.txt',
            'tag'   => FeedCache::TAG_LLMS,
        ],
        Config::XML_LLMS_FULL_ENABLED  => [
            'group' => FeedRegenerator::GROUP_LLMS,
            'file'  => 'llms-full.txt',
            'tag'   => FeedCache::TAG_LLMS_FULL,
        ],
        Config::XML_LLMS_JSONL_ENABLED => [
            'group' => FeedRegenerator::GROUP_JSONL,
            'file'  => 'llms.jsonl',
            'tag'   => FeedCache::TAG_LLMS_JSONL,
        ],
    ];

    /**
     * The configuration each group's documents show, by path or path prefix.
     *
     * llms.txt and llms-full.txt:
     *  - general/locale/: the `> Locale:` line;
     *  - trans_email/ident_support/: the AI contact, when the Organization has none
     *    (Organization\ContactEmail);
     *  - the FAQ groups and the CMS pages listed;
     *  - web/: the base URL every link starts with;
     *  - catalog/seo/: the category URL suffix in the category tree;
     *  - mageos_seo_merchant/return/: the returns policy page in the Policies section.
     *
     * llms.jsonl:
     *  - currency/: the currency prices are shown in;
     *  - catalog/price/scope: whether prices are global or per website;
     *  - web/ and catalog/seo/: product, image and media URLs;
     *  - cataloginventory/options/show_out_of_stock: which products the price index lists.
     *
     * Currency rates are not configuration, so a rate import shows in llms.jsonl from the nightly
     * rebuild.
     */
    private const SOURCES = [
        FeedRegenerator::GROUP_LLMS  => [
            'general/locale/',
            'trans_email/ident_support/',
            Config::XML_LLMS_FAQ_GROUPS,
            Config::XML_LLMS_POLICY_PAGES,
            'web/',
            'catalog/seo/',
            'mageos_seo_merchant/return/',
        ],
        FeedRegenerator::GROUP_JSONL => [
            'currency/',
            'catalog/price/scope',
            'web/',
            'catalog/seo/',
            'cataloginventory/options/show_out_of_stock',
        ],
    ];

    /**
     * The document a setting switches on and off, or null.
     *
     * @param string $path
     * @return Document|null Its group, its file and the tag of its cached responses
     */
    public function documentSwitchedBy(string $path): ?array
    {
        return self::SWITCHES[$path] ?? null;
    }

    /**
     * The groups whose documents show a setting.
     *
     * @param string $path
     * @return string[] FeedRegenerator::GROUP_* values
     */
    public function groupsShowing(string $path): array
    {
        $groups = [];
        foreach (self::SOURCES as $group => $sources) {
            foreach ($sources as $source) {
                if (str_starts_with($path, $source)) {
                    $groups[] = $group;
                    break;
                }
            }
        }

        return $groups;
    }
}
