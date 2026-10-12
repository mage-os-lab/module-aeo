<?php

declare(strict_types=1);

namespace MageOS\Aeo\Model\LlmsTxt;

use Magento\Catalog\Model\ResourceModel\Category\Collection as CategoryCollection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\DataObject;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Aeo\Api\LlmsTxtSectionProviderInterface;
use MageOS\Aeo\Model\Config;
use MageOS\Aeo\Model\ResourceModel\CategoryProductCount;
use MageOS\Seo\Api\ContactEmailInterface;
use MageOS\Seo\Api\OrganizationRepositoryInterface;
use MageOS\Seo\Api\Product\SchemaTemplatesInterface;
use MageOS\Seo\Api\Robots\PageDirectivesInterface;
use MageOS\Seo\Api\StorefrontSettingsInterface;

/**
 * Builds the /llms.txt and /llms-full.txt document bodies.
 *
 * Output follows the llms.txt format (https://llmstxt.org), in this order:
 *
 *   1. an H1 with the site name (the only required part);
 *   2. a one-line blockquote summary, omitted when there is no description;
 *   3. details: paragraphs and lists, but no headings;
 *   4. H2 sections, each a "file list": list items of the form
 *      "- [name](url)" with optional ": notes".
 *
 * Only list items with markdown links may appear under an H2. Plain facts
 * (base URL, locale, schema types, contact) therefore belong to the details
 * block. Lighthouse's llms-txt audit also needs at least one markdown link:
 * a file without one scores worse than no file at all.
 *
 * Extended vendor and category data is injected via provider arrays registered
 * in di.xml — allowing SellersSeo (and any future bridge) to contribute content
 * without coupling this class to those modules. See Api\LlmsTxtSectionProviderInterface
 * for how provider output is placed.
 *
 * The text is in the store view's language, like the rest of its storefront: headings, labels and
 * notes are translated phrases; the markdown structure around them is not.
 *
 * The locale is the store view's configured one (MageOS_Seo's StorefrontSettingsInterface); the line
 * is left out when there is none. The AI contact is MageOS_Seo's ContactEmailInterface's; the line is
 * left out when there is none.
 *
 * While Leave Out NOINDEX Pages is on (the default), a category whose page is NOINDEX is left out,
 * as search engines are told to (review M4); see buildCategorySection().
 */
class LlmsTxtBuilder
{
    /**
     * Square brackets in a link label are swapped for round ones. Backslash escapes
     * (\[ \]) are valid CommonMark, but the reference llms_txt parser matches the
     * label as [^\]]+ and would not read the link.
     */
    private const LABEL_REPLACEMENTS = [
        '[' => '(',
        ']' => ')',
    ];

    /**
     * Characters that would end a markdown link destination early.
     */
    private const URL_REPLACEMENTS = [
        ' ' => '%20',
        '(' => '%28',
        ')' => '%29',
    ];

    /**
     * Book/Software/ArtAndCraft emit multi-type ["Product", X] nodes;
     * listed by their distinguishing secondary type.
     */
    private const TEMPLATE_TYPE_MAP = [
        'Book'        => 'Book',
        'Software'    => 'SoftwareApplication',
        'ArtAndCraft' => 'VisualArtwork',
    ];

    /**
     * @param OrganizationRepositoryInterface $organizationRepository
     * @param StoreManagerInterface $storeManager
     * @param Config $aeoConfig
     * @param CategoryCollectionFactory $categoryCollectionFactory
     * @param SchemaTemplatesInterface $schemaTemplates
     * @param StorefrontSettingsInterface $seoConfig
     * @param ContactEmailInterface $contactEmail
     * @param PageDirectivesInterface $pageDirectives
     * @param SitemapUrlResolver $sitemapUrlResolver
     * @param CategoryProductCount $categoryProductCount
     * @param PolicyPages $policyPages
     * @param \MageOS\Aeo\Api\LlmsTxtSectionProviderInterface[] $sectionProviders
     */
    public function __construct(
        private readonly OrganizationRepositoryInterface $organizationRepository,
        private readonly StoreManagerInterface           $storeManager,
        private readonly Config                          $aeoConfig,
        private readonly CategoryCollectionFactory       $categoryCollectionFactory,
        private readonly SchemaTemplatesInterface        $schemaTemplates,
        private readonly StorefrontSettingsInterface     $seoConfig,
        private readonly ContactEmailInterface           $contactEmail,
        private readonly PageDirectivesInterface         $pageDirectives,
        private readonly SitemapUrlResolver              $sitemapUrlResolver,
        private readonly CategoryProductCount            $categoryProductCount,
        private readonly PolicyPages                     $policyPages,
        private readonly array                           $sectionProviders = []
    ) {
    }

    /**
     * Build the concise /llms.txt document.
     *
     * @return string
     */
    public function buildConcise(): string
    {
        return $this->build(false);
    }

    /**
     * Build the extended /llms-full.txt document.
     *
     * @return string
     */
    public function buildFull(): string
    {
        return $this->build(true);
    }

    /**
     * Assemble one document in llms.txt section order.
     *
     * @param bool $full
     * @return string
     */
    private function build(bool $full): string
    {
        /** @var \Magento\Store\Model\Store $store */
        $store     = $this->storeManager->getStore();
        $storeId   = (int) $store->getId();
        $websiteId = (int) $this->storeManager->getWebsite()->getId();
        $org       = $this->organizationRepository->getForScope($storeId, $websiteId);
        $baseUrl   = rtrim((string) $store->getBaseUrl(), '/');
        $name      = $this->oneLine($org->getName() ?: (string) $store->getName());

        // Provider output: prose goes to the details block, H2 sections after ours.
        $providerDetails  = [];
        $providerSections = [];
        foreach ($this->sectionProviders as $provider) {
            if (!$provider instanceof LlmsTxtSectionProviderInterface) {
                continue;
            }
            $section = trim($full ? $provider->getFullSection() : $provider->getConciseSection());
            if ($section === '') {
                continue;
            }
            if (str_starts_with($section, '## ')) {
                $providerSections[] = $section;
            } else {
                $providerDetails[] = $section;
            }
        }

        $blocks = ["# {$name}"];

        // A summary must be one line: a newline would end the blockquote. With no
        // description there is no summary; an empty or metadata-only one misleads.
        $summary = $this->oneLine($org->getDescription());
        if ($summary !== '') {
            $blocks[] = "> {$summary}";
        }

        $blocks[] = implode("\n", $this->buildDetails($full, $store, $baseUrl, $org->getSocialProfiles()));
        foreach ($providerDetails as $details) {
            $blocks[] = $details;
        }

        $keyUrls = [
            '## ' . __('Key URLs'),
            '',
            '- [' . $this->linkLabel((string) __('Home')) . "]({$baseUrl}/): " . __('Store front page'),
        ];
        // The configured sitemap location (Marketing → Site Map), not a guessed /sitemap.xml.
        $sitemapUrl = $this->sitemapUrlResolver->getUrl($store);
        if ($sitemapUrl !== null) {
            $keyUrls[] = '- [' . $this->linkLabel((string) __('Sitemap')) . ']('
                . strtr($sitemapUrl, self::URL_REPLACEMENTS) . '): ' . __('XML sitemap of indexable pages');
        }
        $blocks[] = implode("\n", $keyUrls);

        $categories = $this->buildCategorySection($baseUrl, $full);
        if ($categories !== '') {
            $blocks[] = $categories;
        }

        $policies = $this->buildPolicySection($storeId, $baseUrl);
        if ($policies !== '') {
            $blocks[] = $policies;
        }

        foreach ($providerSections as $section) {
            $blocks[] = $section;
        }

        return implode("\n\n", $blocks) . "\n";
    }

    /**
     * Build the details list: plain facts about the site, placed before the first H2.
     *
     * @param bool $full
     * @param \Magento\Store\Model\Store $store
     * @param string $baseUrl
     * @param string[] $socialProfiles
     * @return string[]
     */
    private function buildDetails(bool $full, $store, string $baseUrl, array $socialProfiles): array
    {
        $lines = ['- ' . __('Base URL: %1', $baseUrl)];

        $locale = trim($this->seoConfig->getLocaleCode((int) $store->getId()));
        if ($locale !== '') {
            $lines[] = '- ' . __('Locale: %1', $locale);
        }

        $lines[] = '- ' . __('Search URL template: %1', "`{$baseUrl}/catalogsearch/result?q={query}`");

        if ($full && $socialProfiles !== []) {
            $links = [];
            foreach ($socialProfiles as $profile) {
                $profile = trim((string) $profile);
                if ($profile !== '') {
                    $links[] = "<{$profile}>";
                }
            }
            if ($links !== []) {
                $lines[] = '- ' . __('Social profiles: %1', implode(', ', $links));
            }
        }

        // The spec lets llms.txt point at the structured data a site uses. Name
        // schema.org types an agent can look for, not this module's template codes.
        // The list says what the store's pages can carry: templates are assigned per
        // category or product, so "registered" is not the same as "in use".
        $templates = $this->schemaTemplates->getAvailableTemplates();
        if (!empty($templates)) {
            $productTypes = [];
            foreach (array_keys($templates) as $templateCode) {
                $productTypes[] = self::TEMPLATE_TYPE_MAP[$templateCode] ?? 'Product';
            }
            $productTypes = array_values(array_unique(array_merge(['Product'], $productTypes)));

            if ($full) {
                $types = array_merge(
                    ['Organization', 'WebSite', 'CollectionPage', 'BreadcrumbList', 'ItemList'],
                    $productTypes
                );
                $lines[] = '- ' . __(
                    'Structured data: schema.org JSON-LD; types the pages can carry: %1',
                    implode(', ', $types)
                );

                $described = [];
                foreach ($templates as $code => $label) {
                    $described[] = "{$code} ({$this->oneLine((string) $label)})";
                }
                $lines[] = '- ' . __('Product schema templates: %1', implode(', ', $described));
            } else {
                $lines[] = '- ' . __(
                    'Structured data: schema.org JSON-LD on product pages (%1)',
                    implode(', ', $productTypes)
                );
            }
        }

        $contactEmail = trim($this->contactEmail->get());
        if ($contactEmail !== '') {
            $lines[] = '- ' . __('Contact for automated queries: %1', "<{$contactEmail}>");
        }

        return $lines;
    }

    /**
     * Build the category section for the current store's tree only.
     *
     * The tree is the storefront menu's (Catalog\Plugin\Block\Topmenu): active categories with
     * Include in Menu, in the menu's order. /llms-full.txt lists every level as its Category Tree:
     * unlike the menu, it is not cut at the configured navigation depth, since every level is a
     * page. /llms.txt lists the top level only, as Categories. Returns '' when the store has no
     * visible categories. A failure to read them is not caught: FeedRegenerator logs the store's
     * failed build and keeps the previous file, which is better than publishing a file without its
     * categories.
     *
     * While Leave Out NOINDEX Pages is on, a category whose page is NOINDEX (its own directive, an
     * ancestor's, or a default: MageOS_Seo's PageDirectivesInterface) is left out (review M4). Its
     * subcategories that are themselves indexable take its place in the tree, under its nearest
     * listed ancestor (decision D3a); /llms.txt, top level only, just leaves it out.
     *
     * Each link is the category's URL rewrite, the URL its page answers at; a category without one
     * links to its catalog/category/view URL, which answers too, rather than to a guessed path.
     *
     * @param string $baseUrl
     * @param bool $full
     * @return string
     */
    private function buildCategorySection(string $baseUrl, bool $full): string
    {
        $items = [];

        /** @var \Magento\Store\Model\Store $store */
        $store   = $this->storeManager->getStore();
        $storeId = (int) $store->getId();
        $rootId  = (int) $store->getRootCategoryId();

        $collection = $this->categoryCollectionFactory->create();
        // Store scoping is essential: without setStoreId + the root-path filter
        // this would list every website's categories (including hidden B2B or
        // staging trees) and pair their URLs with this store's base URL.
        $collection->setStoreId($storeId)
            ->addAttributeToSelect(['name', 'is_active'])
            ->addPathsFilter(['1/' . $rootId . '/'])
            ->addAttributeToFilter('is_active', (string) 1)
            ->addAttributeToFilter('include_in_menu', (string) 1)
            // Level 2 is the top level: the store's root category is level 1.
            ->addAttributeToFilter('level', $full ? ['gt' => '1'] : '2')
            // Each category's own request path in this store view, in the same query.
            ->joinUrlRewrite();
        // The menu's order: by position among siblings, ties by parent and ID, as core sorts it.
        foreach (['level', 'position', 'parent_id', 'entity_id'] as $field) {
            $collection->addOrder($field, CategoryCollection::SORT_ORDER_ASC);
        }

        $categoryIds = array_map('intval', array_keys($collection->getItems()));
        // What each category page lists, an anchor's subcategories included: one query.
        $counts  = $this->categoryProductCount->countListed($storeId, $categoryIds);
        $hidden  = $this->noindexCategoryIds($categoryIds, $storeId);

        $children = [];
        foreach ($collection as $category) {
            $children[(int) $category->getParentId()][] = $category;
        }

        // Depth first from the root, so a category is listed only under a listed parent: one
        // that is disabled or left out of the menu takes its subcategories with it, though each
        // of them is still active and in the menu itself. A NOINDEX category is not listed, but
        // its subcategories take its place, at its depth.
        $pending = array_map(
            static fn (DataObject $category): array => [$category, 0],
            array_reverse($children[$rootId] ?? [])
        );
        while ($pending !== []) {
            [$category, $depth] = array_pop($pending);
            $categoryId         = (int) $category->getId();
            $listed             = !isset($hidden[$categoryId]);
            foreach (array_reverse($children[$categoryId] ?? []) as $child) {
                $pending[] = [$child, $listed ? $depth + 1 : $depth];
            }
            if (!$listed) {
                continue;
            }

            $indent = str_repeat('  ', $depth);
            $url    = strtr($this->categoryUrl($category, $baseUrl), self::URL_REPLACEMENTS);
            $label  = $this->linkLabel((string) $category->getName());
            $count  = $counts[$categoryId] ?? 0;
            $note   = $count > 0 ? ': ' . __('%1 products', $count) : '';
            $items[] = "{$indent}- [{$label}]({$url}){$note}";
        }

        if ($items === []) {
            return '';
        }

        return implode("\n", array_merge(['## ' . ($full ? __('Category Tree') : __('Categories')), ''], $items));
    }

    /**
     * The categories left out as NOINDEX, as keys; none while Leave Out NOINDEX Pages is off.
     *
     * @param int[] $categoryIds
     * @param int $storeId
     * @return array<int, true>
     */
    private function noindexCategoryIds(array $categoryIds, int $storeId): array
    {
        if (!$this->aeoConfig->isNoindexExcluded($storeId)) {
            return [];
        }

        $hidden = [];
        foreach ($this->pageDirectives->forCategories($categoryIds, $storeId) as $categoryId => $directive) {
            if ($this->pageDirectives->isNoindex($directive)) {
                $hidden[$categoryId] = true;
            }
        }

        return $hidden;
    }

    /**
     * The URL the category's page answers at: its URL rewrite, else its catalog/category/view URL.
     *
     * @param DataObject $category A category of the collection, loaded with its request path
     * @param string $baseUrl
     * @return string
     */
    private function categoryUrl(DataObject $category, string $baseUrl): string
    {
        $requestPath = ltrim((string) $category->getData('request_path'), '/');

        return $requestPath !== ''
            ? $baseUrl . '/' . $requestPath
            : $baseUrl . '/catalog/category/view/id/' . (int) $category->getId();
    }

    /**
     * Build the Policies section: the returns policy and the chosen CMS pages (PolicyPages).
     *
     * Returns '' when the store view lists none.
     *
     * @param int $storeId
     * @param string $baseUrl
     * @return string
     */
    private function buildPolicySection(int $storeId, string $baseUrl): string
    {
        $items = [];
        foreach ($this->policyPages->entries($storeId, $baseUrl) as $entry) {
            $note    = $this->oneLine($entry['note']);
            $items[] = '- [' . $this->linkLabel($entry['title']) . ']('
                . strtr($entry['url'], self::URL_REPLACEMENTS) . ')'
                . ($note !== '' ? ': ' . $note : '');
        }

        if ($items === []) {
            return '';
        }

        return implode("\n", array_merge(['## ' . __('Policies'), ''], $items));
    }

    /**
     * Collapse all whitespace, newlines included, to single spaces.
     *
     * @param string $text
     * @return string
     */
    private function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Make text safe as a markdown link label.
     *
     * @param string $text
     * @return string
     */
    private function linkLabel(string $text): string
    {
        return strtr($this->oneLine($text), self::LABEL_REPLACEMENTS);
    }
}
