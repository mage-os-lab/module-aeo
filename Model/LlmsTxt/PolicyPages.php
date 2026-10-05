<?php

declare(strict_types=1);

namespace MageOS\Aeo\Model\LlmsTxt;

use Magento\Cms\Api\GetPageByIdentifierInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use MageOS\Aeo\Model\Config;
use MageOS\Seo\Model\Config as SeoConfig;
use Psr\Log\LoggerInterface;

/**
 * The pages listed under Policies in /llms.txt and /llms-full.txt, for one store view.
 *
 * First the returns policy page, while MageOS_Seo's return policy is on (SEO Merchant Policies), then
 * each CMS page chosen under Pages Listed in llms.txt, in that order. A page is looked up as core
 * looks up a configured page: the identifier, without the `|ID` core's page source adds to a repeated
 * one, among the pages active in this store view or in all store views. A chosen page that is not
 * there is left out and logged, rather than listed with a URL that answers 404.
 */
class PolicyPages
{
    /**
     * @param Config $aeoConfig
     * @param SeoConfig $seoConfig
     * @param GetPageByIdentifierInterface $getPageByIdentifier
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Config                       $aeoConfig,
        private readonly SeoConfig                    $seoConfig,
        private readonly GetPageByIdentifierInterface $getPageByIdentifier,
        private readonly LoggerInterface              $logger
    ) {
    }

    /**
     * The store view's policy pages: title, URL and an optional note, in order.
     *
     * @param int $storeId
     * @param string $baseUrl The store view's base URL, without a trailing slash
     * @return array<int, array{title: string, url: string, note: string}>
     */
    public function entries(int $storeId, string $baseUrl): array
    {
        $entries = [];

        $returnsUrl = $this->seoConfig->getReturnPolicyUrl($storeId);
        if ($returnsUrl !== null) {
            $entries[] = ['title' => (string) __('Returns policy'), 'url' => $returnsUrl, 'note' => ''];
        }

        foreach ($this->aeoConfig->getLlmsPolicyPages($storeId) as $value) {
            // Core's own stripping (Cms\Helper\Page): a pipe at position 0 is left in place.
            $delimiter  = strrpos($value, '|');
            $identifier = $delimiter ? substr($value, 0, $delimiter) : $value;

            try {
                $page = $this->getPageByIdentifier->execute($identifier, $storeId);
            } catch (NoSuchEntityException) {
                $this->logger->warning(\sprintf(
                    'MageOS_Aeo: the llms.txt policy page "%s" is not active in store view %d, so it is'
                    . ' left out. Choose another under Pages Listed in llms.txt.',
                    $identifier,
                    $storeId
                ));
                continue;
            }

            $url = $baseUrl . '/' . ltrim((string) $page->getIdentifier(), '/');
            if ($url === $returnsUrl) {
                continue;
            }

            $entries[] = [
                'title' => (string) $page->getTitle(),
                'url'   => $url,
                'note'  => (string) $page->getMetaDescription(),
            ];
        }

        return $entries;
    }
}
