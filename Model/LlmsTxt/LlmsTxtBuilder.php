<?php

declare(strict_types=1);

namespace MageOS\Aeo\Model\LlmsTxt;

use Magento\Catalog\Model\ResourceModel\Category\Collection as CategoryCollection;
use Magento\Catalog\Model\ResourceModel\Category\CollectionFactory as CategoryCollectionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Aeo\Api\LlmsTxtSectionProviderInterface;
use MageOS\Aeo\Model\ResourceModel\CategoryProductCount;
use MageOS\Seo\Api\OrganizationRepositoryInterface;
use MageOS\Seo\Model\Config;
use MageOS\Seo\Model\Organization\ContactEmail;
use MageOS\Seo\Model\Product\SchemaBuilderPool;

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
 * The locale is the store view's configured one (Config::getLocaleCode()); the line is left out
 * when there is none. The AI contact is Organization\ContactEmail's; the line is left out when
 * there is none.
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
     * @param ScopeConfigInterface $scopeConfig
     * @param CategoryCollectionFactory $categoryCollectionFactory
     * @param SchemaBuilderPool $builderPool
     * @param Config $seoConfig
     * @param ContactEmail $contactEmail
     * @param SitemapUrlResolver $sitemapUrlResolver
     * @param CategoryProductCount $categoryProductCount
     * @param \MageOS\Aeo\Api\LlmsTxtSectionProviderInterface[] $sectionProviders
     */
    public function __construct(
        private readonly OrganizationRepositoryInterface $organizationRepository,
        private readonly StoreManagerInterface           $storeManager,
        private readonly ScopeConfigInterface            $scopeConfig,
        private readonly CategoryCollectionFactory       $categoryCollectionFactory,
        private readonly SchemaBuilderPool               $builderPool,
        private readonly Config                          $seoConfig,
        private readonly ContactEmail                    $contactEmail,
        private readonly SitemapUrlResolver              $sitemapUrlResolver,
        private readonly CategoryProductCount            $categoryProductCount,
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

        if ($full) {
            $categories = $this->buildCategorySection($baseUrl);
            if ($categories !== '') {
                $blocks[] = $categories;
            }
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
        $templates = $this->builderPool->getAvailableTemplates();
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
     * Build the category tree section for the current store's tree only.
     *
     * The tree is the storefront menu's (Catalog\Plugin\Block\Topmenu): active categories with
     * Include in Menu, in the menu's order. Unlike the menu, it is not cut at the configured
     * navigation depth: every level is a page. Returns '' when the store has no visible
     * categories. A failure to read them is not caught: FeedRegenerator logs the store's failed
     * build and keeps the previous file, which is better than publishing a file without its
     * category tree.
     *
     * @param string $baseUrl
     * @return string
     */
    private function buildCategorySection(string $baseUrl): string
    {
        $items = [];

        /** @var \Magento\Store\Model\Store $store */
        $store   = $this->storeManager->getStore();
        $storeId = (int) $store->getId();
        $rootId  = (int) $store->getRootCategoryId();

        $collection = $this->categoryCollectionFactory->create();
        // Store scoping is essential: without setStoreId + the root-path filter
        // this would list every website's categories (including hidden B2B or
        // staging trees) and pair their url_path with this store's base URL.
        $collection->setStoreId($storeId)
            ->addAttributeToSelect(['name', 'url_path', 'is_active'])
            ->addPathsFilter(['1/' . $rootId . '/'])
            ->addAttributeToFilter('is_active', (string) 1)
            ->addAttributeToFilter('include_in_menu', (string) 1)
            ->addAttributeToFilter('level', ['gt' => 1]);
        // The menu's order: by position among siblings, ties by parent and ID, as core sorts it.
        foreach (['level', 'position', 'parent_id', 'entity_id'] as $field) {
            $collection->addOrder($field, CategoryCollection::SORT_ORDER_ASC);
        }

        // What each category page lists, an anchor's subcategories included: one query.
        $counts = $this->categoryProductCount->countListed($storeId, array_keys($collection->getItems()));

        $urlSuffix = (string) $this->scopeConfig->getValue(
            'catalog/seo/category_url_suffix',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        $children = [];
        foreach ($collection as $category) {
            $children[(int) $category->getParentId()][] = $category;
        }

        // Depth first from the root, so a category is listed only under a listed parent: one
        // that is disabled or left out of the menu takes its subcategories with it, though each
        // of them is still active and in the menu itself.
        $pending = array_reverse($children[$rootId] ?? []);
        while ($pending !== []) {
            $category = array_pop($pending);
            foreach (array_reverse($children[(int) $category->getId()] ?? []) as $child) {
                $pending[] = $child;
            }

            $level  = max(0, (int) $category->getLevel() - 2);
            $indent = str_repeat('  ', $level);
            $url    = strtr(
                $baseUrl . '/' . ltrim((string) $category->getUrlPath(), '/') . $urlSuffix,
                self::URL_REPLACEMENTS
            );
            $label  = $this->linkLabel((string) $category->getName());
            $count  = $counts[(int) $category->getId()] ?? 0;
            $note   = $count > 0 ? ': ' . __('%1 products', $count) : '';
            $items[] = "{$indent}- [{$label}]({$url}){$note}";
        }

        if ($items === []) {
            return '';
        }

        return implode("\n", array_merge(['## ' . __('Category Tree'), ''], $items));
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
