<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Unit\Model\Feed;

// phpcs:disable Magento2.Functions.DiscouragedFunction -- builds the in-memory files read

use MageOS\Aeo\Model\Feed\FeedFile;
use PHPUnit\Framework\TestCase;

/**
 * A stored feed file read a piece at a time, and closed however the reading ends.
 */
class FeedFileTest extends TestCase
{
    /**
     * The handle of the last file made.
     *
     * @var resource|null
     */
    private $handle = null;

    /**
     * The file comes in pieces of the given size, in order, and is closed once read.
     *
     * @return void
     */
    public function testTheFileComesInPiecesAndIsClosedOnceRead(): void
    {
        $file = $this->file('abcdefghij');

        $this->assertSame(['abcd', 'efgh', 'ij'], iterator_to_array($file->pieces(4), false));
        $this->assertFalse(\is_resource($this->handle), 'Closed once read.');
        $this->assertSame([], iterator_to_array($file->pieces(4), false), 'Nothing is read after.');
    }

    /**
     * A piece is a byte at least.
     *
     * @return void
     */
    public function testAPieceIsAByteAtLeast(): void
    {
        $this->assertSame(['a', 'b', 'c'], iterator_to_array($this->file('abc')->pieces(0), false));
    }

    /**
     * Reading given up partway — a copy that failed, a client gone — closes the file all the same.
     *
     * @return void
     */
    public function testReadingGivenUpPartwayClosesTheFile(): void
    {
        $file   = $this->file('abcdefghij');
        $pieces = $file->pieces(4);

        $this->assertSame('abcd', $pieces->current());
        $pieces->next();
        $this->assertSame('efgh', $pieces->current(), 'Open while the reading may go on.');

        unset($pieces);
        $this->assertFalse(\is_resource($this->handle), 'Closed once the reading is given up.');
    }

    /**
     * Sent to the output whole, a piece at a time.
     *
     * @return void
     */
    public function testTheFileIsSentToTheOutputWhole(): void
    {
        $this->expectOutputString('abcdefghij');

        $this->file('abcdefghij')->output(3);
    }

    /**
     * Read whole, then closed.
     *
     * @return void
     */
    public function testTheFileIsReadWholeThenClosed(): void
    {
        $file = $this->file('abc');

        $this->assertSame(3, $file->size());
        $this->assertSame('abc', $file->contents());
        $this->assertFalse(\is_resource($this->handle));
        $this->assertSame('', $file->contents(), 'Nothing is read after.');
    }

    /**
     * A file in memory with the given content.
     *
     * @param string $content
     * @return FeedFile
     */
    private function file(string $content): FeedFile
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            $this->fail('No in-memory file.');
        }
        fwrite($handle, $content);
        rewind($handle);
        $this->handle = $handle;

        return new FeedFile($handle, \strlen($content));
    }
}
