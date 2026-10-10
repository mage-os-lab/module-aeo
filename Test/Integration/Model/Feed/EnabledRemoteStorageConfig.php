<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Integration\Model\Feed;

use Magento\RemoteStorage\Model\Config;

/**
 * Magento's remote-storage configuration reporting remote storage on, over a driver that is not
 * S3 — given to the remote feed backend alone, so the rest of the installation keeps local disk.
 */
class EnabledRemoteStorageConfig extends Config
{
    /**
     * @return bool
     */
    public function isEnabled(): bool
    {
        return true;
    }

    /**
     * @return string
     */
    public function getDriver(): string
    {
        return 'file';
    }
}
