<?php

declare(strict_types=1);

namespace MageOS\Aeo\Model\Feed;

use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;

/**
 * Answers a feed request from a stored file: from memory up to BUFFER_LIMIT, streamed above it.
 *
 * A feed held in memory is a string the built-in full page cache can store, which matters for
 * llms.txt and llms-full.txt, read far more often than they change. Above the limit — llms.jsonl of
 * a large catalog — the file is streamed (FeedFileResponse), so serving it takes the same memory
 * whatever its size (issue #3).
 */
class FeedDelivery
{
    /**
     * Largest feed answered from memory, in bytes: 0.5 MiB.
     */
    public const BUFFER_LIMIT = 524288;

    /**
     * @param RawFactory $rawFactory
     * @param FeedFileResponseFactory $feedFileResponseFactory
     */
    public function __construct(
        private readonly RawFactory              $rawFactory,
        private readonly FeedFileResponseFactory $feedFileResponseFactory
    ) {
    }

    /**
     * The 200 response serving a stored feed file, with the feed's cache policy and cache tag.
     *
     * @param FeedFile $file
     * @param string $contentType
     * @param string $cacheTag One of the FeedCache::TAG_* values
     * @return ResultInterface|ResponseInterface
     */
    public function deliver(FeedFile $file, string $contentType, string $cacheTag): ResultInterface|ResponseInterface
    {
        if ($file->size() <= self::BUFFER_LIMIT) {
            $result = $this->rawFactory->create();
            $result->setHttpResponseCode(200);
            $result->setHeader('Content-Type', $contentType, true);
            $result->setHeader('Cache-Control', FeedCache::CACHE_CONTROL, true);
            $result->setHeader('X-Magento-Tags', $cacheTag, true);
            $result->setContents($file->contents());

            return $result;
        }

        $response = $this->feedFileResponseFactory->create();
        $response->setHttpResponseCode(200);
        $response->setHeader('Content-Type', $contentType, true);
        $response->setHeader('Content-Length', (string) $file->size(), true);
        $response->setHeader('Cache-Control', FeedCache::CACHE_CONTROL, true);
        $response->setHeader('X-Magento-Tags', $cacheTag, true);
        $response->setFeedFile($file);

        return $response;
    }
}
