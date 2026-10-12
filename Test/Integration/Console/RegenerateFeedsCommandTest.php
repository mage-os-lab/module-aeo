<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Integration\Console;

use Magento\Framework\FlagManager;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Aeo\Model\Feed\FeedStorage;
use MageOS\Aeo\Test\Integration\CommitsDeferredRequests;
use MageOS\Seo\Console\Command\RegenerateFeedsCommand;
use MageOS\Seo\Setup\RecurringData;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * This module's groups through MageOS_Seo's rebuild entry points outside the queue: the
 * `seo:rebuild` command and the setup hook. The llms documents' handler is registered in
 * MageOS_Seo's handler pool, which both read.
 *
 * @magentoAppArea global
 * @magentoDbIsolation enabled
 */
class RegenerateFeedsCommandTest extends TestCase
{
    use CommitsDeferredRequests;

    /**
     * Start without the documents.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->storage()->deleteForStore('llms*.txt', $this->storeId());
    }

    /**
     * Remove the feed files the tests wrote; the storage directory is not rolled back.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->storage()->deleteForStore('llms*.txt', $this->storeId());
        Bootstrap::getObjectManager()->removeSharedInstance(FeedStorage::class);
    }

    /**
     * Running the command builds the requested feed group immediately.
     *
     * @return void
     */
    public function testTheCommandBuildsTheRequestedGroup(): void
    {
        $tester = new CommandTester(
            Bootstrap::getObjectManager()->create(RegenerateFeedsCommand::class)
        );

        $this->assertSame(Command::SUCCESS, $tester->execute(['--group' => ['llms']]), $tester->getDisplay());
        $this->assertStringStartsWith('# ', (string) $this->storage()->read('llms.txt', $this->storeId()));
        $this->assertNotNull($this->storage()->read('llms-full.txt', $this->storeId()));
    }

    /**
     * Each setup run queues a rebuild of the feeds the store views can build.
     *
     * @return void
     */
    public function testSetupQueuesARebuildOfTheBuildableFeeds(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $flags         = $objectManager->get(FlagManager::class);
        foreach (['llms', 'jsonl', 'hreflang'] as $group) {
            $flags->deleteFlag('mageos_seo_feed_pending_' . $group);
        }

        $objectManager->create(RecurringData::class)->install(
            $this->createStub(ModuleDataSetupInterface::class),
            $this->createStub(ModuleContextInterface::class)
        );

        // Default configuration on a single store view: only llms.txt / llms-full.txt can be built.
        // The requests wait for a commit the test's transaction never makes.
        $this->commitDeferredRequests();
        $this->assertIsNumeric($flags->getFlagData('mageos_seo_feed_pending_llms'));
        $this->assertNull($flags->getFlagData('mageos_seo_feed_pending_jsonl'));
        $this->assertNull($flags->getFlagData('mageos_seo_feed_pending_hreflang'));
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
        return (int) Bootstrap::getObjectManager()
            ->get(StoreManagerInterface::class)
            ->getStore('default')
            ->getId();
    }
}
