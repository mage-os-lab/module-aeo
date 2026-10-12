<?php

declare(strict_types=1);

namespace MageOS\Aeo\Model\Feed\Remote;

use MageOS\Aeo\Exception\FeedStorageUnavailableException;
use MageOS\Aeo\Model\Feed\FeedFile;

/**
 * Opens a feed file in Magento's remote storage for serving.
 *
 * S3FeedReader streams it; DriverFeedReader, for a remote driver other than S3, reads it whole
 * through Magento's driver.
 */
interface RemoteFeedReaderInterface
{
    /**
     * Open the file at a path from the remote storage's root, or null when there is none.
     *
     * @param string $path
     * @throws FeedStorageUnavailableException When remote storage cannot be read
     * @return FeedFile|null
     */
    public function open(string $path): ?FeedFile;
}
