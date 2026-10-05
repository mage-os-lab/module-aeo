<?php

declare(strict_types=1);

namespace MageOS\Aeo\Controller\Llmsjsonl;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Aeo\Exception\FeedStorageUnavailableException;
use MageOS\Aeo\Model\Config;
use MageOS\Aeo\Model\Feed\FeedCache;
use MageOS\Aeo\Model\Feed\FeedDelivery;
use MageOS\Aeo\Model\Feed\FeedRegenerator;
use MageOS\Aeo\Model\Feed\FeedStorage;
use MageOS\Seo\Model\Rebuild\RegenerationRequester;
use MageOS\Seo\Model\Router\CanonicalPathRedirect;

/**
 * Serves /llms.jsonl — one JSON-LD Product node per line (NDJSON) for AI catalog consumers.
 */
class Index implements HttpGetActionInterface
{
    private const FILE = 'llms.jsonl';

    /**
     * @param RawFactory $rawFactory
     * @param Config $aeoConfig
     * @param FeedStorage $feedStorage
     * @param CanonicalPathRedirect $canonicalPathRedirect
     * @param RegenerationRequester $regenerationRequester
     * @param StoreManagerInterface $storeManager
     * @param FeedDelivery $feedDelivery
     */
    public function __construct(
        private readonly RawFactory            $rawFactory,
        private readonly Config                $aeoConfig,
        private readonly FeedStorage           $feedStorage,
        private readonly CanonicalPathRedirect $canonicalPathRedirect,
        private readonly RegenerationRequester $regenerationRequester,
        private readonly StoreManagerInterface $storeManager,
        private readonly FeedDelivery          $feedDelivery,
    ) {
    }

    /**
     * Serve the NDJSON catalog from the pre-generated feed file.
     *
     * Web requests never build the document: a missing file queues a rebuild and
     * answers 503 Retry-After, so anonymous traffic cannot trigger catalog builds.
     * A file over 0.5 MiB — a large catalog's — is streamed rather than read into memory
     * (FeedDelivery).
     *
     * @return ResultInterface|ResponseInterface
     */
    public function execute(): ResultInterface|ResponseInterface
    {
        $redirect = $this->canonicalPathRedirect->check(self::FILE);
        if ($redirect !== null) {
            return $redirect;
        }

        $result = $this->rawFactory->create();

        if (!$this->aeoConfig->isLlmsJsonlEnabled()) {
            $result->setHttpResponseCode(404);
            $result->setContents('');
            return $result;
        }

        $storeId = (int) $this->storeManager->getStore()->getId();
        try {
            $file = $this->feedStorage->open(self::FILE, $storeId);
        } catch (FeedStorageUnavailableException) {
            // The storage directory, not the file: a rebuild cannot fix that, so none is queued.
            $result->setHttpResponseCode(503);
            $result->setHeader('Retry-After', '300', true);
            $result->setHeader('Cache-Control', 'no-store', true);
            $result->setContents('');
            return $result;
        }
        if ($file === null) {
            $this->regenerationRequester->request(FeedRegenerator::GROUP_JSONL);
            $result->setHttpResponseCode(503);
            $result->setHeader('Retry-After', '120', true);
            $result->setContents('');
            return $result;
        }

        return $this->feedDelivery->deliver(
            $file,
            'application/x-ndjson; charset=utf-8',
            FeedCache::TAG_LLMS_JSONL
        );
    }
}
