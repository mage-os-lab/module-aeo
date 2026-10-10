<?php

declare(strict_types=1);

namespace MageOS\Aeo\Model\Feed;

use Magento\Framework\Exception\FileSystemException;

/**
 * Builds one feed file a piece at a time; the file is served only once it is committed.
 *
 * Returned by FeedStorage::openForWrite(), for the local backend (FeedFileWriter) and the remote one
 * (Remote\RemoteFeedFileWriter) alike.
 */
interface FeedWriterInterface
{
    /**
     * Append content to the file being built.
     *
     * @param string $content
     * @throws FileSystemException
     * @return void
     */
    public function write(string $content): void;

    /**
     * Put the finished file in place under the given name, replacing any existing one.
     *
     * @param string $fileName
     * @throws FileSystemException
     * @return void
     */
    public function commit(string $fileName): void;

    /**
     * Throw the partial file away; the served file stays as it is.
     *
     * @return void
     */
    public function discard(): void;
}
