<?php

declare(strict_types=1);

namespace MageOS\Aeo\Model\Feed;

use Magento\Framework\Exception\FileSystemException;
use MageOS\Aeo\Exception\FeedStorageUnavailableException;

/**
 * Where the feed files are kept: local disk (LocalFeedFiles) or Magento's remote storage
 * (Remote\RemoteFeedFiles). FeedStorage hands each operation to the one in use.
 *
 * One directory per store view, `store_<id>/`, under the storage directory.
 */
interface FeedFilesInterface
{
    /**
     * The files feed storage writes: the documents, their temporary files while being written, and
     * the hreflang sitemap files earlier versions wrote here. Clean-up removes nothing else.
     */
    public const OWNED_FILES = ['llms.txt', 'llms-full.txt', 'llms.jsonl', '.*.tmp', 'hreflang-sitemap*.xml'];

    /**
     * Persist a feed file for a store, replacing any existing one without a moment it is partial.
     *
     * @param string $fileName
     * @param int $storeId
     * @param string $content
     * @throws FileSystemException
     * @throws FeedStorageUnavailableException
     * @return void
     */
    public function write(string $fileName, int $storeId, string $content): void;

    /**
     * Open a writer that builds one feed file for a store a piece at a time.
     *
     * @param int $storeId
     * @throws FileSystemException
     * @throws FeedStorageUnavailableException
     * @return FeedWriterInterface
     */
    public function openForWrite(int $storeId): FeedWriterInterface;

    /**
     * Open a feed file for a store, or null when there is none to serve.
     *
     * @param string $fileName
     * @param int $storeId
     * @throws FeedStorageUnavailableException When the storage cannot be read from here
     * @return FeedFile|null
     */
    public function open(string $fileName, int $storeId): ?FeedFile;

    /**
     * Read a feed file for a store whole, or null when it has not been generated.
     *
     * @param string $fileName
     * @param int $storeId
     * @throws FeedStorageUnavailableException When the storage cannot be read from here
     * @return string|null
     */
    public function read(string $fileName, int $storeId): ?string;

    /**
     * Delete one store's feed files matching a glob pattern (best effort).
     *
     * @param string $fileNamePattern
     * @param int $storeId
     * @return void
     */
    public function deleteForStore(string $fileNamePattern, int $storeId): void;

    /**
     * Delete a store's feed files, and its directory once nothing else is in it (best effort).
     *
     * @param int $storeId
     * @return void
     */
    public function deleteStoreDirectory(int $storeId): void;

    /**
     * The store view IDs that have a feed directory.
     *
     * @return int[]
     */
    public function listStoreDirectories(): array;
}
