<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Unit\Model\Feed;

use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Aeo\Exception\FeedRebuildInProgressException;
use MageOS\Aeo\Model\Config;
use MageOS\Aeo\Model\Feed\FeedCache;
use MageOS\Aeo\Model\Feed\FeedFileWriter;
use MageOS\Aeo\Model\Feed\FeedRegenerator;
use MageOS\Aeo\Model\Feed\FeedStorage;
use MageOS\Aeo\Model\Feed\RebuildLock;
use MageOS\Aeo\Model\LlmsJsonl\JsonlBuilder;
use MageOS\Aeo\Model\LlmsTxt\LlmsTxtBuilder;
use MageOS\Seo\Model\Rebuild\ProblemLog;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class FeedRegeneratorTest extends TestCase
{
    /**
     * @var StoreManagerInterface&Stub
     */
    private StoreManagerInterface&Stub $storeManager;

    /**
     * @var Emulation&Stub
     */
    private Emulation&Stub $emulation;

    /**
     * @var Config&Stub
     */
    private Config&Stub $seoConfig;

    /**
     * @var LlmsTxtBuilder&Stub
     */
    private LlmsTxtBuilder&Stub $llmsTxtBuilder;

    /**
     * @var JsonlBuilder&Stub
     */
    private JsonlBuilder&Stub $jsonlBuilder;

    /**
     * @var FeedStorage&Stub
     */
    private FeedStorage&Stub $feedStorage;

    /**
     * @var FeedCache&Stub
     */
    private FeedCache&Stub $feedCache;

    /**
     * @var LoggerInterface&Stub
     */
    private LoggerInterface&Stub $logger;

    /**
     * Set only by the tests that care whether the rebuild lock was free.
     *
     * @var RebuildLock|null
     */
    private ?RebuildLock $rebuildLock = null;

    /**
     * Set only by the tests that look at what is recorded for the admin.
     *
     * @var ProblemLog|null
     */
    private ?ProblemLog $problemLog = null;

    /**
     * Storage and cache calls in the order they happened.
     *
     * @var list<string>
     */
    private array $calls = [];

    /**
     * Store view IDs the storage reports as having a feed directory.
     *
     * @var int[]
     */
    private array $storeDirectories = [];

    protected function setUp(): void
    {
        $this->storeManager      = $this->createStub(StoreManagerInterface::class);
        $this->emulation         = $this->createStub(Emulation::class);
        $this->seoConfig         = $this->createStub(Config::class);
        $this->llmsTxtBuilder    = $this->createStub(LlmsTxtBuilder::class);
        $this->jsonlBuilder      = $this->createStub(JsonlBuilder::class);
        $this->feedStorage       = $this->createStub(FeedStorage::class);
        $this->feedCache         = $this->createStub(FeedCache::class);
        $this->logger            = $this->createStub(LoggerInterface::class);
        $this->calls             = [];
        $this->storeDirectories  = [];

        $this->feedStorage->method('listStoreDirectories')->willReturnCallback(
            fn (): array => $this->storeDirectories
        );
        $this->feedStorage->method('deleteStoreDirectory')->willReturnCallback(
            function (int $storeId): void {
                $this->calls[] = "delete directory {$storeId}";
            }
        );

        $this->feedStorage->method('write')->willReturnCallback(
            function (string $fileName, int $storeId, string $content): void {
                $this->calls[] = "write {$storeId}/{$fileName}={$content}";
            }
        );
        $this->feedStorage->method('openForWrite')->willReturnCallback(
            fn (int $storeId): FeedFileWriter => $this->recordingFile($storeId)
        );
        $this->feedStorage->method('deleteForStore')->willReturnCallback(
            function (string $pattern, int $storeId): void {
                $this->calls[] = "delete {$storeId}/{$pattern}";
            }
        );
        $this->feedCache->method('purge')->willReturnCallback(
            function (array $groups): void {
                $this->calls[] = 'purge ' . implode(',', $groups);
            }
        );
    }

    public function testInactiveStoresAreSkipped(): void
    {
        $store = $this->createStub(Store::class);
        $store->method('getIsActive')->willReturn(false);
        $this->storeManager->method('getStores')->willReturn([$store]);
        $emulation = $this->createMock(Emulation::class);
        $emulation->expects($this->never())->method('startEnvironmentEmulation');
        $this->emulation = $emulation;

        $this->regenerator()->regenerate(FeedRegenerator::GROUP_LLMS);

        $this->assertSame(['purge llms'], $this->calls);
    }

    public function testGroupFilterBuildsOnlyTheRequestedGroup(): void
    {
        $this->storeManager->method('getStores')->willReturn([$this->activeStore()]);
        $this->seoConfig->method('isLlmsTxtEnabled')->willReturn(true);
        $this->seoConfig->method('isLlmsFullTxtEnabled')->willReturn(true);
        $this->llmsTxtBuilder->method('buildConcise')->willReturn('concise');
        $this->llmsTxtBuilder->method('buildFull')->willReturn('full');
        $jsonlBuilder = $this->createMock(JsonlBuilder::class);
        $jsonlBuilder->expects($this->never())->method('stream');
        $this->jsonlBuilder = $jsonlBuilder;

        $this->regenerator()->regenerate(FeedRegenerator::GROUP_LLMS);

        $this->assertSame(
            ['write 1/llms.txt=concise', 'write 1/llms-full.txt=full', 'purge llms'],
            $this->calls
        );
    }

    public function testJsonlIsStreamedToItsFileInsteadOfBuiltInMemory(): void
    {
        $this->storeManager->method('getStores')->willReturn([$this->activeStore()]);
        $this->seoConfig->method('isLlmsJsonlEnabled')->willReturn(true);
        $this->jsonlBuilder->method('stream')->willReturnCallback(
            static function (): \Generator {
                yield "{\"a\":1}\n";
                yield "{\"b\":2}\n";
            }
        );

        $this->regenerator()->regenerate(FeedRegenerator::GROUP_JSONL);

        $this->assertSame(
            ['stream 1: {"a":1}' . "\n" . '{"b":2}' . "\n" . ' -> llms.jsonl', 'purge jsonl'],
            $this->calls
        );
    }

    public function testAFailedStreamDiscardsThePartialFile(): void
    {
        $this->storeManager->method('getStores')->willReturn([$this->activeStore()]);
        $this->seoConfig->method('isLlmsJsonlEnabled')->willReturn(true);
        $this->jsonlBuilder->method('stream')->willReturnCallback(
            static function (): \Generator {
                yield "{\"a\":1}\n";
                throw new \RuntimeException('collection failed');
            }
        );

        $failures = $this->regenerator()->regenerate(FeedRegenerator::GROUP_JSONL);

        $this->assertSame([1 => 'collection failed'], $failures);
        $this->assertContains('discard 1', $this->calls);
        $this->assertNotContains('commit 1 llms.jsonl', $this->calls);
    }

    public function testDisabledFeedsAreRemovedInsteadOfWritten(): void
    {
        $this->storeManager->method('getStores')->willReturn([$this->activeStore()]);
        $this->seoConfig->method('isLlmsTxtEnabled')->willReturn(false);
        $this->seoConfig->method('isLlmsFullTxtEnabled')->willReturn(false);
        $this->seoConfig->method('isLlmsJsonlEnabled')->willReturn(false);
        $llmsTxtBuilder = $this->createMock(LlmsTxtBuilder::class);
        $llmsTxtBuilder->expects($this->never())->method('buildConcise');
        $llmsTxtBuilder->expects($this->never())->method('buildFull');
        $this->llmsTxtBuilder = $llmsTxtBuilder;

        $this->regenerator()->regenerate(FeedRegenerator::GROUP_LLMS);
        $this->regenerator()->regenerate(FeedRegenerator::GROUP_JSONL);

        $this->assertSame(
            ['delete 1/llms.txt', 'delete 1/llms-full.txt', 'purge llms', 'delete 1/llms.jsonl', 'purge jsonl'],
            $this->calls
        );
    }

    public function testRebuiltGroupIsPurgedOnceAfterEveryStore(): void
    {
        $this->storeManager->method('getStores')->willReturn([$this->activeStore(1), $this->activeStore(2)]);
        $this->seoConfig->method('isLlmsJsonlEnabled')->willReturn(true);
        $this->jsonlBuilder->method('stream')->willReturnCallback(
            static function (): \Generator {
                yield 'lines';
            }
        );

        $this->regenerator()->regenerate(FeedRegenerator::GROUP_JSONL);

        $this->assertSame(
            ['stream 1: lines -> llms.jsonl', 'stream 2: lines -> llms.jsonl', 'purge jsonl'],
            $this->calls
        );
    }

    public function testAFullRebuildSweepsTheDirectoriesOfStoreViewsThatNoLongerExist(): void
    {
        // Deleting a website or a store group takes its store views with it in the database,
        // dispatching no event, so the directories are swept rather than chased.
        $this->storeManager->method('getStores')->willReturn([$this->activeStore(1)]);
        $this->storeDirectories = [1, 7, 9];

        $this->regenerator()->regenerate();

        // The store view that still exists keeps its directory; the build's own calls come first.
        $this->assertSame(
            ['delete directory 7', 'delete directory 9', 'purge llms,jsonl'],
            \array_slice($this->calls, -3)
        );
    }

    public function testASingleGroupRebuildDoesNotSweepDirectories(): void
    {
        $this->storeManager->method('getStores')->willReturn([]);
        $this->storeDirectories = [7];

        $this->regenerator()->regenerate(FeedRegenerator::GROUP_JSONL);

        $this->assertSame(['purge jsonl'], $this->calls);
    }

    public function testFullRebuildPurgesEveryGroup(): void
    {
        $this->storeManager->method('getStores')->willReturn([]);

        $this->regenerator()->regenerate();

        $this->assertSame(['purge llms,jsonl'], $this->calls);
    }

    public function testPurgeFailureIsLoggedNotThrown(): void
    {
        $this->storeManager->method('getStores')->willReturn([]);
        $feedCache = $this->createStub(FeedCache::class);
        $feedCache->method('purge')->willThrowException(new \RuntimeException('varnish down'));
        $this->feedCache = $feedCache;
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('varnish down'));
        $this->logger = $logger;

        $this->regenerator()->regenerate(FeedRegenerator::GROUP_LLMS);
    }

    public function testAFailingStoreIsLoggedAndTheOtherStoresAreStillBuilt(): void
    {
        $this->storeManager->method('getStores')->willReturn([$this->activeStore(1), $this->activeStore(2)]);
        $this->seoConfig->method('isLlmsTxtEnabled')->willReturn(true);
        $builds = 0;
        $this->llmsTxtBuilder->method('buildConcise')->willReturnCallback(
            static function () use (&$builds): string {
                if (++$builds === 1) {
                    throw new \RuntimeException('build failed');
                }
                return 'concise';
            }
        );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')->with($this->stringContains('store 1'));
        $this->logger = $logger;

        $failures = $this->regenerator()->regenerate(FeedRegenerator::GROUP_LLMS);

        // llms-full.txt is disabled in this test, so it is removed for the store that built.
        $this->assertSame(['write 2/llms.txt=concise', 'delete 2/llms-full.txt', 'purge llms'], $this->calls);
        $this->assertSame([1 => 'build failed'], $failures);
    }

    public function testASuccessfulRebuildReportsNoFailures(): void
    {
        $this->storeManager->method('getStores')->willReturn([$this->activeStore()]);

        $this->assertSame([], $this->regenerator()->regenerate(FeedRegenerator::GROUP_JSONL));
    }

    public function testEmulationIsStoppedInFinallyOnThrow(): void
    {
        $this->storeManager->method('getStores')->willReturn([$this->activeStore()]);
        $this->seoConfig->method('isLlmsTxtEnabled')->willReturn(true);
        $this->llmsTxtBuilder->method('buildConcise')->willThrowException(new \RuntimeException('build failed'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');
        $this->logger = $logger;
        $emulation = $this->createMock(Emulation::class);
        $emulation->expects($this->once())->method('stopEnvironmentEmulation');
        $this->emulation = $emulation;

        $this->regenerator()->regenerate(FeedRegenerator::GROUP_LLMS);
    }

    /**
     * Build the regenerator from the current collaborators.
     *
     * @return FeedRegenerator
     */
    private function regenerator(): FeedRegenerator
    {
        return new FeedRegenerator(
            $this->storeManager,
            $this->emulation,
            $this->seoConfig,
            $this->llmsTxtBuilder,
            $this->jsonlBuilder,
            $this->feedStorage,
            $this->feedCache,
            $this->logger,
            $this->rebuildLock ?? $this->freeRebuildLock(),
            $this->problemLog ?? $this->createStub(ProblemLog::class)
        );
    }

    public function testEachGroupsResultIsRecordedForTheAdmin(): void
    {
        $this->storeManager->method('getStores')->willReturn([$this->activeStore(1), $this->activeStore(2)]);
        $this->seoConfig->method('isLlmsTxtEnabled')->willReturn(true);
        $this->llmsTxtBuilder->method('buildConcise')->willThrowException(new \RuntimeException('build failed'));
        $recorded = $this->recordingProblemLog();

        $this->regenerator()->regenerate();

        $this->assertSame(
            [
                'rebuilding llms',
                'rebuilding jsonl',
                'rebuilt llms {"1":"build failed","2":"build failed"}',
                'rebuilt jsonl {}',
            ],
            $recorded->calls
        );
    }

    public function testOneGroupFailingDoesNotStopOrBlameTheOther(): void
    {
        $this->storeManager->method('getStores')->willReturn([$this->activeStore()]);
        $this->seoConfig->method('isLlmsTxtEnabled')->willReturn(true);
        $this->seoConfig->method('isLlmsJsonlEnabled')->willReturn(true);
        $this->llmsTxtBuilder->method('buildConcise')->willThrowException(new \RuntimeException('build failed'));
        $this->jsonlBuilder->method('stream')->willReturnCallback(
            static function (): \Generator {
                yield 'lines';
            }
        );
        $recorded = $this->recordingProblemLog();

        $failures = $this->regenerator()->regenerate();

        $this->assertContains('stream 1: lines -> llms.jsonl', $this->calls);
        $this->assertContains('rebuilt jsonl {}', $recorded->calls);
        $this->assertSame([1 => 'build failed'], $failures, 'The command still gets one message per store view.');
    }

    public function testAStoreViewWhoseGroupsBothFailHasOneMessageNamingBoth(): void
    {
        $this->storeManager->method('getStores')->willReturn([$this->activeStore(1), $this->activeStore(2)]);
        $this->seoConfig->method('isLlmsTxtEnabled')->willReturn(true);
        $this->seoConfig->method('isLlmsJsonlEnabled')->willReturn(true);
        $this->llmsTxtBuilder->method('buildConcise')->willThrowException(new \RuntimeException('llms failed'));
        $this->jsonlBuilder->method('stream')->willThrowException(new \RuntimeException('jsonl failed'));

        $this->assertSame(
            [1 => 'llms failed; jsonl failed', 2 => 'llms failed; jsonl failed'],
            $this->regenerator()->regenerate()
        );
    }

    public function testABuildThatThrowsIsAProblemForEveryGroupItCovered(): void
    {
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willThrowException(new \RuntimeException('no stores'));
        $this->storeManager = $storeManager;
        $recorded           = $this->recordingProblemLog();

        try {
            $this->regenerator()->regenerate();
            $this->fail('The exception was swallowed.');
        } catch (\RuntimeException $e) {
            $this->assertSame('no stores', $e->getMessage());
        }

        $this->assertSame(
            [
                'rebuilding llms',
                'rebuilding jsonl',
                'rebuilt llms {"all":"no stores"}',
                'rebuilt jsonl {"all":"no stores"}',
            ],
            $recorded->calls
        );
    }

    public function testARebuildRefusedForTheLockRecordsNothing(): void
    {
        $lock = $this->createStub(RebuildLock::class);
        $lock->method('acquire')->willReturn(false);
        $this->rebuildLock = $lock;
        $recorded          = $this->recordingProblemLog();

        try {
            $this->regenerator()->regenerate();
        } catch (FeedRebuildInProgressException) {
            // Expected; what matters is that nothing was opened.
        }

        $this->assertSame([], $recorded->calls);
    }

    /**
     * A problem log that records the brackets it is given, as "rebuilding GROUP" and
     * "rebuilt GROUP FAILURES-AS-JSON".
     *
     * @return \stdClass With the calls in `calls`
     */
    private function recordingProblemLog(): \stdClass
    {
        $recorded        = new \stdClass();
        $recorded->calls = [];

        $problemLog = $this->createStub(ProblemLog::class);
        $problemLog->method('rebuilding')->willReturnCallback(
            static function (string $group) use ($recorded): void {
                $recorded->calls[] = 'rebuilding ' . $group;
            }
        );
        $problemLog->method('rebuilt')->willReturnCallback(
            static function (string $group, array $failures) use ($recorded): void {
                $recorded->calls[] = 'rebuilt ' . $group . ' ' . json_encode((object) $failures);
            }
        );
        $this->problemLog = $problemLog;

        return $recorded;
    }

    public function testNothingIsBuiltWhenAnotherProcessHoldsTheRebuildLock(): void
    {
        $lock = $this->createStub(RebuildLock::class);
        $lock->method('acquire')->willReturn(false);
        $this->rebuildLock = $lock;

        $this->expectException(FeedRebuildInProgressException::class);

        try {
            $this->regenerator()->regenerate(FeedRegenerator::GROUP_LLMS);
        } finally {
            // Not merely "returned nothing": no store view was touched at all.
            $this->assertSame([], $this->calls);
        }
    }

    public function testTheLockIsReleasedEvenWhenABuildThrows(): void
    {
        $released = 0;

        $lock = $this->createStub(RebuildLock::class);
        $lock->method('acquire')->willReturn(true);
        $lock->method('release')->willReturnCallback(
            function () use (&$released): void {
                $released++;
            }
        );
        $this->rebuildLock = $lock;

        // A store manager that throws is the harshest case: without the finally, the lock would
        // be held until the lock provider's own timeout and every later rebuild would be refused.
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStores')->willThrowException(new \RuntimeException('no stores'));
        $this->storeManager = $storeManager;

        try {
            $this->regenerator()->regenerate(FeedRegenerator::GROUP_LLMS);
        } catch (\RuntimeException) {
            // The point of the test is the release below, not this exception.
        }

        $this->assertSame(1, $released);
    }

    /**
     * A rebuild lock nobody else holds, which is the case for every test but one.
     *
     * @return RebuildLock
     */
    private function freeRebuildLock(): RebuildLock
    {
        $lock = $this->createStub(RebuildLock::class);
        $lock->method('acquire')->willReturn(true);

        return $lock;
    }

    /**
     * An active store view.
     *
     * @param int $id
     * @return Store
     */
    private function activeStore(int $id = 1): Store
    {
        $store = $this->createStub(Store::class);
        $store->method('getIsActive')->willReturn(true);
        $store->method('getId')->willReturn($id);

        return $store;
    }

    /**
     * A file writer that records what was streamed into it and how it ended.
     *
     * @param int $storeId
     * @return FeedFileWriter
     */
    private function recordingFile(int $storeId): FeedFileWriter
    {
        $content = '';

        $file = $this->createStub(FeedFileWriter::class);
        $file->method('write')->willReturnCallback(
            static function (string $part) use (&$content): void {
                $content .= $part;
            }
        );
        $file->method('commit')->willReturnCallback(
            function (string $fileName) use (&$content, $storeId): void {
                $this->calls[] = "stream {$storeId}: {$content} -> {$fileName}";
            }
        );
        $file->method('discard')->willReturnCallback(
            function () use ($storeId): void {
                $this->calls[] = "discard {$storeId}";
            }
        );

        return $file;
    }
}
