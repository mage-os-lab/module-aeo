<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Integration\Model\Feed;

use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\FlagManager;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Seo\Api\OrganizationRepositoryInterface;
use MageOS\Seo\Model\OrganizationRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The FAQs and the Organization feed /llms.txt and /llms-full.txt, so saving or deleting either
 * queues their rebuild however it is done — the admin form, the REST API, an import, a data patch.
 * The feeds learn of it from the models' own events rather than from a caller remembering to ask.
 *
 * The FAQ events are MageOS_Faq's model events. Their names are the contract between the two
 * modules, so they are dispatched here directly; MageOS_Faq tests that a save and a delete send them.
 *
 * @magentoAppArea adminhtml
 * @magentoDbIsolation enabled
 */
class LlmsSourceChangeInvalidationTest extends TestCase
{
    private const LLMS_PENDING = 'mageos_seo_feed_pending_llms';

    /**
     * Leave no pending request, and drop the Organizations the rolled-back saves left memoised.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        Bootstrap::getObjectManager()->get(FlagManager::class)->deleteFlag(self::LLMS_PENDING);
        Bootstrap::getObjectManager()->get(OrganizationRepository::class)->_resetState();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function faqEventProvider(): array
    {
        return [
            'saved'   => ['mageos_faq_save_after'],
            'deleted' => ['mageos_faq_delete_after'],
        ];
    }

    /**
     * @dataProvider faqEventProvider
     * @param string $eventName
     * @return void
     */
    #[DataProvider('faqEventProvider')]
    public function testAFaqEventQueuesLlms(string $eventName): void
    {
        $events = Bootstrap::getObjectManager()->get(ManagerInterface::class);

        $this->assertQueuedBy(fn () => $events->dispatch($eventName));
    }

    /**
     * @return void
     */
    public function testSavingTheOrganizationThroughTheRepositoryQueuesLlms(): void
    {
        $repository   = Bootstrap::getObjectManager()->get(OrganizationRepositoryInterface::class);
        $organization = $repository->get();
        $organization->setName('Makers Workshop');

        $this->assertQueuedBy(fn () => $repository->save($organization));
    }

    /**
     * Assert the change queues a rebuild of the llms documents.
     *
     * @param callable $change
     * @return void
     */
    private function assertQueuedBy(callable $change): void
    {
        $flags = Bootstrap::getObjectManager()->get(FlagManager::class);
        $flags->deleteFlag(self::LLMS_PENDING);

        $change();

        $this->assertNotNull($flags->getFlagData(self::LLMS_PENDING), 'No rebuild of the llms documents was queued.');
    }
}
