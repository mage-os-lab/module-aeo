<?php

declare(strict_types=1);

namespace MageOS\Aeo\Model\Feed;

use Magento\Framework\App\PageCache\NotCacheableInterface;
use Magento\Framework\App\Response\Http;

/**
 * A feed response whose body is sent from the open file, 4 KiB at a time, never held in memory.
 *
 * For feeds over FeedDelivery::BUFFER_LIMIT (issue #3): read into a string, a feed larger than PHP's
 * memory limit ends the request with a fatal error, and every concurrent cache miss holds its own
 * copy. Core's file response (MediaStorage\Model\File\Storage\Response) is not used: its transfer
 * adapter sends a Content-Type it detects from the file after the one set here, and opens the file
 * again by path, after FeedStorage has checked it.
 *
 * Not cacheable by the built-in full page cache, which keeps a response as one string — the memory
 * this response exists to save. Core's FrontController\BuiltinPlugin leaves a NotCacheableInterface
 * response alone altogether, so its headers reach the client as set: browsers keep it for
 * FeedCache's browser lifetime, and the server reads it from disk for each request that gets
 * through. Varnish caches it by its headers like any other feed.
 */
class FeedFileResponse extends Http implements NotCacheableInterface
{
    /**
     * Bytes read from the file and sent at a time, as core's file transfer adapter does.
     */
    private const PIECE_SIZE = 4096;

    /**
     * @var FeedFile|null
     */
    private ?FeedFile $feedFile = null;

    /**
     * Set the file to send as the body.
     *
     * @param FeedFile $feedFile
     * @return $this
     */
    public function setFeedFile(FeedFile $feedFile): self
    {
        $this->feedFile = $feedFile;

        return $this;
    }

    /**
     * Send the headers, then the file; any other response is sent as usual.
     *
     * A HEAD request gets the headers only. Content-Length is the file's size, set here again:
     * for a HEAD request, core's App\Http adds one measured from the body, which is empty.
     *
     * @return void
     */
    public function sendResponse()
    {
        if ($this->feedFile === null || $this->getHttpResponseCode() !== 200) {
            parent::sendResponse();
            return;
        }

        $this->setHeader('Content-Length', (string) $this->feedFile->size(), true);
        $this->sendHeaders();
        if ($this->request->isHead()) {
            $this->feedFile->close();
            return;
        }

        $this->feedFile->output(self::PIECE_SIZE);
    }
}
