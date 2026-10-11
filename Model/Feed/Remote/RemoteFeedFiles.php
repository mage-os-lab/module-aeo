<?php

declare(strict_types=1);

namespace MageOS\Aeo\Model\Feed\Remote;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\RemoteStorage\Model\Config as RemoteStorageConfig;
use MageOS\Aeo\Exception\FeedStorageUnavailableException;
use MageOS\Aeo\Model\Config;
use MageOS\Aeo\Model\Feed\FeedFile;
use MageOS\Aeo\Model\Feed\FeedFilesInterface;
use MageOS\Aeo\Model\Feed\FeedWriterInterface;
use MageOS\Aeo\Model\Feed\StorageLog;
use MageOS\Seo\Api\Rebuild\ProblemLogInterface;
use Psr\Log\LoggerInterface;

/**
 * Feed files in Magento's remote storage — an S3 bucket — while it is on, so every web server
 * serves the files the host running the rebuilds writes, with no mount to share.
 *
 * The files are kept at the storage directory setting taken from the remote storage's root
 * (RemoteStoragePath): `mageos_aeo/store_<id>/` by default. They are written through Magento's own
 * remote filesystem, as core's sitemap is, which keeps its record of the bucket's contents current;
 * they are read for serving with a client that streams them (S3FeedReader), as Magento's driver
 * would read a large llms.jsonl whole into memory.
 *
 * The rules that make a local directory safe — no links followed, owner-only modes, `var/` or a
 * root declared in env.php — have nothing to guard in a bucket, which is deployment configuration
 * an administrator cannot change. Clean-up still removes only the files feed storage writes.
 */
class RemoteFeedFiles implements FeedFilesInterface
{
    /**
     * Magento_AwsS3's driver code, which S3FeedReader can read from.
     */
    private const S3_DRIVER = 'aws-s3';

    /**
     * @param Filesystem $filesystem Magento's full remote filesystem (di.xml)
     * @param RemoteStorageConfig $remoteStorageConfig
     * @param Config $aeoConfig
     * @param RemoteStoragePath $remoteStoragePath
     * @param S3FeedReader $s3FeedReader
     * @param DriverFeedReader $driverFeedReader
     * @param StorageLog $storageLog
     * @param LoggerInterface $logger
     * @param ProblemLogInterface $problemLog Injected as a proxy: storefront reads never need it
     */
    public function __construct(
        private readonly Filesystem          $filesystem,
        private readonly RemoteStorageConfig $remoteStorageConfig,
        private readonly Config              $aeoConfig,
        private readonly RemoteStoragePath   $remoteStoragePath,
        private readonly S3FeedReader        $s3FeedReader,
        private readonly DriverFeedReader    $driverFeedReader,
        private readonly StorageLog          $storageLog,
        private readonly LoggerInterface     $logger,
        private readonly ProblemLogInterface $problemLog
    ) {
    }

    /**
     * Whether Magento's remote storage is on, so the feeds are kept in it.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->remoteStorageConfig->isEnabled();
    }

    /**
     * @inheritDoc
     */
    public function write(string $fileName, int $storeId, string $content): void
    {
        $writer = $this->openForWrite($storeId);

        try {
            $writer->write($content);
            $writer->commit($fileName);
        } catch (\Exception $e) {
            $writer->discard();
            throw $e;
        }
    }

    /**
     * @inheritDoc
     */
    public function openForWrite(int $storeId): FeedWriterInterface
    {
        return new RemoteFeedFileWriter(
            $this->directory(),
            $this->storePath($storeId),
            // Hidden and ".tmp"-suffixed: no served file name or cleanup pattern can match it.
            '.' . bin2hex(random_bytes(6)) . '.tmp'
        );
    }

    /**
     * @inheritDoc
     */
    public function open(string $fileName, int $storeId): ?FeedFile
    {
        $reader = $this->remoteStorageConfig->getDriver() === self::S3_DRIVER
            ? $this->s3FeedReader
            : $this->driverFeedReader;

        try {
            return $reader->open($this->pathOf($fileName, $storeId));
        } catch (FeedStorageUnavailableException $e) {
            $this->storageLog->error(
                'MageOS_Aeo: the feeds cannot be read from remote storage: ' . $e->getMessage(),
                ['path' => $this->storePath($storeId)]
            );
            throw $e;
        }
    }

    /**
     * @inheritDoc
     */
    public function read(string $fileName, int $storeId): ?string
    {
        return $this->open($fileName, $storeId)?->contents();
    }

    /**
     * Whether a store's feed file is in remote storage.
     *
     * @param string $fileName
     * @param int $storeId
     * @throws FileSystemException
     * @return bool
     */
    public function exists(string $fileName, int $storeId): bool
    {
        return $this->directory()->isFile($this->pathOf($fileName, $storeId));
    }

    /**
     * A store's feed file's path from the remote storage's root.
     *
     * @param string $fileName
     * @param int $storeId
     * @return string
     */
    public function pathOf(string $fileName, int $storeId): string
    {
        return $this->storePath($storeId) . '/' . $fileName;
    }

    /**
     * @inheritDoc
     */
    public function deleteForStore(string $fileNamePattern, int $storeId): void
    {
        try {
            $path = $this->storePath($storeId);
            foreach ($this->names($path) as $name) {
                if (fnmatch($fileNamePattern, $name)) {
                    $this->directory()->delete($path . '/' . $name);
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning(
                'MageOS_Aeo: could not delete feed files from remote storage: ' . $e->getMessage(),
                ['store_id' => $storeId, 'pattern' => $fileNamePattern]
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function deleteStoreDirectory(int $storeId): void
    {
        try {
            $path = $this->storePath($storeId);
            $kept = [];
            foreach ($this->names($path) as $name) {
                if ($this->isOwned($name)) {
                    $this->directory()->delete($path . '/' . $name);
                } else {
                    $kept[] = $name;
                }
            }

            if ($kept === []) {
                $this->directory()->delete($path);
                return;
            }
            $this->logger->notice(
                'MageOS_Aeo: a feed directory in remote storage was kept, as it holds files feed storage did'
                . ' not write.',
                ['directory' => $path]
            );
        } catch (\Exception $e) {
            $this->logger->warning(
                'MageOS_Aeo: could not delete a feed directory from remote storage: ' . $e->getMessage(),
                ['store_id' => $storeId]
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function listStoreDirectories(): array
    {
        try {
            $storeIds = [];
            foreach ($this->names($this->root()) as $name) {
                if (preg_match('/^store_(\d+)$/', $name, $matches) === 1) {
                    $storeIds[] = (int) $matches[1];
                }
            }
            sort($storeIds);

            return $storeIds;
        } catch (\Exception) {
            return [];
        }
    }

    /**
     * The feeds' directory from the remote storage's root.
     *
     * The configured storage directory taken from the root, or mageos_aeo/ when none is configured.
     * A value that cannot be a path in remote storage — saved before remote storage was switched on,
     * or written around the admin's check — falls back to mageos_aeo/, and a rebuild that falls back
     * is shown to the admin as incomplete until the setting is fixed.
     *
     * @return string
     */
    private function root(): string
    {
        $configured = $this->aeoConfig->getFeedStorageDir();
        $path       = $this->remoteStoragePath->pathFor($configured);
        if ($path !== null) {
            return $path;
        }

        $this->storageLog->error(
            'MageOS_Aeo: the configured feed storage directory cannot be a path in remote storage and was'
            . ' ignored; falling back to ' . RemoteStoragePath::DEFAULT . '/.',
            ['storage_dir' => $configured]
        );
        $this->problemLog->degradedWhileRebuilding(
            __(
                'The configured storage directory cannot be a path in remote storage, so the files are kept'
                . ' in %1 instead.',
                RemoteStoragePath::DEFAULT . '/'
            )
        );

        return RemoteStoragePath::DEFAULT;
    }

    /**
     * A store view's directory from the remote storage's root.
     *
     * @param int $storeId
     * @return string
     */
    private function storePath(int $storeId): string
    {
        return $this->root() . '/store_' . $storeId;
    }

    /**
     * The names of what a directory in remote storage holds; none when it is not there.
     *
     * @param string $path
     * @throws FileSystemException
     * @return string[]
     */
    private function names(string $path): array
    {
        $directory = $this->directory();
        if (!$directory->isDirectory($path)) {
            return [];
        }

        $names = [];
        foreach ($directory->read($path) as $entry) {
            $slash   = strrpos($entry, '/');
            $names[] = $slash === false ? $entry : substr($entry, $slash + 1);
        }

        return $names;
    }

    /**
     * Whether feed storage wrote a file of this name.
     *
     * @param string $name
     * @return bool
     */
    private function isOwned(string $name): bool
    {
        foreach (self::OWNED_FILES as $pattern) {
            if (fnmatch($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The remote storage's root, through Magento's full remote filesystem.
     *
     * @throws FileSystemException
     * @return WriteInterface
     */
    private function directory(): WriteInterface
    {
        return $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
    }
}
