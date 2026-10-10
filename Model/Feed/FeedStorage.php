<?php

declare(strict_types=1);

namespace MageOS\Aeo\Model\Feed;

use Magento\Framework\Exception\FileSystemException;
use MageOS\Aeo\Exception\FeedStorageUnavailableException;
use MageOS\Aeo\Model\Feed\Remote\RemoteFeedFiles;

/**
 * File storage for pre-generated SEO feeds (llms.txt, llms-full.txt, llms.jsonl), one
 * directory per store view.
 *
 * While Magento's remote storage is on, the feeds are kept in it (Remote\RemoteFeedFiles), so every
 * web server serves what the host running the rebuilds writes. Otherwise they are on local disk, or
 * a mount the servers share (LocalFeedFiles). The storage directory setting says where in either:
 * an absolute path on disk, or the same path taken from the remote storage's root.
 *
 * Everything that reads, writes or removes feed files does it through here.
 */
class FeedStorage implements FeedFilesInterface
{
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
     * Persist a feed file for a store, replacing any existing file atomically.
     *
     * @param string $fileName
     * @param int $storeId
     * @param string $content
     * @throws FileSystemException
     * @throws FeedStorageUnavailableException
     * @return void
     */
    public function write(string $fileName, int $storeId, string $content): void
    {
        $this->files()->write($fileName, $storeId, $content);
    }

    /**
     * Open a writer that builds one feed file for a store incrementally.
     *
     * The document is streamed out (llms.jsonl, a line per product) instead of held in memory;
     * the served file name is given to commit(), and the file appears only then.
     *
     * @param int $storeId
     * @throws FileSystemException
     * @throws FeedStorageUnavailableException
     * @return FeedWriterInterface
     */
    public function openForWrite(int $storeId): FeedWriterInterface
    {
        return $this->files()->openForWrite($storeId);
    }

    /**
     * Open a feed file for a store, or null when there is none to serve.
     *
     * @param string $fileName
     * @param int $storeId
     * @throws FeedStorageUnavailableException When the storage cannot be read from here: no file
     *                                         there is a fault a rebuild cannot fix
     * @return FeedFile|null
     */
    public function open(string $fileName, int $storeId): ?FeedFile
    {
        return $this->files()->open($fileName, $storeId);
    }

    /**
     * Read a feed file for a store, or null when it has not been generated.
     *
     * The whole file as a string. The controllers use open() instead, through FeedDelivery, which
     * streams a large file rather than reading it into memory.
     *
     * @param string $fileName
     * @param int $storeId
     * @throws FeedStorageUnavailableException When the storage cannot be read from here
     * @return string|null
     */
    public function read(string $fileName, int $storeId): ?string
    {
        return $this->files()->read($fileName, $storeId);
    }

    /**
     * Delete matching feed files for one store.
     *
     * @param string $fileNamePattern Glob pattern, e.g. "llms*.txt"
     * @param int $storeId
     * @return void
     */
    public function deleteForStore(string $fileNamePattern, int $storeId): void
    {
        $this->files()->deleteForStore($fileNamePattern, $storeId);
    }

    /**
     * Delete a store's feed files, and its directory once nothing else is in it (best effort).
     *
     * Used when a store view is deleted, and for directories whose store view no longer exists:
     * nothing rebuilds their files, so without this they stay forever.
     *
     * @param int $storeId
     * @return void
     */
    public function deleteStoreDirectory(int $storeId): void
    {
        $this->files()->deleteStoreDirectory($storeId);
    }

    /**
     * List the store view IDs that have a feed directory.
     *
     * Used to find directories left behind by store views that no longer exist: deleting a
     * store group or a website removes its store views through a database-level cascade, with
     * no event to act on.
     *
     * @return int[]
     */
    public function listStoreDirectories(): array
    {
        return $this->files()->listStoreDirectories();
    }

    /**
     * Where the feeds are kept: in remote storage while it is on, on local disk otherwise.
     *
     * @return FeedFilesInterface
     */
    private function files(): FeedFilesInterface
    {
        return $this->remoteFeedFiles->isEnabled() ? $this->remoteFeedFiles : $this->localFeedFiles;
    }
}
