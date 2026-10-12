<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Unit\Model\Feed;

// phpcs:disable Magento2.Functions.DiscouragedFunction -- the test writes the file it streams

use Laminas\Http\Header\HeaderInterface;
use Magento\Framework\App\Http\Context;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Session\Config\ConfigInterface as SessionConfig;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Framework\Stdlib\DateTime;
use MageOS\Aeo\Model\Feed\FeedFile;
use MageOS\Aeo\Model\Feed\FeedFileResponse;
use PHPUnit\Framework\TestCase;

/**
 * Issue #3: a large feed is sent from the open file with bounded memory, whatever its size.
 */
class FeedFileResponseTest extends TestCase
{
    /**
     * @var string
     */
    private string $path = '';

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

    public function testTheWholeFileIsSent(): void
    {
        $content = str_repeat("{\"sku\":\"line\"}\n", 1000);
        file_put_contents($this->path, $content);
        $response = $this->response()->setFeedFile($this->open());
        $response->setHttpResponseCode(200);

        ob_start();
        $response->sendResponse();

        $this->assertSame($content, ob_get_clean());
    }

    public function testAHeadRequestGetsNoBodyAndTheFilesLength(): void
    {
        file_put_contents($this->path, 'feed');
        $response = $this->response(true)->setFeedFile($this->open());
        $response->setHttpResponseCode(200);
        // What core's App\Http::handleHeadRequest() adds: the length of the empty body.
        $response->setHeader('Content-Length', '0');

        ob_start();
        $response->sendResponse();

        $this->assertSame('', ob_get_clean());
        $lengths = [];
        foreach ($response->getHeaders() as $header) {
            if ($header instanceof HeaderInterface && strtolower($header->getFieldName()) === 'content-length') {
                $lengths[] = $header->getFieldValue();
            }
        }
        $this->assertSame(['4'], $lengths);
    }

    public function testAnythingButA200IsSentAsUsual(): void
    {
        file_put_contents($this->path, 'feed');
        $response = $this->response()->setFeedFile($this->open());
        $response->setHttpResponseCode(503);
        $response->setContent('');

        ob_start();
        $response->sendResponse();

        $this->assertSame('', ob_get_clean());
    }

    public function testSendingTakesTheSameMemoryWhateverTheFileSize(): void
    {
        // 8 MiB, sent through an output buffer that passes each 4 KiB on and keeps nothing, as a
        // web server does. Held as a string, the file alone would raise the peak by 8 MiB.
        $handle = fopen($this->path, 'wb');
        $this->assertNotFalse($handle);
        $line = str_repeat('x', 1023) . "\n";
        for ($i = 0; $i < 8192; $i++) {
            fwrite($handle, $line);
        }
        fclose($handle);
        $response = $this->response()->setFeedFile($this->open());
        $response->setHttpResponseCode(200);

        // Memory is sampled each time a piece leaves: a file held as a string would be in it.
        $sent   = 0;
        $before = memory_get_usage();
        $most   = 0;
        ob_start(static function (string $piece) use (&$sent, &$most, $before): string {
            $sent += \strlen($piece);
            $most  = max($most, memory_get_usage() - $before);
            return '';
        }, 4096);
        $response->sendResponse();
        ob_end_clean();

        $this->assertSame(8 * 1024 * 1024, $sent, 'Every byte was sent.');
        $this->assertLessThan(1024 * 1024, $most, 'Sending held less than 1 MiB more at any point.');
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
     * A response for a GET, or a HEAD, request.
     *
     * @param bool $head
     * @return FeedFileResponse
     */
    private function response(bool $head = false): FeedFileResponse
    {
        $request = $this->createStub(HttpRequest::class);
        $request->method('isHead')->willReturn($head);

        return new FeedFileResponse(
            $request,
            $this->createStub(CookieManagerInterface::class),
            $this->createStub(CookieMetadataFactory::class),
            $this->createStub(Context::class),
            $this->createStub(DateTime::class),
            $this->createStub(SessionConfig::class)
        );
    }
}
