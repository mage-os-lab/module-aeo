<?php

declare(strict_types=1);

namespace MageOS\Aeo\Controller\Llms;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\Aeo\Model\Config;
use MageOS\Aeo\Model\Feed\FeedCache;
use MageOS\Aeo\Model\Feed\FeedDelivery;
use MageOS\Aeo\Model\Feed\FeedRegenerator;
use MageOS\Aeo\Model\Feed\FeedStorage;
use MageOS\Seo\Model\Rebuild\RegenerationRequester;
use MageOS\Seo\Model\Router\CanonicalPathRedirect;

class Index implements HttpGetActionInterface
{
    private const FILE = 'llms.txt';

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
     * Serve /llms.txt from the pre-generated feed file.
     *
     * Web requests never build the document: a missing file queues a rebuild, so
     * anonymous traffic cannot trigger catalog builds.
     *
     * A missing file answers 404, not 503. Lighthouse's llms-txt audit scores a 5xx
     * as a failure (0) and any 4xx as "not applicable"; a store should not fail the
     * audit because the first rebuild has not run yet. Retry-After still tells
     * clients when to come back, and no-store keeps the 404 out of Varnish/FPC.
     *
     * A file over 0.5 MiB is streamed rather than read into memory (FeedDelivery).
     *
     * @return ResultInterface|ResponseInterface
     */
    public function execute(): ResultInterface|ResponseInterface
    {
        // Query-string variants and the standard-router URL collapse to the canonical
        // path so they cannot be used to force cache-missing requests.
        $redirect = $this->canonicalPathRedirect->check(self::FILE);
        if ($redirect !== null) {
            return $redirect;
        }

        $result = $this->rawFactory->create();

        if (!$this->aeoConfig->isLlmsTxtEnabled()) {
            $result->setHttpResponseCode(404);
            $result->setContents('');
            return $result;
        }

        $storeId = (int) $this->storeManager->getStore()->getId();
        $file    = $this->feedStorage->open(self::FILE, $storeId);
        if ($file === null) {
            $this->regenerationRequester->request(FeedRegenerator::GROUP_LLMS);
            $result->setHttpResponseCode(404);
            $result->setHeader('Retry-After', '120', true);
            $result->setHeader('Cache-Control', 'no-store', true);
            $result->setContents('');
            return $result;
        }

        return $this->feedDelivery->deliver($file, 'text/plain; charset=utf-8', FeedCache::TAG_LLMS);
    }
}
