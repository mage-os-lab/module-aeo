<?php

declare(strict_types=1);

namespace MageOS\Aeo\Model\Feed;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Framework\Phrase;
use MageOS\Aeo\Exception\FeedStorageUnavailableException;
use MageOS\Aeo\Model\Config;
use MageOS\Seo\Model\Rebuild\ProblemLog;
use Psr\Log\LoggerInterface;

/**
 * File storage for pre-generated SEO feeds (llms.txt, llms-full.txt, llms.jsonl), one
 * directory per store view.
 *
 * Defaults to var/mageos_aeo/store_<id>/; a custom absolute directory can be
 * configured (mageos_aeo/feeds/storage_dir) so multi-server deployments
 * can point web servers and the cron/consumer host at a shared mount — var/ is
 * host-local on scaled setups.
 *
 * No operation follows a symbolic link (issue #2). Each one resolves the storage directory once and
 * works from that path; the storage directory, the store directories and the feed files must be the
 * real thing, not links, and a link where one should be is refused, or removed itself, never
 * followed (LinkSafeFilesystem). A directory every user can write to is refused as well.
 *
 * A configured directory is judged for what each operation does (issue #7): serving a feed needs
 * it readable, rebuilding needs it writable, so a web host that only reads the shared directory
 * serves what the cron host writes. One this host cannot use at all — a mount that is not there —
 * is reported (FeedStorageUnavailableException), not swapped for var/, and logged at most once
 * every LOG_INTERVAL seconds, however many requests meet it.
 *
 * Cleanup removes only the files feed storage writes (OWNED_FILES), and a store directory only
 * once nothing else is in it (issue #6): a directory the feeds share keeps everything else.
 */
class FeedStorage
{
    private const DEFAULT_BASE_DIR = 'mageos_aeo';

    /**
     * The files feed storage writes: the documents, their temporary files while being written, and
     * the hreflang sitemap files earlier versions wrote here.
     */
    private const OWNED_FILES = ['llms.txt', 'llms-full.txt', 'llms.jsonl', '.*.tmp', 'hreflang-sitemap*.xml'];

    /**
     * Seconds between two log entries about the same storage problem.
     */
    private const LOG_INTERVAL = 300;

    /**
     * Feed files: owner read/write, group read, nothing for others. Replacing a file needs
     * only directory permissions, so writers do not need group write on the file.
     */
    private const FILE_MODE = 0o640;

    /**
     * Feed directories: owner full access, group enter/list, nothing for others.
     */
    private const DIRECTORY_MODE = 0o750;

    /**
     * @param DirectoryList $directoryList
     * @param FileDriver $fileDriver
     * @param LinkSafeFilesystem $linkSafeFilesystem
     * @param Config $aeoConfig
     * @param StorageDirectory $storageDirectory
     * @param LoggerInterface $logger
     * @param ProblemLog $problemLog Injected as a proxy: storefront reads never need it
     * @param CacheInterface $cache Remembers which storage problems were logged lately
     */
    public function __construct(
        private readonly DirectoryList      $directoryList,
        private readonly FileDriver         $fileDriver,
        private readonly LinkSafeFilesystem $linkSafeFilesystem,
        private readonly Config             $aeoConfig,
        private readonly StorageDirectory   $storageDirectory,
        private readonly LoggerInterface    $logger,
        private readonly ProblemLog         $problemLog,
        private readonly CacheInterface     $cache
    ) {
    }

    /**
     * Persist a feed file for a store, replacing any existing file atomically.
     *
     * The content is written to a temporary file in the same directory and renamed over the
     * target, so a concurrent request reads either the previous file or the complete new one,
     * never a partially written file.
     *
     * @param string $fileName
     * @param int $storeId
     * @param string $content
     * @throws FileSystemException
     * @return void
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
     * Open a writer that builds one feed file for a store incrementally.
     *
     * The document is streamed out (llms.jsonl, a line per product) instead of held in memory;
     * the served file name is given to commit(), and the file appears only then.
     *
     * The storage directory and the store directory are created when missing, and given the feed
     * directory mode — re-applied on every write, so directories made before this policy, or by
     * another tool, converge on it. A configured storage directory is left as it is. Changing the
     * mode of a directory another user owns fails and is ignored, unless it leaves the directory
     * writable by every user, which is refused.
     *
     * @param int $storeId
     * @throws FileSystemException When a storage directory is a link, or not safe to write to
     * @throws FeedStorageUnavailableException When the configured directory cannot be written from here
     * @return FeedFileWriter
     */
    public function openForWrite(int $storeId): FeedFileWriter
    {
        [$root, $isDefault] = $this->root(StorageDirectory::WRITE);
        if ($isDefault) {
            $this->linkSafeFilesystem->ensureDirectory($root, self::DIRECTORY_MODE);
        } else {
            $this->linkSafeFilesystem->assertDirectory($root);
        }
        $directory = $this->storeDirectory($root, $storeId);
        $this->linkSafeFilesystem->ensureDirectory($directory, self::DIRECTORY_MODE);

        return new FeedFileWriter(
            $this->linkSafeFilesystem,
            $directory,
            // Hidden and ".tmp"-suffixed: no served file name or cleanup pattern can match it.
            '.' . bin2hex(random_bytes(6)) . '.tmp',
            self::FILE_MODE
        );
    }

    /**
     * Open a feed file for a store, or null when there is none to serve.
     *
     * Null as well for anything but a regular file in safe directories — a link, or a directory
     * every user can write to — which the rebuild a missing file queues then replaces or reports.
     *
     * @param string $fileName
     * @param int $storeId
     * @throws FeedStorageUnavailableException When the configured directory cannot be read from
     *                                         here: no file there is a fault a rebuild cannot fix
     * @return FeedFile|null
     */
    public function open(string $fileName, int $storeId): ?FeedFile
    {
        [$root] = $this->root(StorageDirectory::READ);

        try {
            $directory = $this->storeDirectory($root, $storeId);
            if (!$this->linkSafeFilesystem->isSafeDirectory($root)
                || !$this->linkSafeFilesystem->isSafeDirectory($directory)
            ) {
                return null;
            }

            return $this->linkSafeFilesystem->openFile($directory . '/' . $fileName);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Read a feed file for a store, or null when it has not been generated.
     *
     * The whole file as a string. The controllers use open() instead, through FeedDelivery, which
     * streams a large file rather than reading it into memory.
     *
     * @param string $fileName
     * @param int $storeId
     * @throws FeedStorageUnavailableException When the configured directory cannot be read from here
     * @return string|null
     */
    public function read(string $fileName, int $storeId): ?string
    {
        return $this->open($fileName, $storeId)?->contents();
    }

    /**
     * Delete matching feed files for one store.
     *
     * Files and links are removed themselves; a store directory that is a link is left alone, as
     * nothing of this module's is behind it, and the next write refuses it.
     *
     * @param string $fileNamePattern Glob pattern, e.g. "llms*.txt"
     * @param int $storeId
     * @return void
     */
    public function deleteForStore(string $fileNamePattern, int $storeId): void
    {
        try {
            [$root]    = $this->root(StorageDirectory::WRITE);
            $directory = $this->storeDirectory($root, $storeId);
            if (!$this->linkSafeFilesystem->isSafeDirectory($root)
                || !$this->linkSafeFilesystem->isSafeDirectory($directory)
            ) {
                return;
            }

            foreach ($this->linkSafeFilesystem->names($directory) as $name) {
                if (fnmatch($fileNamePattern, $name)) {
                    $this->linkSafeFilesystem->removeFrom($directory, $name);
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning(
                'MageOS_Aeo: could not delete feed files: ' . $e->getMessage(),
                ['store_id' => $storeId, 'pattern' => $fileNamePattern]
            );
        }
    }

    /**
     * Delete a store's feed files, and its directory once nothing else is in it (best effort).
     *
     * Used when a store view is deleted, and for directories whose store view no longer exists:
     * nothing rebuilds their files, so without this they stay on disk forever. Only the files feed
     * storage writes are removed; a directory still holding anything else stays, with a notice in
     * the log (issue #6). A store directory that is a link is removed itself, never what it points
     * to.
     *
     * @param int $storeId
     * @return void
     */
    public function deleteStoreDirectory(int $storeId): void
    {
        try {
            [$root] = $this->root(StorageDirectory::WRITE);
            if (!$this->linkSafeFilesystem->isSafeDirectory($root)) {
                return;
            }

            $directory = $this->storeDirectory($root, $storeId);
            if (!$this->linkSafeFilesystem->removeOwned($directory, self::OWNED_FILES)) {
                $this->logger->notice(
                    'MageOS_Aeo: a feed directory was kept, as it holds files feed storage did not write.',
                    ['directory' => $directory]
                );
            }
        } catch (\Exception $e) {
            $this->logger->warning(
                'MageOS_Aeo: could not delete a feed directory: ' . $e->getMessage(),
                ['store_id' => $storeId]
            );
        }
    }

    /**
     * List the store view IDs that have a feed directory.
     *
     * Used to find directories left behind by store views that no longer exist: deleting a
     * store group or a website removes its store views through a database-level cascade, with
     * no event to act on. A link with a store directory's name is listed too, so the cleanup
     * removes it.
     *
     * The directory is read each time: Magento\Framework\Filesystem\Glob memoises its results for
     * the life of the process, and the queue consumer and the cron rebuild more than once per
     * process.
     *
     * @return int[]
     */
    public function listStoreDirectories(): array
    {
        try {
            [$root] = $this->root(StorageDirectory::WRITE);
            if (!$this->linkSafeFilesystem->isSafeDirectory($root)) {
                return [];
            }

            $storeIds = [];
            foreach ($this->linkSafeFilesystem->names($root) as $name) {
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
     * The storage directory every path of one operation starts from, resolved once.
     *
     * The configured directory, resolved, when the installation permits it; otherwise
     * mageos_aeo/ in the resolved var/. The admin field is validated on save, but a configuration
     * row can arrive another way — a data patch, a deployment tool, a direct database write — so
     * the value is checked again here. A location the rules refuse falls back to var/mageos_aeo
     * rather than failing: the feeds keep working, in the one place every installation can write.
     * A rebuild that falls back is shown to the admin as incomplete until the setting is fixed.
     *
     * A permitted directory this host cannot use for the purpose does not fall back: on a
     * multi-server install, var/ of this host is not where the others look.
     *
     * @param string $purpose StorageDirectory::READ or StorageDirectory::WRITE
     * @throws FileSystemException When var/ cannot be resolved
     * @throws FeedStorageUnavailableException When the configured directory cannot be used from here
     * @return array{0: string, 1: bool} The directory, and whether it is the default one
     */
    private function root(string $purpose): array
    {
        $configured = $this->aeoConfig->getFeedStorageDir();
        if ($configured !== '') {
            try {
                $located = $this->storageDirectory->locate($configured, $purpose);
            } catch (FeedStorageUnavailableException $e) {
                $this->logOccasionally(
                    'MageOS_Aeo: the feed storage directory cannot be used: ' . $e->getMessage(),
                    ['storage_dir' => $configured]
                );
                throw $e;
            }
            if ($located !== null) {
                return [$located, false];
            }

            $this->logOccasionally(
                'MageOS_Aeo: the configured feed storage directory is not permitted and was ignored;'
                . ' falling back to var/mageos_aeo.',
                ['storage_dir' => $configured]
            );
            // On a multi-server install the web servers may not see var/ of the host that rebuilds.
            $this->problemLog->degradedWhileRebuilding(
                __('The configured storage directory is not allowed, so the files are kept in var/mageos_aeo instead.')
            );
        }

        $var = $this->fileDriver->getRealPath($this->directoryList->getPath(DirectoryList::VAR_DIR));
        if (!\is_string($var) || $var === '') {
            throw new FileSystemException(new Phrase('The var/ directory could not be resolved.'));
        }

        return [rtrim($var, '/') . '/' . self::DEFAULT_BASE_DIR, true];
    }

    /**
     * Log a storage problem as an error, at most once every LOG_INTERVAL seconds per message.
     *
     * Every request for a feed meets a storage problem until someone fixes it; once a few minutes
     * says as much as once a request.
     *
     * @param string $message
     * @param mixed[] $context
     * @return void
     */
    private function logOccasionally(string $message, array $context): void
    {
        $key = 'mageos_aeo_storage_log_' . sha1($message . "\0" . json_encode($context));
        try {
            if ($this->cache->load($key) !== false) {
                return;
            }
            $this->cache->save('1', $key, [], self::LOG_INTERVAL);
        } catch (\Exception) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch -- log it anyway
        }

        $this->logger->error($message, $context);
    }

    /**
     * A store view's directory in the storage directory.
     *
     * @param string $root
     * @param int $storeId
     * @return string
     */
    private function storeDirectory(string $root, int $storeId): string
    {
        return $root . '/store_' . $storeId;
    }
}
