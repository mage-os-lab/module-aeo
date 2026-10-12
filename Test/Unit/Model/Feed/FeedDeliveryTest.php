<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Unit\Model\Feed;

// phpcs:disable Magento2.Functions.DiscouragedFunction -- the test writes the files it serves

use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use MageOS\Aeo\Model\Feed\FeedCache;
use MageOS\Aeo\Model\Feed\FeedDelivery;
use MageOS\Aeo\Model\Feed\FeedFile;
use MageOS\Aeo\Model\Feed\FeedFileResponse;
use MageOS\Aeo\Model\Feed\FeedFileResponseFactory;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Issue #3: a feed up to 0.5 MiB is answered from memory, a larger one streamed from its file.
 *
 * Doubles Magento's generated factories, so it runs in the unit job inside an installation, not
 * under Infection.
 */
#[Group('magento-generated')]
class FeedDeliveryTest extends TestCase
{
    /**
     * @var string
     */
    private string $path = '';

    /**
     * Headers set on the answer, by name.
     *
     * @var array<string, string>
     */
    private array $headers = [];

    protected function setUp(): void
    {
        $this->path = (string) tempnam((string) sys_get_temp_dir(), 'mageos-aeo-feed-');
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function testAFeedUpToTheLimitIsAnsweredFromMemory(): void
    {
        $content = str_repeat('x', FeedDelivery::BUFFER_LIMIT);
        file_put_contents($this->path, $content);
        $contents = null;
        $raw      = $this->createStub(Raw::class);
        $raw->method('setHeader')->willReturnCallback($this->recordHeader(...));
        $raw->method('setContents')->willReturnCallback(
            function (string $value) use (&$contents, $raw): Raw {
                $contents = $value;
                return $raw;
            }
        );

        $answer = $this->delivery($raw, $this->createStub(FeedFileResponse::class))
            ->deliver($this->open(), 'text/plain; charset=utf-8', FeedCache::TAG_LLMS);

        $this->assertSame($raw, $answer);
        $this->assertSame($content, $contents);
        $this->assertSame(
            [
                'Content-Type'   => 'text/plain; charset=utf-8',
                'Cache-Control'  => FeedCache::CACHE_CONTROL,
                'X-Magento-Tags' => FeedCache::TAG_LLMS,
            ],
            $this->headers
        );
    }

    public function testALargerFeedIsStreamedFromItsFile(): void
    {
        file_put_contents($this->path, str_repeat('x', FeedDelivery::BUFFER_LIMIT + 1));
        $file     = $this->open();
        $response = $this->createMock(FeedFileResponse::class);
        $response->method('setHeader')->willReturnCallback($this->recordHeader(...));
        $response->expects($this->once())->method('setHttpResponseCode')->with(200);
        $response->expects($this->once())->method('setFeedFile')->with($file);

        $answer = $this->delivery($this->createStub(Raw::class), $response)
            ->deliver($file, 'application/x-ndjson; charset=utf-8', FeedCache::TAG_LLMS_JSONL);

        $this->assertSame($response, $answer);
        $this->assertSame(
            [
                'Content-Type'   => 'application/x-ndjson; charset=utf-8',
                'Content-Length' => (string) (FeedDelivery::BUFFER_LIMIT + 1),
                'Cache-Control'  => FeedCache::CACHE_CONTROL,
                'X-Magento-Tags' => FeedCache::TAG_LLMS_JSONL,
            ],
            $this->headers
        );
    }

    /**
     * Record a header set on the answer.
     *
     * @param string $name
     * @param string $value
     * @return null
     */
    private function recordHeader(string $name, string $value): mixed
    {
        $this->headers[$name] = $value;

        return null;
    }

    /**
     * Open the test file as FeedStorage would.
     *
     * @return FeedFile
     */
    private function open(): FeedFile
    {
        $handle = fopen($this->path, 'rb');
        $this->assertNotFalse($handle);

        return new FeedFile($handle, (int) filesize($this->path));
    }

    /**
     * @param Raw $raw
     * @param FeedFileResponse $response
     * @return FeedDelivery
     */
    private function delivery(Raw $raw, FeedFileResponse $response): FeedDelivery
    {
        $rawFactory = $this->createStub(RawFactory::class);
        $rawFactory->method('create')->willReturn($raw);
        $responseFactory = $this->createStub(FeedFileResponseFactory::class);
        $responseFactory->method('create')->willReturn($response);

        return new FeedDelivery($rawFactory, $responseFactory);
    }
}
