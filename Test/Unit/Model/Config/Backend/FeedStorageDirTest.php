<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Unit\Model\Config\Backend;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\RemoteStorage\Model\Config as RemoteStorageConfig;
use MageOS\Aeo\Model\Config\Backend\FeedStorageDir;
use MageOS\Aeo\Model\Feed\Remote\RemoteStoragePath;
use MageOS\Aeo\Model\Feed\StorageDirectory;
use PHPUnit\Framework\TestCase;

/**
 * The storage directory is checked as a path in remote storage while it is on, and as a directory
 * on disk otherwise.
 */
class FeedStorageDirTest extends TestCase
{
    /**
     * @return void
     */
    public function testWithRemoteStorageOnTheValueIsCheckedAsAPathInIt(): void
    {
        $storageDirectory = $this->createMock(StorageDirectory::class);
        $storageDirectory->expects($this->never())->method('validate');

        $this->value('/mnt/shared/feeds', true, $storageDirectory)->beforeSave();

        $this->expectException(LocalizedException::class);
        $this->value('/mnt/../etc', true, $storageDirectory)->beforeSave();
    }

    /**
     * @return void
     */
    public function testWithRemoteStorageOffTheValueIsCheckedAsADirectoryOnDisk(): void
    {
        $storageDirectory = $this->createMock(StorageDirectory::class);
        $storageDirectory->expects($this->once())->method('validate')->with('/mnt/shared/feeds');

        $this->value('/mnt/shared/feeds', false, $storageDirectory)->beforeSave();
    }

    /**
     * A field saved with no value at all is checked as an empty one: the default directory.
     *
     * @return void
     */
    public function testNoValueIsCheckedAsAnEmptyOne(): void
    {
        $storageDirectory = $this->createMock(StorageDirectory::class);
        $storageDirectory->expects($this->once())->method('validate')->with('');

        $this->value(null, true, $storageDirectory)->beforeSave();
        $this->value(null, false, $storageDirectory)->beforeSave();
    }

    /**
     * The field's value, about to be saved.
     *
     * @param string|null $value
     * @param bool $remoteStorage Whether remote storage is on
     * @param StorageDirectory $storageDirectory
     * @return FeedStorageDir
     */
    private function value(?string $value, bool $remoteStorage, StorageDirectory $storageDirectory): FeedStorageDir
    {
        $remoteStorageConfig = $this->createStub(RemoteStorageConfig::class);
        $remoteStorageConfig->method('isEnabled')->willReturn($remoteStorage);
        // The parent's beforeSave() dispatches an event.
        $context = $this->createStub(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createStub(EventManager::class));

        $field = new FeedStorageDir(
            $storageDirectory,
            $remoteStorageConfig,
            new RemoteStoragePath(),
            $context,
            $this->createStub(Registry::class),
            $this->createStub(ScopeConfigInterface::class),
            $this->createStub(TypeListInterface::class)
        );
        $field->setValue($value);

        return $field;
    }
}
