<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Integration\Model\LlmsTxt;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\PageRepositoryInterface;
use Magento\Cms\Model\PageFactory;
use Magento\Framework\App\Config\MutableScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Aeo\Model\Config as AeoConfig;
use MageOS\Aeo\Model\Feed\FeedRegenerator;
use MageOS\Aeo\Model\Feed\FeedStorage;
use MageOS\Seo\Model\Config as SeoConfig;
use PHPUnit\Framework\TestCase;

/**
 * The Policies section of /llms.txt and /llms-full.txt, read from the files a rebuild writes: the
 * returns policy page, then the CMS pages chosen under Pages Listed in llms.txt that are active in
 * the store view, by title, URL and meta description.
 *
 * @magentoAppArea adminhtml
 * @magentoDbIsolation enabled
 */
class PolicySectionTest extends TestCase
{
    /**
     * Remove the files the test wrote and the chosen pages it configured.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $storage = Bootstrap::getObjectManager()->get(FeedStorage::class);
        $storage->deleteForStore('llms*', $this->storeId());
        $storage->deleteForStore('.*.tmp', $this->storeId());
        Bootstrap::getObjectManager()->get(MutableScopeConfigInterface::class)
            ->setValue(AeoConfig::XML_LLMS_POLICY_PAGES, null, ScopeInterface::SCOPE_STORE, 'default');
    }

    /**
     * @return void
     */
    #[Config(SeoConfig::XML_RETURN_POLICY_ENABLED, 1, ScopeInterface::SCOPE_STORE, 'default')]
    #[Config(SeoConfig::XML_RETURN_POLICY_URL, 'https://shop.test/returns', ScopeInterface::SCOPE_STORE, 'default')]
    public function testTheReturnsPolicyAndTheChosenActivePagesAreListed(): void
    {
        $terms    = $this->createPage('Terms of sale', 'What you agree to when you buy.', true);
        $retired  = $this->createPage('Old terms', '', false);
        Bootstrap::getObjectManager()->get(MutableScopeConfigInterface::class)->setValue(
            AeoConfig::XML_LLMS_POLICY_PAGES,
            $terms . ',' . $retired,
            ScopeInterface::SCOPE_STORE,
            'default'
        );

        Bootstrap::getObjectManager()->create(FeedRegenerator::class)->regenerate(FeedRegenerator::GROUP_LLMS);

        $baseUrl  = rtrim((string) Bootstrap::getObjectManager()->get(StoreManagerInterface::class)
            ->getStore('default')->getBaseUrl(), '/');
        $expected = "## Policies\n\n"
            . "- [Returns policy](https://shop.test/returns)\n"
            . "- [Terms of sale]({$baseUrl}/{$terms}): What you agree to when you buy.\n";
        $storage  = Bootstrap::getObjectManager()->get(FeedStorage::class);
        foreach (['llms.txt', 'llms-full.txt'] as $file) {
            $document = (string) $storage->read($file, $this->storeId());
            $this->assertStringContainsString($expected, $document, $file);
            $this->assertStringNotContainsString('Old terms', $document, 'An inactive page is left out.');
        }
    }

    /**
     * Create a CMS page for every store view and return its identifier.
     *
     * Built here rather than with Magento\Cms\Test\Fixture\Page: that fixture only exists in recent
     * releases. The database isolation rolls it back.
     *
     * @param string $title
     * @param string $metaDescription
     * @param bool $active
     * @return string
     */
    private function createPage(string $title, string $metaDescription, bool $active): string
    {
        $identifier = 'mageos-aeo-policy-' . uniqid();
        $page       = Bootstrap::getObjectManager()->get(PageFactory::class)->create();
        $page->setData([
            PageInterface::IDENTIFIER       => $identifier,
            PageInterface::TITLE            => $title,
            PageInterface::META_DESCRIPTION => $metaDescription,
            PageInterface::CONTENT          => '<p>' . $title . '</p>',
            PageInterface::IS_ACTIVE        => (int) $active,
            'stores'                        => [0],
        ]);
        Bootstrap::getObjectManager()->get(PageRepositoryInterface::class)->save($page);

        return $identifier;
    }

    /**
     * @return int
     */
    private function storeId(): int
    {
        return (int) Bootstrap::getObjectManager()->get(StoreManagerInterface::class)->getStore('default')->getId();
    }
}
