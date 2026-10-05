<?php

declare(strict_types=1);

namespace MageOS\Aeo\Model\Feed;

use Magento\Framework\App\Area;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Aeo\Exception\FeedRebuildInProgressException;
use MageOS\Aeo\Model\Config;
use MageOS\Aeo\Model\LlmsJsonl\JsonlBuilder;
use MageOS\Aeo\Model\LlmsTxt\LlmsTxtBuilder;
use MageOS\Seo\Model\Rebuild\ProblemLog;
use Psr\Log\LoggerInterface;

/**
 * Builds the pre-generated SEO feeds to storage for every active store view.
 *
 * Shared by the nightly cron (full rebuild) and the queue consumer (single feed
 * group after an invalidation). Whole-catalog builds therefore happen only in
 * background processes, never inside anonymous web requests.
 *
 * Served files are replaced in place (see FeedStorage::write()), so a feed stays
 * available while it is rebuilt; the cached responses of the rebuilt groups are purged
 * once all stores are done. Files of a feed that is disabled for a store are removed,
 * so re-enabling it later triggers a fresh build instead of serving an outdated file.
 */
class FeedRegenerator
{
    public const GROUP_LLMS  = 'llms';
    public const GROUP_JSONL = 'jsonl';

    public const GROUPS = [self::GROUP_LLMS, self::GROUP_JSONL];

    private const FILE_LLMS      = 'llms.txt';
    private const FILE_LLMS_FULL = 'llms-full.txt';
    private const FILE_JSONL     = 'llms.jsonl';

    /**
     * @param StoreManagerInterface $storeManager
     * @param Emulation $emulation
     * @param Config $aeoConfig
     * @param LlmsTxtBuilder $llmsTxtBuilder
     * @param JsonlBuilder $jsonlBuilder
     * @param FeedStorage $feedStorage
     * @param FeedCache $feedCache
     * @param LoggerInterface $logger
     * @param RebuildLock $rebuildLock
     * @param ProblemLog $problemLog
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly Emulation             $emulation,
        private readonly Config                $aeoConfig,
        private readonly LlmsTxtBuilder        $llmsTxtBuilder,
        private readonly JsonlBuilder          $jsonlBuilder,
        private readonly FeedStorage           $feedStorage,
        private readonly FeedCache             $feedCache,
        private readonly LoggerInterface       $logger,
        private readonly RebuildLock           $rebuildLock,
        private readonly ProblemLog            $problemLog
    ) {
    }

    /**
     * Regenerate one feed group (or all, when null) for every active store view.
     *
     * A failing store view is logged and skipped so the others are still built; the
     * failures are returned for callers that report them (the CLI command). Each group's result
     * is also recorded for the admin (MageOS_Seo's ProblemLog), whichever process ran it: the
     * cron, the queue consumer or the command.
     *
     * @param string|null $group One of self::GROUPS, or null for all
     * @throws FeedRebuildInProgressException When another process is already building
     * @return array<int, string> Error message per failed store view ID
     */
    public function regenerate(?string $group = null): array
    {
        if (!$this->rebuildLock->acquire()) {
            throw new FeedRebuildInProgressException(
                __('A feed rebuild is already running; this one was not started.')
            );
        }

        $groups = $group === null ? self::GROUPS : [$group];
        foreach ($groups as $each) {
            $this->problemLog->rebuilding($each);
        }

        try {
            $failures = $this->build($groups, $group === null);
        } catch (\Throwable $e) {
            foreach ($groups as $each) {
                $this->problemLog->rebuilt($each, [ProblemLog::ALL => $e->getMessage()]);
            }
            throw $e;
        } finally {
            $this->rebuildLock->release();
        }

        $byStore = [];
        foreach ($groups as $each) {
            $this->problemLog->rebuilt($each, $failures[$each] ?? []);
            foreach ($failures[$each] ?? [] as $storeId => $message) {
                $byStore[$storeId] = isset($byStore[$storeId]) ? $byStore[$storeId] . '; ' . $message : $message;
            }
        }

        return $byStore;
    }

    /**
     * Build the feeds, with the rebuild lock already held.
     *
     * Each group of each store view is built on its own, so one group failing neither stops the
     * other nor is blamed on it.
     *
     * @param string[] $groups
     * @param bool $everything Whether this is the full rebuild, which also sweeps orphaned directories
     * @return array<string, array<int, string>> Error message per group and failed store view ID
     */
    private function build(array $groups, bool $everything): array
    {
        $failures = [];
        foreach ($this->storeManager->getStores() as $store) {
            if (!$store->getIsActive()) {
                continue;
            }
            $storeId = (int) $store->getId();

            $this->emulation->startEnvironmentEmulation($storeId, Area::AREA_FRONTEND, true);
            try {
                foreach ($groups as $group) {
                    try {
                        $this->generateForStore($storeId, $group);
                    } catch (\Throwable $e) {
                        $failures[$group][$storeId] = $e->getMessage();
                        $this->logger->error(
                            \sprintf(
                                'MageOS_Aeo: feed regeneration failed for store %d: %s',
                                $storeId,
                                $e->getMessage()
                            ),
                            ['exception' => $e, 'group' => $group]
                        );
                    }
                }
            } finally {
                $this->emulation->stopEnvironmentEmulation();
            }
        }

        if ($everything) {
            $this->removeOrphanedStoreDirectories();
        }

        $this->purgeCachedResponses($groups);

        return $failures;
    }

    /**
     * Remove feed directories of store views that no longer exist.
     *
     * Deleting a store view directly removes its directory straight away (see
     * Observer\RemoveFeedFilesOnStoreDelete), but deleting a store group or a website takes its
     * store views with it through a database-level cascade that dispatches no store_delete
     * event. Nothing serves the leftover files — a request resolves feeds for the current store
     * view — so they are swept here on the full rebuild rather than chased through events.
     *
     * Inactive store views keep their files: they are still store views, and re-activating one
     * should not have to wait for a rebuild.
     *
     * @return void
     */
    private function removeOrphanedStoreDirectories(): void
    {
        $existing = [];
        // With the default store view (admin, ID 0): nothing writes its feeds, but a directory
        // of that name is not an orphan either.
        foreach ($this->storeManager->getStores(true) as $store) {
            $existing[(int) $store->getId()] = true;
        }

        foreach ($this->feedStorage->listStoreDirectories() as $storeId) {
            if (!isset($existing[$storeId])) {
                $this->feedStorage->deleteStoreDirectory($storeId);
            }
        }
    }

    /**
     * Generate one group's feeds for the currently emulated store.
     *
     * @param int $storeId
     * @param string $group
     * @throws \Magento\Framework\Exception\FileSystemException
     * @return void
     */
    private function generateForStore(int $storeId, string $group): void
    {
        if ($group === self::GROUP_LLMS) {
            $this->writeOrRemove(
                self::FILE_LLMS,
                $storeId,
                $this->aeoConfig->isLlmsTxtEnabled($storeId),
                fn (): string => $this->llmsTxtBuilder->buildConcise()
            );
            $this->writeOrRemove(
                self::FILE_LLMS_FULL,
                $storeId,
                $this->aeoConfig->isLlmsFullTxtEnabled($storeId),
                fn (): string => $this->llmsTxtBuilder->buildFull()
            );
        }

        if ($group === self::GROUP_JSONL) {
            if ($this->aeoConfig->isLlmsJsonlEnabled($storeId)) {
                $this->writeStream(self::FILE_JSONL, $storeId, $this->jsonlBuilder->stream());
            } else {
                $this->feedStorage->deleteForStore(self::FILE_JSONL, $storeId);
            }
        }
    }

    /**
     * Write a feed from a stream of content, without holding the document in memory.
     *
     * @param string $fileName
     * @param int $storeId
     * @param iterable<string> $content
     * @throws \Magento\Framework\Exception\FileSystemException
     * @return void
     */
    private function writeStream(string $fileName, int $storeId, iterable $content): void
    {
        $writer = $this->feedStorage->openForWrite($storeId);

        try {
            foreach ($content as $part) {
                $writer->write($part);
            }
            $writer->commit($fileName);
        } catch (\Exception $e) {
            $writer->discard();
            throw $e;
        }
    }

    /**
     * Write a single-file feed when it is enabled for the store, otherwise remove it.
     *
     * @param string $fileName
     * @param int $storeId
     * @param bool $enabled
     * @param callable $build Returns the file content
     * @throws \Magento\Framework\Exception\FileSystemException
     * @return void
     */
    private function writeOrRemove(string $fileName, int $storeId, bool $enabled, callable $build): void
    {
        if ($enabled) {
            $this->feedStorage->write($fileName, $storeId, $build());
            return;
        }

        $this->feedStorage->deleteForStore($fileName, $storeId);
    }

    /**
     * Purge the cached responses of the rebuilt groups (best effort).
     *
     * A purge failure is logged: the new files are in place and the cached copies
     * expire on their own.
     *
     * @param string[] $groups
     * @return void
     */
    private function purgeCachedResponses(array $groups): void
    {
        try {
            $this->feedCache->purge($groups);
        } catch (\Throwable $e) {
            $this->logger->error(
                'MageOS_Aeo: could not purge cached feed responses: ' . $e->getMessage(),
                ['exception' => $e, 'groups' => $groups]
            );
        }
    }
}
