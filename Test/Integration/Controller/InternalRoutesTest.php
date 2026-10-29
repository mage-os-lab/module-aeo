<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Integration\Controller;

use Magento\Store\Model\ScopeInterface;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\TestCase\AbstractController;
use MageOS\Aeo\Model\Config as AeoConfig;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The standard-router URL behind the llms documents answers with a 301 to the document's canonical
 * path, while the document is switched on.
 *
 * @magentoAppArea frontend
 */
class InternalRoutesTest extends AbstractController
{
    /**
     * @return void
     */
    #[Config(AeoConfig::XML_LLMS_ENABLED, 1, ScopeInterface::SCOPE_STORE, 'default')]
    public function testTheLlmsDocumentsInternalUrlRedirectsToItsCanonicalPath(): void
    {
        $this->dispatch('mageos-aeo/llms/index');

        $this->assertSame(301, $this->getResponse()->getHttpResponseCode());
        $this->assertRedirect($this->stringEndsWith('/llms.txt'));
    }

    /**
     * Review low item: /llms.txt/ was served as a second, publicly cacheable copy of /llms.txt.
     *
     * @return void
     */
    #[Config(AeoConfig::XML_LLMS_ENABLED, 1, ScopeInterface::SCOPE_STORE, 'default')]
    public function testATrailingSlashRedirectsToTheCanonicalPath(): void
    {
        $this->dispatch('/llms.txt/');

        $this->assertSame(301, $this->getResponse()->getHttpResponseCode(), '/llms.txt/ was not redirected.');
        $this->assertRedirect($this->stringEndsWith('/llms.txt'));
    }

    /**
     * Each document's standard-router URL.
     *
     * @return array<string, array{string}>
     */
    public static function documentRoutes(): array
    {
        return [
            'llms.txt'      => ['mageos-aeo/llms/index'],
            'llms-full.txt' => ['mageos-aeo/llmsfull/index'],
            'llms.jsonl'    => ['mageos-aeo/llmsjsonl/index'],
        ];
    }

    /**
     * A switched-off document's standard-router URL answers 404 itself, rather than redirecting to a
     * path that answers 404.
     *
     * @dataProvider documentRoutes
     * @param string $route
     * @return void
     */
    #[DataProvider('documentRoutes')]
    #[Config(AeoConfig::XML_LLMS_ENABLED, 0, ScopeInterface::SCOPE_STORE, 'default')]
    #[Config(AeoConfig::XML_LLMS_FULL_ENABLED, 0, ScopeInterface::SCOPE_STORE, 'default')]
    #[Config(AeoConfig::XML_LLMS_JSONL_ENABLED, 0, ScopeInterface::SCOPE_STORE, 'default')]
    public function testASwitchedOffDocumentsInternalUrlIsNotFound(string $route): void
    {
        $this->dispatch($route);

        $this->assertSame(404, $this->getResponse()->getHttpResponseCode(), $route . ' was not answered 404.');
    }
}
