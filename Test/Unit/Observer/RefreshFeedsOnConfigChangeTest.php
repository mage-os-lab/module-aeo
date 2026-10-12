<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Unit\Observer;

use Magento\Config\Model\ResourceModel\Config\Data as ConfigDataResource;
use Magento\Framework\App\Config\Value as ConfigValue;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use MageOS\Aeo\Model\Feed\DocumentRetirement;
use MageOS\Aeo\Model\Feed\FeedCache;
use MageOS\Aeo\Model\Feed\FeedConfigDependencies;
use MageOS\Aeo\Model\Feed\FeedRegenerator;
use MageOS\Aeo\Observer\RefreshFeedsOnConfigChange;
use MageOS\Seo\Model\Rebuild\Invalidator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Issue #4: a configuration change reaches the feeds that depend on it, once it is committed.
 */
class RefreshFeedsOnConfigChangeTest extends TestCase
{
    /**
     * @var DocumentRetirement&MockObject
     */
    private DocumentRetirement&MockObject $retirement;

    /**
     * @var Invalidator&MockObject
     */
    private Invalidator&MockObject $invalidator;

    /**
     * Callbacks registered for the commit.
     *
     * @var callable[]
     */
    private array $onCommit = [];

    /**
     * The open transaction level the save runs in.
     *
     * @var int
     */
    private int $transactionLevel = 1;

    protected function setUp(): void
    {
        $this->retirement  = $this->createMock(DocumentRetirement::class);
        $this->invalidator = $this->createMock(Invalidator::class);
    }

    public function testASwitchRetiresItsDocumentForTheValuesScopeOnceCommitted(): void
    {
        $this->retirement->expects($this->once())->method('retire')->with(
            ['group' => FeedRegenerator::GROUP_JSONL, 'file' => 'llms.jsonl', 'tag' => FeedCache::TAG_LLMS_JSONL],
            'websites',
            2
        );
        $this->invalidator->expects($this->never())->method('invalidate');

        $this->observe('config_data_save_after', $this->value('mageos_aeo/llms_txt/jsonl_enabled', 'websites', 2));
        $this->assertCount(1, $this->onCommit, 'Nothing happens before the commit.');
        $this->commit();
    }

    public function testAValueTheFeedsShowRebuildsEachFeedShowingIt(): void
    {
        $this->retirement->expects($this->never())->method('retire');
        $invalidated = [];
        $this->invalidator->expects($this->exactly(2))->method('invalidate')->willReturnCallback(
            static function (string $group) use (&$invalidated): void {
                $invalidated[] = $group;
            }
        );

        $this->observe('config_data_save_after', $this->value('web/secure/base_url'));
        $this->commit();

        $this->assertSame([FeedRegenerator::GROUP_LLMS, FeedRegenerator::GROUP_JSONL], $invalidated);
    }

    public function testAnUnchangedValueOrOneNoFeedDependsOnDoesNothing(): void
    {
        $this->retirement->expects($this->never())->method('retire');
        $this->invalidator->expects($this->never())->method('invalidate');

        $this->observe('config_data_save_after', $this->value('mageos_aeo/llms_txt/enabled', 'default', 0, false));
        $this->observe('config_data_save_after', $this->value('contact/email/recipient_email'));
        $this->observe('config_data_save_after', new \Magento\Framework\DataObject());

        $this->assertSame([], $this->onCommit);
    }

    public function testADeletionAlwaysCounts(): void
    {
        // "Use Default" removes the store's own value: what the store view gets can have changed.
        $this->retirement->expects($this->once())->method('retire')
            ->with($this->anything(), 'stores', 1);
        $this->invalidator->expects($this->never())->method('invalidate');

        $this->observe('config_data_delete_after', $this->value('mageos_aeo/llms_txt/enabled', 'stores', 1, false));
        $this->commit();
    }

    public function testOutsideATransactionItActsAtOnce(): void
    {
        // app:config:import runs a value's afterSave() with no save, and so no commit to wait for.
        $this->transactionLevel = 0;
        $this->retirement->expects($this->once())->method('retire');
        $this->invalidator->expects($this->never())->method('invalidate');

        $this->observe('config_data_save_after', $this->value('mageos_aeo/llms_txt/enabled'));

        $this->assertSame([], $this->onCommit);
    }

    /**
     * Run the observer for an event carrying the given object.
     *
     * @param string $eventName
     * @param object $object
     * @return void
     */
    private function observe(string $eventName, object $object): void
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('getTransactionLevel')->willReturn($this->transactionLevel);
        $resource = $this->createStub(ConfigDataResource::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('addCommitCallback')->willReturnCallback(
            function (callable $callback) use ($resource): ConfigDataResource {
                $this->onCommit[] = $callback;
                return $resource;
            }
        );

        $observer = new RefreshFeedsOnConfigChange(
            new FeedConfigDependencies(),
            $this->retirement,
            $this->invalidator,
            $resource
        );
        $observer->execute(new Observer([
            'event' => new Event(['name' => $eventName, 'data_object' => $object]),
        ]));
    }

    /**
     * Run the callbacks registered for the commit.
     *
     * @return void
     */
    private function commit(): void
    {
        foreach ($this->onCommit as $callback) {
            $callback();
        }
        $this->onCommit = [];
    }

    /**
     * A configuration value being saved.
     *
     * @param string $path
     * @param string $scope
     * @param int $scopeId
     * @param bool $changed
     * @return ConfigValue
     */
    private function value(string $path, string $scope = 'default', int $scopeId = 0, bool $changed = true): ConfigValue
    {
        $value = new class ($changed) extends ConfigValue {
            /**
             * @param bool $changed
             */
            public function __construct(private readonly bool $changed)
            {
            }

            /**
             * @inheritdoc
             */
            public function isValueChanged()
            {
                return $this->changed;
            }
        };
        $value->setData(['path' => $path, 'scope' => $scope, 'scope_id' => $scopeId]);

        return $value;
    }
}
