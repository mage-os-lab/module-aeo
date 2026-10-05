<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Unit\Model\Feed;

// phpcs:disable Magento2.Functions.DiscouragedFunction -- the test checks the disk directly

use Magento\Framework\Filesystem\Driver\File as FileDriver;
use MageOS\Aeo\Model\Feed\FeedFileWriter;
use MageOS\Aeo\Model\Feed\LinkSafeFilesystem;
use PHPUnit\Framework\TestCase;

/**
 * Building a feed file in a temporary file and putting it in place, on a real directory.
 */
class FeedFileWriterTest extends TestCase
{
    private const TEMPORARY = '.abc123def456.tmp';

    /**
     * The store's feed directory.
     *
     * @var string
     */
    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = (string) realpath((string) sys_get_temp_dir()) . '/mageos-aeo-writer-' . uniqid();
        mkdir($this->directory, 0o750);
    }

    protected function tearDown(): void
    {
        (new FileDriver())->deleteDirectory($this->directory);
    }

    public function testContentGoesToTheTemporaryFileAndIsNamedOnCommit(): void
    {
        file_put_contents($this->directory . '/llms.jsonl', 'served');
        $writer = $this->writer();

        $writer->write("first\n");
        $writer->write("second\n");
        $this->assertSame("first\nsecond\n", file_get_contents($this->directory . '/' . self::TEMPORARY));
        $this->assertSame('served', file_get_contents($this->directory . '/llms.jsonl'));

        $writer->commit('llms.jsonl');

        $this->assertSame("first\nsecond\n", file_get_contents($this->directory . '/llms.jsonl'));
        $this->assertSame(0o640, fileperms($this->directory . '/llms.jsonl') & 0o777);
        $this->assertFileDoesNotExist($this->directory . '/' . self::TEMPORARY);
    }

    public function testAFileWithNoContentIsStillCreated(): void
    {
        // An empty catalogue must still replace the served file, not leave the previous one.
        file_put_contents($this->directory . '/llms.jsonl', 'served');

        $this->writer()->commit('llms.jsonl');

        $this->assertSame('', file_get_contents($this->directory . '/llms.jsonl'));
    }

    public function testDiscardRemovesTheTemporaryFileAndLeavesTheServedFile(): void
    {
        file_put_contents($this->directory . '/llms.jsonl', 'served');
        $writer = $this->writer();
        $writer->write('half a document');

        $writer->discard();

        $this->assertSame(['.', '..', 'llms.jsonl'], scandir($this->directory));
        $this->assertSame('served', file_get_contents($this->directory . '/llms.jsonl'));
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testDiscardWithoutAnyContentDoesNotFail(): void
    {
        $this->writer()->discard();
    }

    public function testATemporaryFileThatIsAlreadyThereIsNotWrittenTo(): void
    {
        // Created exclusively: a file, or a link, planted at the temporary name is never opened.
        file_put_contents($this->directory . '/' . self::TEMPORARY, 'planted');

        try {
            $this->writer()->write('feed');
            $this->fail('An existing temporary file was opened.');
        } catch (\Magento\Framework\Exception\FileSystemException) {
            // Refused.
        }

        $this->assertSame('planted', file_get_contents($this->directory . '/' . self::TEMPORARY));
    }

    public function testADirectoryReplacedByALinkBeforeTheFirstWriteTakesNothingOutside(): void
    {
        // Issue #2: PHP resolves a link in a path before it opens it, so the writer checks the
        // directory itself before creating its file there.
        $outside = $this->directory . '-outside';
        mkdir($outside, 0o750);
        $writer = $this->writer();
        rename($this->directory, $this->directory . '-moved');
        symlink($outside, $this->directory);

        try {
            $writer->write('feed');
            $this->fail('A write through a linked directory was not refused.');
        } catch (\Magento\Framework\Exception\FileSystemException) {
            // Refused.
        } finally {
            unlink($this->directory);
            rename($this->directory . '-moved', $this->directory);
        }

        $this->assertSame(['.', '..'], scandir($outside), 'Nothing was created outside.');
        rmdir($outside);
    }

    public function testADirectoryReplacedByALinkBeforeTheCommitTakesNothingOutside(): void
    {
        // The rename and the discard go through the directory's path, so it is checked again.
        $outside = $this->directory . '-outside';
        mkdir($outside, 0o750);
        $writer = $this->writer();
        $writer->write('feed');
        rename($this->directory, $this->directory . '-moved');
        copy($this->directory . '-moved/' . self::TEMPORARY, $outside . '/' . self::TEMPORARY);
        symlink($outside, $this->directory);

        try {
            try {
                $writer->commit('llms.jsonl');
                $this->fail('A commit through a linked directory was not refused.');
            } catch (\Magento\Framework\Exception\FileSystemException) {
                $writer->discard();
            }

            $this->assertSame(
                ['.', '..', self::TEMPORARY],
                scandir($outside),
                'Nothing outside was renamed or deleted.'
            );
        } finally {
            unlink($this->directory);
            rename($this->directory . '-moved', $this->directory);
            (new FileDriver())->deleteDirectory($outside);
        }
    }

    /**
     * @return FeedFileWriter
     */
    private function writer(): FeedFileWriter
    {
        return new FeedFileWriter(new LinkSafeFilesystem(), $this->directory, self::TEMPORARY, 0o640);
    }
}
