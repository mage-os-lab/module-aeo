<?php

declare(strict_types=1);

namespace MageOS\Aeo\Model\Feed\Remote;

use Magento\Framework\Exception\LocalizedException;
use MageOS\Aeo\Model\Feed\FeedRegenerator;
use MageOS\Aeo\Model\Feed\LocalFeedFiles;

/**
 * Copies the feeds already on local disk into Magento's remote storage, once it has been switched on.
 *
 * Run by `bin/magento remote-storage:sync`, the step Magento documents after switching remote
 * storage on (Plugin\RemoteStorage\CopyFeedsOnSynchronize), so the feeds are there before the
 * first rebuild writes them. Each store view's documents — llms.txt, llms-full.txt, llms.jsonl —
 * are copied from where the storage directory setting keeps them on disk to where it puts them in
 * remote storage, a piece at a time, and only when remote storage does not have them yet: as the
 * synchronisation does for media, a file already there is newer than any left on disk.
 */
class FeedCopier
{
    /**
     * Bytes read from a local feed and written to remote storage at a time.
     */
    private const PIECE_SIZE = 1048576;

    /**
     * @param LocalFeedFiles $localFeedFiles
     * @param RemoteFeedFiles $remoteFeedFiles
     */
    public function __construct(
        private readonly LocalFeedFiles  $localFeedFiles,
        private readonly RemoteFeedFiles $remoteFeedFiles
    ) {
    }

    /**
     * Copy the feeds remote storage lacks, yielding the path of each from its root as it is copied.
     *
     * Nothing while remote storage is off. A copy that fails stops the run, saying which file.
     *
     * @throws LocalizedException
     * @return \Generator<int,string>
     */
    public function copy(): \Generator
    {
        if (!$this->remoteFeedFiles->isEnabled()) {
            return;
        }

        foreach ($this->localFeedFiles->listStoreDirectories() as $storeId) {
            foreach (FeedRegenerator::FILES as $fileName) {
                if ($this->remoteFeedFiles->exists($fileName, $storeId)) {
                    continue;
                }
                $file = $this->localFeedFiles->open($fileName, $storeId);
                if ($file === null) {
                    continue;
                }

                $writer = $this->remoteFeedFiles->openForWrite($storeId);
                try {
                    // pieces() closes the local file, whether it is read to the end or given up.
                    foreach ($file->pieces(self::PIECE_SIZE) as $piece) {
                        $writer->write($piece);
                    }
                    $writer->commit($fileName);
                } catch (\Exception $e) {
                    $writer->discard();
                    throw new LocalizedException(
                        __(
                            'Could not copy %1 of store view %2 to remote storage: %3',
                            $fileName,
                            $storeId,
                            $e->getMessage()
                        ),
                        $e
                    );
                }

                yield $this->remoteFeedFiles->pathOf($fileName, $storeId);
            }
        }
    }
}
