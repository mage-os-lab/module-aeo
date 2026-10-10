<?php

declare(strict_types=1);

namespace MageOS\Aeo\Model\Feed\Remote;

// phpcs:disable Magento2.Functions.DiscouragedFunction -- builds the in-memory stream FeedFile reads

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\RemoteStorage\Model\Config as RemoteStorageConfig;
use MageOS\Aeo\Exception\FeedStorageUnavailableException;
use MageOS\Aeo\Model\Feed\FeedFile;
use MageOS\Aeo\Model\Feed\StorageLog;

/**
 * Opens a feed in remote storage through Magento's driver, for a remote driver other than S3.
 *
 * Magento's drivers read a file whole, so a feed served this way takes its size in memory per
 * request the full-page cache or a CDN does not answer. Magento ships only the S3 driver, which
 * S3FeedReader streams instead; this is the fallback, and says so in the log.
 */
class DriverFeedReader implements RemoteFeedReaderInterface
{
    /**
     * @param Filesystem $filesystem Magento's full remote filesystem (di.xml)
     * @param RemoteStorageConfig $remoteStorageConfig
     * @param StorageLog $storageLog
     */
    public function __construct(
        private readonly Filesystem          $filesystem,
        private readonly RemoteStorageConfig $remoteStorageConfig,
        private readonly StorageLog          $storageLog
    ) {
    }

    /**
     * @inheritDoc
     */
    public function open(string $path): ?FeedFile
    {
        $this->storageLog->notice(
            'MageOS_Aeo: feeds are read whole from remote storage, as its driver is not S3.',
            ['driver' => $this->remoteStorageConfig->getDriver()]
        );

        try {
            $directory = $this->filesystem->getDirectoryRead(DirectoryList::VAR_DIR);
            if (!$directory->isFile($path)) {
                return null;
            }
            $content = $directory->readFile($path);
        } catch (\Exception $e) {
            throw new FeedStorageUnavailableException(
                __('Remote storage could not be read: %1', $e->getMessage()),
                $e
            );
        }

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new FeedStorageUnavailableException(__('A feed read from remote storage could not be buffered.'));
        }
        fwrite($stream, $content);
        rewind($stream);

        return new FeedFile($stream, \strlen($content));
    }
}
