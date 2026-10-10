<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Unit\Model\Feed\Remote;

use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Filesystem\DriverInterface;
use Magento\Framework\Phrase;

/**
 * The local file driver standing in for remote storage, made to fail at a chosen operation.
 *
 * Counts every operation that changes a file — open, write, close, rename, delete — from the
 * moment failAt() is called, and throws in place of the one with the given number, as a lost
 * connection would. It also counts the files left open: Magento's S3 driver uploads a file only
 * when it is closed.
 */
class FailingFileDriver extends File
{
    /**
     * The number of the operation to fail; null for none.
     *
     * @var int|null
     */
    private ?int $failAt = null;

    /**
     * Operations counted since failAt() was called.
     *
     * @var int
     */
    private int $operations = 0;

    /**
     * Files opened and not yet closed.
     *
     * @var int
     */
    private int $openFiles = 0;

    /**
     * The exception to fail with; null for the framework's own.
     *
     * @var \Exception|null
     */
    private ?\Exception $failWith = null;

    /**
     * Fail the operation with the given number, counting from 1 from now on.
     *
     * @param int|null $operation null to fail none
     * @param \Exception|null $failWith A storage library's own exception; null for a FileSystemException
     * @return void
     */
    public function failAt(?int $operation, ?\Exception $failWith = null): void
    {
        $this->failAt     = $operation;
        $this->failWith   = $failWith;
        $this->operations = 0;
    }

    /**
     * How many files are open.
     *
     * @return int
     */
    public function openFiles(): int
    {
        return $this->openFiles;
    }

    /**
     * @inheritDoc
     */
    public function fileOpen($path, $mode)
    {
        $this->count('open ' . $path);
        $resource = parent::fileOpen($path, $mode);
        $this->openFiles++;

        return $resource;
    }

    /**
     * @inheritDoc
     */
    public function fileWrite($resource, $data)
    {
        $this->count('write');

        return parent::fileWrite($resource, $data);
    }

    /**
     * @inheritDoc
     */
    public function fileClose($resource)
    {
        $this->count('close');
        $closed = parent::fileClose($resource);
        $this->openFiles--;

        return $closed;
    }

    /**
     * @inheritDoc
     */
    public function rename($oldPath, $newPath, ?DriverInterface $targetDriver = null)
    {
        $this->count('rename ' . $oldPath);

        return parent::rename($oldPath, $newPath, $targetDriver);
    }

    /**
     * @inheritDoc
     */
    public function deleteFile($path)
    {
        $this->count('delete ' . $path);

        return parent::deleteFile($path);
    }

    /**
     * Count an operation, and fail it if it is the chosen one.
     *
     * @param string $operation
     * @throws \Exception
     * @return void
     */
    private function count(string $operation): void
    {
        $this->operations++;
        if ($this->failAt === $this->operations) {
            throw $this->failWith ?? new FileSystemException(new Phrase('Injected failure at operation %1: %2', [
                $this->operations,
                $operation,
            ]));
        }
    }
}
