<?php

declare(strict_types=1);

namespace MageOS\Aeo\Model\Feed;

// phpcs:disable Magento2.Functions.DiscouragedFunction -- reads the handle FeedStorage opened and checked

/**
 * A stored feed file, opened for reading.
 *
 * Holds the handle of the very file FeedStorage checked, so whatever happens to the path afterwards
 * — a rebuild renaming a new file over it, a link put in its place — this is the file that is read.
 * The size is the opened file's.
 */
class FeedFile
{
    /**
     * @var resource|null
     */
    private $handle;

    /**
     * @param resource $handle
     * @param int $size
     */
    public function __construct(
        $handle,
        private readonly int $size
    ) {
        $this->handle = $handle;
    }

    /**
     * Size in bytes.
     *
     * @return int
     */
    public function size(): int
    {
        return $this->size;
    }

    /**
     * The whole file as a string, closing it. For files small enough to hold in memory.
     *
     * @return string
     */
    public function contents(): string
    {
        try {
            $contents = $this->handle === null ? false : stream_get_contents($this->handle);
        } finally {
            $this->close();
        }

        return $contents === false ? '' : $contents;
    }

    /**
     * Send the file to the output a piece at a time, then close it.
     *
     * @param int $pieceSize Bytes read and sent at a time
     * @return void
     */
    public function output(int $pieceSize): void
    {
        try {
            while ($this->handle !== null && !feof($this->handle)) {
                $piece = fread($this->handle, max(1, $pieceSize));
                if ($piece === false) {
                    break;
                }
                // phpcs:ignore Magento2.Security.LanguageConstruct.DirectOutput -- the response body
                echo $piece;
            }
        } finally {
            $this->close();
        }
    }

    /**
     * Close the file; reading it afterwards gives nothing.
     *
     * @return void
     */
    public function close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    /**
     * Close the file if nothing else did.
     */
    public function __destruct()
    {
        $this->close();
    }
}
