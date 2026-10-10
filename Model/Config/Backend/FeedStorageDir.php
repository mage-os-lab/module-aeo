<?php

declare(strict_types=1);

namespace MageOS\Aeo\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Value;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\RemoteStorage\Model\Config as RemoteStorageConfig;
use MageOS\Aeo\Model\Feed\Remote\RemoteStoragePath;
use MageOS\Aeo\Model\Feed\StorageDirectory;

/**
 * Refuses a feed storage directory the feeds cannot be kept in, with the reason.
 *
 * While Magento's remote storage is on, the value is a path from its root, and is checked as one
 * (Model\Feed\Remote\RemoteStoragePath); otherwise it is a directory on disk the installation must
 * permit (Model\Feed\StorageDirectory).
 *
 * The check is repeated when the value is read (see Model\Feed\FeedStorage): a configuration row
 * can be written without passing through here — by a data patch, a deployment tool, or straight
 * into the database — and a directory this rejects must not be written to whichever way it arrived.
 */
class FeedStorageDir extends Value
{
    /**
     * @param StorageDirectory $storageDirectory
     * @param RemoteStorageConfig $remoteStorageConfig
     * @param RemoteStoragePath $remoteStoragePath
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param mixed[] $data
     */
    public function __construct(
        private readonly StorageDirectory $storageDirectory,
        private readonly RemoteStorageConfig $remoteStorageConfig,
        private readonly RemoteStoragePath $remoteStoragePath,
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct($context, $registry, $config, $cacheTypeList, $resource, $resourceCollection, $data);
    }

    /**
     * Validate the configured directory before it is stored.
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     * @return $this
     */
    public function beforeSave(): self
    {
        if ($this->remoteStorageConfig->isEnabled()) {
            $this->remoteStoragePath->validate((string) $this->getValue());
        } else {
            $this->storageDirectory->validate((string) $this->getValue());
        }

        return parent::beforeSave();
    }
}
