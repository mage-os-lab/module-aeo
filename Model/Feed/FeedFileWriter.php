<?php

declare(strict_types=1);

namespace MageOS\Aeo\Model\Feed;

use Magento\Framework\Exception\FileSystemException;

/**
 * Writes one feed file incrementally, and only puts it in place when it is complete.
 *
 * Content goes to a temporary file in the store's feed directory; commit() renames that over
 * the served name, so readers see the previous file or the complete new one, never a partially
 * written document, and a build that fails leaves the served file untouched. The served name is
 * given at commit time.
 *
 * The temporary file is created exclusively, so it can never be an existing file or a link, and
 * the store directory is checked again before the rename and before a discard: both go through its
 * path, and a link put in its place would take them outside storage (issue #2).
 */
class FeedFileWriter
{
    /**
     * @var resource|null
     */
    private $file = null;

    /**
     * @param LinkSafeFilesystem $filesystem
     * @param string $directory The store's feed directory, absolute
     * @param string $temporaryName Name of the temporary file in that directory
     * @param int $fileMode Mode the file gets
     */
    public function __construct(
        private readonly LinkSafeFilesystem $filesystem,
        private readonly string             $directory,
        private readonly string             $temporaryName,
        private readonly int                $fileMode
    ) {
    }

    /**
     * Append content to the file being built.
     *
     * @param string $content
     * @throws FileSystemException
     * @return void
     */
    public function write(string $content): void
    {
        if ($this->file === null) {
            $this->filesystem->assertDirectory($this->directory);
            $this->file = $this->filesystem->createFile($this->temporaryPath(), $this->fileMode);
        }

        $this->filesystem->write($this->file, $content);
    }

    /**
     * Put the finished file in place under the given name, replacing any existing one.
     *
     * A link at that name is replaced, never written through.
     *
     * @param string $fileName
     * @throws FileSystemException
     * @return void
     */
    public function commit(string $fileName): void
    {
        $this->write('');
        $file       = $this->file;
        $this->file = null;
        if ($file !== null) {
            $this->filesystem->close($file);
        }

        $this->filesystem->assertDirectory($this->directory);
        $this->filesystem->rename($this->temporaryPath(), $this->directory . '/' . $fileName);
    }

    /**
     * Throw the partial file away; the served file stays as it is.
     *
     * @return void
     */
    public function discard(): void
    {
        $file       = $this->file;
        $this->file = null;

        if ($file !== null) {
            try {
                $this->filesystem->close($file);
            } catch (\Exception) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch -- removed below
            }
        }

        try {
            // Refused when the store directory is no longer a real one: the file is not this
            // writer's to delete through a link.
            $this->filesystem->removeFrom($this->directory, $this->temporaryName);
        } catch (\Exception) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch -- best-effort cleanup
        }
    }

    /**
     * The temporary file's path.
     *
     * @return string
     */
    private function temporaryPath(): string
    {
        return $this->directory . '/' . $this->temporaryName;
    }
}
