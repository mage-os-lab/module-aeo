<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Unit\Model\Feed\Remote;

// phpcs:disable Magento2.Functions.DiscouragedFunction -- builds the in-memory files the copier reads

use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;
use MageOS\Aeo\Model\Feed\FeedFile;
use MageOS\Aeo\Model\Feed\FeedWriterInterface;
use MageOS\Aeo\Model\Feed\LocalFeedFiles;
use MageOS\Aeo\Model\Feed\Remote\FeedCopier;
use MageOS\Aeo\Model\Feed\Remote\RemoteFeedFiles;
use PHPUnit\Framework\TestCase;

/**
 * Decision 4: the feeds on disk are copied into remote storage — the documents only, the ones it
 * does not have yet, a piece at a time.
 */
class FeedCopierTest extends TestCase
{
    /**
     * What each remote writer was given, by the store view it was opened for.
     *
     * @var array<int,string[]>
     */
    private array $written = [];

    /**
     * The files committed to remote storage, as "store/name".
     *
     * @var string[]
     */
    private array $committed = [];

    /**
     * The handles of the local files opened.
     *
     * @var resource[]
     */
    private array $handles = [];

    /**
     * Each document is checked on its own: one remote storage has does not stop the rest of the store
     * view's being copied.
     *
     * @return void
     */
    public function testTheDocumentsRemoteStorageLacksAreCopied(): void
    {
        $local = [
            1 => ['llms.txt' => 'txt one', 'llms.jsonl' => 'jsonl one'],
            2 => ['llms-full.txt' => 'full two'],
        ];
        $remote = ['1/llms.txt'];

        $copied = iterator_to_array($this->copier($local, $remote)->copy(), false);

        $this->assertSame(['remote/1/llms.jsonl', 'remote/2/llms-full.txt'], $copied);
        $this->assertSame(['1/llms.jsonl', '2/llms-full.txt'], $this->committed);
        $this->assertSame('jsonl one', implode('', $this->written[1]));
    }

    /**
     * With remote storage off, there is nowhere to copy to.
     *
     * @return void
     */
    public function testNothingIsCopiedWhileRemoteStorageIsOff(): void
    {
        $this->assertSame([], iterator_to_array($this->copier([1 => ['llms.txt' => 'x']], [], false)->copy(), false));
    }

    /**
     * A copy that fails is given up and stops the run, saying which file, and the local file is
     * closed.
     *
     * @return void
     */
    public function testAFailedCopyStopsTheRunSayingWhichFile(): void
    {
        $copier = $this->copier([1 => ['llms.txt' => 'x']], [], true, true);

        try {
            iterator_to_array($copier->copy(), false);
            $this->fail('The copy did not fail.');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('Could not copy llms.txt of store view 1', $e->getMessage());
        }

        $this->assertCount(1, $this->handles);
        $this->assertFalse(\is_resource($this->handles[0]), 'The local file is closed.');
    }

    /**
     * A copier over local files and remote storage holding the given files.
     *
     * @param array<int,array<string,string>> $local Store ID => name => content
     * @param string[] $remote "store/name" of the files remote storage has
     * @param bool $enabled Whether remote storage is on
     * @param bool $failing Whether writing to remote storage fails
     * @return FeedCopier
     */
    private function copier(array $local, array $remote, bool $enabled = true, bool $failing = false): FeedCopier
    {
        $localFiles = $this->createStub(LocalFeedFiles::class);
        $localFiles->method('listStoreDirectories')->willReturn(array_keys($local));
        $localFiles->method('open')->willReturnCallback(
            function (string $name, int $storeId) use ($local): ?FeedFile {
                if (!isset($local[$storeId][$name])) {
                    return null;
                }
                $handle = fopen('php://temp', 'r+');
                if ($handle === false) {
                    $this->fail('No in-memory file.');
                }
                fwrite($handle, $local[$storeId][$name]);
                rewind($handle);
                $this->handles[] = $handle;

                return new FeedFile($handle, \strlen($local[$storeId][$name]));
            }
        );

        $remoteFiles = $this->createStub(RemoteFeedFiles::class);
        $remoteFiles->method('isEnabled')->willReturn($enabled);
        $remoteFiles->method('exists')->willReturnCallback(
            static fn (string $name, int $storeId): bool => \in_array($storeId . '/' . $name, $remote, true)
        );
        $remoteFiles->method('pathOf')->willReturnCallback(
            static fn (string $name, int $storeId): string => 'remote/' . $storeId . '/' . $name
        );
        $remoteFiles->method('openForWrite')->willReturnCallback(
            fn (int $storeId): FeedWriterInterface => $this->writer($storeId, $failing)
        );

        return new FeedCopier($localFiles, $remoteFiles);
    }

    /**
     * A remote writer that records what it is given.
     *
     * @param int $storeId
     * @param bool $failing
     * @return FeedWriterInterface
     */
    private function writer(int $storeId, bool $failing): FeedWriterInterface
    {
        $writer = $this->createMock(FeedWriterInterface::class);
        $writer->method('write')->willReturnCallback(function (string $content) use ($storeId, $failing): void {
            if ($failing) {
                throw new FileSystemException(new Phrase('lost connection'));
            }
            $this->written[$storeId][] = $content;
        });
        $writer->method('commit')->willReturnCallback(function (string $name) use ($storeId): void {
            $this->committed[] = $storeId . '/' . $name;
        });
        $writer->expects($failing ? $this->once() : $this->never())->method('discard');

        return $writer;
    }
}
