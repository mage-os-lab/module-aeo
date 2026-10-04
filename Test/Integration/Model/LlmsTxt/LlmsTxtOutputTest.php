<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Integration\Model\LlmsTxt;

use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Aeo\Model\Feed\FeedRegenerator;
use MageOS\Aeo\Model\Feed\FeedStorage;
use MageOS\Aeo\Model\LlmsTxt\FaqLlmsSectionProvider;
use MageOS\Aeo\Model\LlmsTxt\LlmsTxtBuilder;
use MageOS\Seo\Api\FaqSourceProviderInterface;
use MageOS\Seo\Api\OrganizationRepositoryInterface;
use MageOS\Seo\Model\Faq\SourcePool;
use MageOS\Seo\Model\OrganizationRepository;
use PHPUnit\Framework\TestCase;

/**
 * What /llms.txt and /llms-full.txt say, read from the files a rebuild writes.
 *
 * The documents are built through FeedRegenerator, under the store emulation it runs every store
 * view in, and read back from storage: the files served, not just the builder's return value.
 *
 * The FAQs come from a test source in the FAQ source pool, so the documents are tested against the
 * source contract, whichever module supplies the FAQs (MageOS_Faq's table is one).
 *
 * @magentoAppArea adminhtml
 * @magentoDbIsolation enabled
 */
class LlmsTxtOutputTest extends TestCase
{
    private const SUPPORT_EMAIL = 'trans_email/ident_support/email';
    private const FAQ_GROUPS    = 'mageos_aeo/llms_txt/faq_groups';

    /**
     * The test source's questions, by group.
     *
     * @var array<string, string[]>|null
     */
    private ?array $faqs = null;

    /**
     * Remove the files the tests wrote, the Organizations the rolled-back saves left memoised, and
     * the test FAQ source.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->storage()->deleteForStore('llms*', $this->storeId());
        $this->storage()->deleteForStore('.*.tmp', $this->storeId());
        Bootstrap::getObjectManager()->removeSharedInstance(FeedStorage::class);
        Bootstrap::getObjectManager()->get(OrganizationRepository::class)->_resetState();

        $this->faqs = null;
        foreach ([SourcePool::class, FaqLlmsSectionProvider::class, LlmsTxtBuilder::class] as $class) {
            Bootstrap::getObjectManager()->removeSharedInstance($class);
        }
    }

    /**
     * The locale line carries the store view's configured locale.
     *
     * @return void
     */
    #[Config('general/locale/code', 'en_GB', ScopeInterface::SCOPE_STORE, 'default')]
    public function testTheLocaleLineIsTheStoreViewLocale(): void
    {
        [$concise, $full] = $this->build();

        $this->assertStringContainsString("\n- Locale: en_GB\n", $concise);
        $this->assertStringContainsString("\n- Locale: en_GB\n", $full);
    }

    /**
     * The served /llms.txt passes Lighthouse's llms-txt checks (an H1, a markdown link, at least
     * 50 characters), and every line under an H2 is a "- [name](url)" item, as the llms.txt
     * format and its reference parser require.
     *
     * @return void
     */
    public function testTheConciseDocumentFollowsTheLlmsTxtFormat(): void
    {
        [$concise] = $this->build();

        $this->assertMatchesRegularExpression('/^\s*#\s+.+/m', $concise);
        $this->assertMatchesRegularExpression('/\[.+\]\(.+\)/', $concise);
        $this->assertGreaterThanOrEqual(50, \strlen($concise));

        $underH2 = false;
        foreach (explode("\n", $concise) as $line) {
            if (str_starts_with($line, '## ')) {
                $underH2 = true;
                continue;
            }
            if ($underH2 && trim($line) !== '') {
                $this->assertMatchesRegularExpression(
                    '/^\s*-\s*\[[^\]]+\]\([^)\s]+\)(?::\s*.*)?$/',
                    $line,
                    'Only "- [name](url)" items may follow an H2.'
                );
            }
        }
    }

    /**
     * The Organization's contact email is the AI contact, ahead of the store's support email.
     *
     * @return void
     */
    #[Config(self::SUPPORT_EMAIL, 'help@shop.test', ScopeInterface::SCOPE_STORE, 'default')]
    public function testTheAiContactIsTheOrganizationContactEmail(): void
    {
        $this->organizationContact('ai@shop.test');

        [$concise, $full] = $this->build();

        $this->assertStringContainsString("- Contact for automated queries: <ai@shop.test>\n", $concise);
        $this->assertStringContainsString("- Contact for automated queries: <ai@shop.test>\n", $full);
        $this->assertStringNotContainsString('help@shop.test', $concise . $full);
    }

    /**
     * Without an Organization contact, the store's configured support email is the AI contact.
     *
     * @return void
     */
    #[Config(self::SUPPORT_EMAIL, 'help@shop.test', ScopeInterface::SCOPE_STORE, 'default')]
    public function testWithoutAnOrganizationContactTheConfiguredSupportEmailIsUsed(): void
    {
        [$concise, $full] = $this->build();

        $this->assertStringContainsString("- Contact for automated queries: <help@shop.test>\n", $concise);
        $this->assertStringContainsString("- Contact for automated queries: <help@shop.test>\n", $full);
    }

    /**
     * A support email still at Magento's shipped placeholder is no contact at all.
     *
     * @return void
     */
    public function testNoAiContactWhenTheSupportEmailIsTheShippedDefault(): void
    {
        [$concise, $full] = $this->build();

        $this->assertStringNotContainsString('Contact for automated queries', $concise . $full);
        $this->assertStringNotContainsString('support@example.com', $concise . $full);
    }

    /**
     * By default the `global` FAQ group is the one included.
     *
     * @return void
     */
    public function testTheGlobalFaqGroupIsIncludedByDefault(): void
    {
        $this->faq('global', 'Do you ship worldwide?');
        $this->faq('shipping', 'How long does delivery take?');

        [$concise, $full] = $this->build();

        foreach ([$concise, $full] as $document) {
            $this->assertStringContainsString("Frequently asked questions:\n", $document);
            $this->assertStringContainsString('- **Do you ship worldwide?** ', $document);
            $this->assertStringNotContainsString('How long does delivery take?', $document);
        }
    }

    /**
     * The configured FAQ groups are included, in the configured order.
     *
     * @return void
     */
    #[Config(self::FAQ_GROUPS, 'global,shipping', ScopeInterface::SCOPE_STORE, 'default')]
    public function testTheConfiguredFaqGroupsAreIncluded(): void
    {
        $this->faq('shipping', 'How long does delivery take?');
        $this->faq('global', 'Do you ship worldwide?');

        [$concise, $full] = $this->build();

        foreach ([$concise, $full] as $document) {
            $global   = strpos($document, '**Do you ship worldwide?**');
            $shipping = strpos($document, '**How long does delivery take?**');
            $this->assertIsInt($global, 'The global question is missing.');
            $this->assertIsInt($shipping, 'The shipping question is missing.');
            $this->assertLessThan($shipping, $global, 'The groups are not in the configured order.');
        }
    }

    /**
     * With no group selected, there is no FAQ section.
     *
     * @return void
     */
    #[Config(self::FAQ_GROUPS, '', ScopeInterface::SCOPE_STORE, 'default')]
    public function testNoFaqSectionWhenNoGroupIsSelected(): void
    {
        $this->faq('global', 'Do you ship worldwide?');

        [$concise, $full] = $this->build();

        $this->assertStringNotContainsString('Frequently asked questions:', $concise . $full);
    }

    /**
     * Rebuild the llms documents and return [llms.txt, llms-full.txt].
     *
     * @return string[]
     */
    private function build(): array
    {
        Bootstrap::getObjectManager()->create(FeedRegenerator::class)
            ->regenerate(FeedRegenerator::GROUP_LLMS);

        return [
            (string) $this->storage()->read('llms.txt', $this->storeId()),
            (string) $this->storage()->read('llms-full.txt', $this->storeId()),
        ];
    }

    /**
     * Save the default-scope Organization with a contact point email.
     *
     * @param string $email
     * @return void
     */
    private function organizationContact(string $email): void
    {
        $repository   = Bootstrap::getObjectManager()->get(OrganizationRepositoryInterface::class);
        $organization = $repository->get();
        $organization->setContactPoint(['email' => $email]);
        $repository->save($organization);
    }

    /**
     * Add a FAQ to a group of the test source, which is the only source in the pool.
     *
     * @param string $identifier
     * @param string $question
     * @return void
     */
    private function faq(string $identifier, string $question): void
    {
        $this->faqs[$identifier][] = $question;

        $objectManager = Bootstrap::getObjectManager();
        $objectManager->addSharedInstance(
            new SourcePool(['test' => $this->faqSource($this->faqs)]),
            SourcePool::class
        );
        // The builder and its FAQ section hold the pool they were built with.
        $objectManager->removeSharedInstance(FaqLlmsSectionProvider::class);
        $objectManager->removeSharedInstance(LlmsTxtBuilder::class);
    }

    /**
     * A FAQ source serving the given questions, each with the same answer, in every store view.
     *
     * @param array<string, string[]> $faqs
     * @return FaqSourceProviderInterface
     */
    private function faqSource(array $faqs): FaqSourceProviderInterface
    {
        return new class ($faqs) implements FaqSourceProviderInterface {
            /**
             * @param array<string, string[]> $faqs
             */
            public function __construct(private readonly array $faqs)
            {
            }

            /**
             * @inheritDoc
             */
            public function getFaqs(string $identifier, int $storeId): array
            {
                return array_map(
                    static fn (string $question): array => ['question' => $question, 'answer' => 'An answer.'],
                    $this->faqs[$identifier] ?? []
                );
            }

            /**
             * @inheritDoc
             */
            public function getIdentifiers(): array
            {
                return array_keys($this->faqs);
            }
        };
    }

    /**
     * The feed storage.
     *
     * @return FeedStorage
     */
    private function storage(): FeedStorage
    {
        return Bootstrap::getObjectManager()->create(FeedStorage::class);
    }

    /**
     * ID of the default store view.
     *
     * @return int
     */
    private function storeId(): int
    {
        return (int) Bootstrap::getObjectManager()->get(StoreManagerInterface::class)
            ->getStore('default')->getId();
    }
}
