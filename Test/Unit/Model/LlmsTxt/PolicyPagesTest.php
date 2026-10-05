<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Unit\Model\LlmsTxt;

use Magento\Cms\Api\Data\PageInterface;
use Magento\Cms\Api\GetPageByIdentifierInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\Aeo\Model\Config;
use MageOS\Aeo\Model\LlmsTxt\PolicyPages;
use MageOS\Seo\Model\Config as SeoConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The Policies section's pages: the returns policy first, then the chosen CMS pages that are active
 * in the store view, each by title, URL and meta description.
 */
class PolicyPagesTest extends TestCase
{
    private const STORE_ID = 3;

    private const BASE_URL = 'https://shop.test/nl';

    /**
     * Pages active in the store view, by identifier: title and meta description.
     *
     * @var array<string, array{0: string, 1: string|null}>
     */
    private array $pages = [
        'privacy'  => ['Privacy policy', 'How we handle your data.'],
        'about-us' => ['About us', null],
        'returns'  => ['Returns', 'Thirty days.'],
    ];

    public function testTheReturnsPolicyComesFirstThenTheChosenPagesInOrder(): void
    {
        $entries = $this->policyPages('https://shop.test/returns-policy', ['about-us', 'privacy'])
            ->entries(self::STORE_ID, self::BASE_URL);

        $this->assertSame(
            [
                ['title' => 'Returns policy', 'url' => 'https://shop.test/returns-policy', 'note' => ''],
                ['title' => 'About us', 'url' => 'https://shop.test/nl/about-us', 'note' => ''],
                [
                    'title' => 'Privacy policy',
                    'url'   => 'https://shop.test/nl/privacy',
                    'note'  => 'How we handle your data.',
                ],
            ],
            $entries
        );
    }

    public function testAPageWhoseIdentifierRepeatsIsLookedUpByItsIdentifierAsCoreDoes(): void
    {
        // Core's page source adds "|ID" to an identifier another page has too, and strips it again.
        $entries = $this->policyPages(null, ['privacy|12'])->entries(self::STORE_ID, self::BASE_URL);

        $this->assertSame('https://shop.test/nl/privacy', $entries[0]['url'] ?? null);
    }

    public function testAChosenPageThatIsNotInTheStoreViewIsLeftOutAndLogged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with($this->logicalAnd(
            $this->stringContains('"gone"'),
            $this->stringContains('store view ' . self::STORE_ID)
        ));

        $entries = $this->policyPages(null, ['gone', 'privacy'], $logger)->entries(self::STORE_ID, self::BASE_URL);

        $this->assertSame(['Privacy policy'], array_column($entries, 'title'));
    }

    public function testAPageThatIsTheReturnsPolicyIsListedOnce(): void
    {
        $entries = $this->policyPages(self::BASE_URL . '/returns', ['returns', 'privacy'])
            ->entries(self::STORE_ID, self::BASE_URL);

        $this->assertSame(['Returns policy', 'Privacy policy'], array_column($entries, 'title'));
    }

    public function testNothingConfiguredListsNothing(): void
    {
        $this->assertSame([], $this->policyPages(null, [])->entries(self::STORE_ID, self::BASE_URL));
    }

    /**
     * The pages over the returns URL and chosen pages given, with the store view's pages above.
     *
     * @param string|null $returnsUrl
     * @param string[] $chosen
     * @param LoggerInterface|null $logger
     * @return PolicyPages
     */
    private function policyPages(?string $returnsUrl, array $chosen, ?LoggerInterface $logger = null): PolicyPages
    {
        $aeoConfig = $this->createStub(Config::class);
        $aeoConfig->method('getLlmsPolicyPages')->willReturnMap([[self::STORE_ID, $chosen]]);

        $seoConfig = $this->createStub(SeoConfig::class);
        $seoConfig->method('getReturnPolicyUrl')->willReturnMap([[self::STORE_ID, $returnsUrl]]);

        $getPage = $this->createStub(GetPageByIdentifierInterface::class);
        $getPage->method('execute')->willReturnCallback(
            function (string $identifier, int $storeId): PageInterface {
                if ($storeId !== self::STORE_ID || !isset($this->pages[$identifier])) {
                    throw new NoSuchEntityException(__('No such page.'));
                }
                [$title, $metaDescription] = $this->pages[$identifier];

                $page = $this->createStub(PageInterface::class);
                $page->method('getIdentifier')->willReturn($identifier);
                $page->method('getTitle')->willReturn($title);
                $page->method('getMetaDescription')->willReturn($metaDescription);

                return $page;
            }
        );

        return new PolicyPages(
            $aeoConfig,
            $seoConfig,
            $getPage,
            $logger ?? $this->createStub(LoggerInterface::class)
        );
    }
}
