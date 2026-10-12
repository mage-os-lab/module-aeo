<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Integration\Controller;

use Magento\Framework\App\PageCache\NotCacheableInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Controller\ResultInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\TestFramework\Fixture\Config;
use Magento\TestFramework\Helper\Bootstrap;
use MageOS\Aeo\Controller\Llmsjsonl\Index as LlmsJsonl;
use MageOS\Aeo\Model\Feed\FeedCache;
use MageOS\Aeo\Model\Feed\FeedStorage;
use PHPUnit\Framework\TestCase;

/**
 * How a stored feed reaches the client (issue #3): a large one is sent from the open file and never
 * read into a PHP string, a small one is answered from memory and cached as before.
 *
 * The controller is run directly: dispatch() keeps the shared response, and a streamed feed is a
 * response of its own, which the test sends itself.
 *
 * @magentoAppArea frontend
 */
class FeedServingTest extends TestCase
{
    /**
     * @return void
     */
    protected function tearDown(): void
    {
        Bootstrap::getObjectManager()->create(FeedStorage::class)->deleteForStore('llms*', $this->storeId());
    }

    /**
     * Over 0.5 MiB: a response that streams the file and that the built-in page cache will not
     * store, with the feed's own headers.
     *
     * @return void
     */
    #[Config('mageos_aeo/llms_txt/jsonl_enabled', 1, ScopeInterface::SCOPE_STORE, 'default')]
    public function testALargeFeedIsStreamedFromTheFile(): void
    {
        $line    = '{"@type":"Product","name":"' . str_repeat('x', 200) . '"}' . "\n";
        $content = str_repeat($line, (int) ceil(600 * 1024 / \strlen($line)));
        Bootstrap::getObjectManager()->create(FeedStorage::class)->write('llms.jsonl', $this->storeId(), $content);

        $response = $this->serve();

        $this->assertInstanceOf(NotCacheableInterface::class, $response, 'The feed was buffered.');
        $this->assertInstanceOf(HttpResponse::class, $response);
        $this->assertSame(200, $response->getHttpResponseCode());
        $this->assertSame('application/x-ndjson; charset=utf-8', $this->header($response, 'Content-Type'));
        $this->assertSame((string) \strlen($content), $this->header($response, 'Content-Length'));
        $this->assertCachePolicy($response);
        $this->assertSame(FeedCache::TAG_LLMS_JSONL, $this->header($response, 'X-Magento-Tags'));

        ob_start();
        $response->sendResponse();
        $sent = (string) ob_get_clean();

        $this->assertSame($content, $sent, 'The whole file is sent.');
    }

    /**
     * Up to 0.5 MiB: answered from memory, so the built-in page cache stores it as before.
     *
     * @return void
     */
    #[Config('mageos_aeo/llms_txt/jsonl_enabled', 1, ScopeInterface::SCOPE_STORE, 'default')]
    public function testASmallFeedIsAnsweredFromMemory(): void
    {
        Bootstrap::getObjectManager()->create(FeedStorage::class)->write('llms.jsonl', $this->storeId(), "{}\n");

        $result = $this->serve();

        $this->assertInstanceOf(ResultInterface::class, $result);
        $response = Bootstrap::getObjectManager()->create(HttpResponse::class);
        $result->renderResult($response);
        $this->assertSame("{}\n", $response->getContent());
        $this->assertCachePolicy($response);
    }

    /**
     * Assert that a response carries the feeds' cache policy.
     *
     * Compared directive by directive: the header object sorts them when it renders the value.
     *
     * @param HttpResponse $response
     * @return void
     */
    private function assertCachePolicy(HttpResponse $response): void
    {
        $directives = static function (?string $value): array {
            $parts = array_map('trim', explode(',', (string) $value));
            sort($parts);
            return $parts;
        };

        $this->assertSame(
            $directives(FeedCache::CACHE_CONTROL),
            $directives($this->header($response, 'Cache-Control'))
        );
    }

    /**
     * Run the llms.jsonl controller for its canonical path.
     *
     * @return mixed
     */
    private function serve(): mixed
    {
        $request = Bootstrap::getObjectManager()->get(RequestInterface::class);
        $this->assertInstanceOf(HttpRequest::class, $request);
        $request->setPathInfo('/llms.jsonl');

        return Bootstrap::getObjectManager()->create(LlmsJsonl::class)->execute();
    }

    /**
     * A response header's value, or null.
     *
     * @param HttpResponse $response
     * @param string $name
     * @return string|null
     */
    private function header(HttpResponse $response, string $name): ?string
    {
        $header = $response->getHeader($name);

        return $header === false ? null : (string) $header->getFieldValue();
    }

    /**
     * @return int
     */
    private function storeId(): int
    {
        return (int) Bootstrap::getObjectManager()->get(StoreManagerInterface::class)->getStore('default')->getId();
    }
}
