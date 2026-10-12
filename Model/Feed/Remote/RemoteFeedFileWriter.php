<?php

declare(strict_types=1);

namespace MageOS\Aeo\Model\Feed\Remote;

use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Filesystem\File\WriteInterface as FileWriteInterface;
use Magento\Framework\Phrase;
use MageOS\Aeo\Model\Feed\FeedWriterInterface;

/**
 * Builds one feed file in Magento's remote storage, and puts it in place only once it is complete.
 *
 * Content goes to a temporary object in the store's directory — Magento's S3 driver keeps it in a
 * local temporary file and uploads it when it is closed, so a large llms.jsonl is never held in
 * memory — and commit() moves it over the served name: a reader gets the previous object or the
 * complete new one, and a build that fails leaves the served object untouched.
 *
 * Magento's drivers report some failures with exceptions of their storage library rather than the
 * framework's; each is reported here as a FileSystemException, as on local disk.
 */
class RemoteFeedFileWriter implements FeedWriterInterface
{
    /**
     * @var FileWriteInterface|null
     */
    private ?FileWriteInterface $file = null;

    /**
     * @param WriteInterface $directory The remote storage's root
     * @param string $storePath The store's directory, from the root
     * @param string $temporaryName Name of the temporary object in that directory
     */
    public function __construct(
        private readonly WriteInterface $directory,
        private readonly string         $storePath,
        private readonly string         $temporaryName
    ) {
    }

    /**
     * @inheritDoc
     */
    public function write(string $content): void
    {
        $this->attempt(function () use ($content): void {
            $this->file ??= $this->directory->openFile($this->temporaryPath(), 'w');
            $this->file->write($content);
        });
    }

    /**
     * @inheritDoc
     */
    public function commit(string $fileName): void
    {
        $this->write('');
        $file       = $this->file;
        $this->file = null;

        $this->attempt(function () use ($file, $fileName): void {
            $file?->close();
            $this->directory->renameFile($this->temporaryPath(), $this->storePath . '/' . $fileName);
        });
    }

    /**
     * @inheritDoc
     */
    public function discard(): void
    {
        $file       = $this->file;
        $this->file = null;

        try {
            $file?->close();
        } catch (\Exception) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch -- removed below
        }

        try {
            $this->directory->delete($this->temporaryPath());
        } catch (\Exception) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch -- best-effort cleanup
        }
    }

    /**
     * Run a storage operation, reporting any failure as a FileSystemException.
     *
     * @param callable $operation
     * @throws FileSystemException
     * @return void
     */
    private function attempt(callable $operation): void
    {
        try {
            $operation();
        } catch (FileSystemException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new FileSystemException(
                new Phrase('Remote storage could not write a feed file: %1', [$e->getMessage()]),
                $e
            );
        }
    }

    /**
     * The temporary object's path.
     *
     * @return string
     */
    private function temporaryPath(): string
    {
        return $this->storePath . '/' . $this->temporaryName;
    }
}
