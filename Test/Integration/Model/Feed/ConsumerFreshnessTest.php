<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Integration\Model\Feed;

use Magento\Framework\FlagManager;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Aeo\Model\Feed\FeedStorage;
use MageOS\Seo\Api\OrganizationRepositoryInterface;
use MageOS\Seo\Model\Organization;
use MageOS\Seo\Model\Rebuild\ProblemLog;
use MageOS\Seo\Model\Rebuild\RegenerateConsumer;
use MageOS\Seo\Model\ResourceModel\Organization as OrganizationResource;
use PHPUnit\Framework\TestCase;

/**
 * One consumer process builds every message it takes from current data (issue #9).
 *
 * Core's queue loop resets nothing between messages, so the Organization the first build loaded
 * would be the one every later build used. The change is saved through the resource model, as
 * another process would save it: this process's repository is never told.
 *
 * Builds commit their files and the Organization is saved for real, so database isolation is off;
 * the test puts the Organization back and removes what it wrote.
 *
 * @magentoAppArea adminhtml
 * @magentoDbIsolation disabled
 */
class ConsumerFreshnessTest extends TestCase
{
    /**
     * The default-scope Organization's name before the test, or null when it had none.
     *
     * @var string|null
     */
    private ?string $originalName = null;

    /**
     * Whether the default scope had an Organization row before the test.
     *
     * @var bool|null
     */
    private ?bool $hadOrganization = null;

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        if ($this->hadOrganization === true) {
            $this->saveNameAsAnotherProcess((string) $this->originalName);
        } elseif ($this->hadOrganization === false) {
            $objectManager->get(OrganizationRepositoryInterface::class)->deleteForScope('default', [0]);
        }
        $objectManager->create(FeedStorage::class)->deleteForStore('llms*', $this->storeId());
        $objectManager->get(FlagManager::class)->deleteFlag(ProblemLog::FLAG);
        $objectManager->get(FlagManager::class)->deleteFlag('mageos_seo_feed_pending_llms');
    }

    /**
     * The second message's llms.txt carries the Organization name changed between the two.
     *
     * @return void
     */
    public function testTheSecondBuildSeesAnOrganizationChangedBetweenMessages(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $repository    = $objectManager->get(OrganizationRepositoryInterface::class);
        $organization  = $repository->get('default', 0);
        $this->hadOrganization = (bool) $organization->getId();
        $this->originalName    = $organization->getName();
        $organization->setName('Before the change');
        $repository->save($organization);

        $consumer = $objectManager->get(RegenerateConsumer::class);
        $consumer->process('llms');
        $this->assertStringStartsWith('# Before the change', $this->llmsTxt());

        $this->saveNameAsAnotherProcess('After the change');
        $consumer->process('llms');

        $this->assertStringStartsWith('# After the change', $this->llmsTxt());
    }

    /**
     * Save the default-scope Organization's name the way another process does: through a model of
     * its own and the resource model, so this process's repository keeps what it had loaded.
     *
     * @param string $name
     * @return void
     */
    private function saveNameAsAnotherProcess(string $name): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $id            = (int) $objectManager->get(OrganizationRepositoryInterface::class)->get('default', 0)->getId();
        $resource      = $objectManager->get(OrganizationResource::class);
        $organization  = $objectManager->create(Organization::class);
        $resource->load($organization, $id);
        $organization->setName($name);
        $resource->save($organization);
    }

    /**
     * @return string
     */
    private function llmsTxt(): string
    {
        return (string) Bootstrap::getObjectManager()->create(FeedStorage::class)->read('llms.txt', $this->storeId());
    }

    /**
     * @return int
     */
    private function storeId(): int
    {
        return (int) Bootstrap::getObjectManager()->get(StoreManagerInterface::class)->getStore('default')->getId();
    }
}
