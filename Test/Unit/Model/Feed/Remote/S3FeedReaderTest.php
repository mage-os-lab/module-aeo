<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Unit\Model\Feed\Remote;

use Aws\CommandInterface;
use Aws\Exception\CredentialsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use MageOS\Aeo\Exception\FeedStorageUnavailableException;
use MageOS\Aeo\Model\Feed\Remote\S3ClientProvider;
use MageOS\Aeo\Model\Feed\Remote\S3FeedReader;
use PHPUnit\Framework\TestCase;

/**
 * Reading a feed from S3 as a stream, against the SDK's own mock handler.
 */
class S3FeedReaderTest extends TestCase
{
    /**
     * The commands the client was given.
     *
     * @var CommandInterface[]
     */
    private array $commands = [];

    /**
     * The object comes back as a stream not read yet, with its size; it is read as the feed is sent.
     *
     * @return void
     */
    public function testTheObjectIsHandedOnAsAStreamNotReadYet(): void
    {
        $pulled = 0;
        $body   = new PumpStream(
            static function () use (&$pulled): string|false {
                return $pulled++ === 0 ? '{"sku":"a"}' : false;
            }
        );
        $reader = $this->reader(['Body' => $body, 'ContentLength' => 11]);

        $file = $reader->open('mageos_aeo/store_1/llms.jsonl');

        $this->assertNotNull($file);
        $this->assertSame(11, $file->size());
        $this->assertSame(0, $pulled, 'Nothing is read before the feed is sent.');
        $this->assertSame('{"sku":"a"}', $file->contents());

        $command = $this->commands[0];
        $this->assertSame('GetObject', $command->getName());
        $this->assertSame('feeds-bucket', $command['Bucket']);
        $this->assertSame('tenant/mageos_aeo/store_1/llms.jsonl', $command['Key'], 'Behind the remote storage prefix.');
        $this->assertTrue($command['@http']['stream'] ?? false, 'The body is streamed, not downloaded whole.');
    }

    /**
     * No such object is no feed: the request that met it queues a rebuild.
     *
     * @return void
     */
    public function testAMissingObjectIsNoFeed(): void
    {
        $this->assertNull($this->reader($this->error(404, 'NoSuchKey'))->open('mageos_aeo/store_1/llms.txt'));
    }

    /**
     * Any other error is storage that cannot be read, which a rebuild cannot fix.
     *
     * @return void
     */
    public function testAnyOtherErrorIsUnavailableStorage(): void
    {
        $this->expectException(FeedStorageUnavailableException::class);
        $this->expectExceptionMessage('Remote storage could not be read: Access Denied');

        $this->reader($this->error(403, 'AccessDenied', 'Access Denied'))->open('mageos_aeo/store_1/llms.txt');
    }

    /**
     * Credentials that cannot be had — an instance role that does not answer — are storage that
     * cannot be read too. The SDK reports them with an exception of its own that is not an S3 error.
     *
     * @return void
     */
    public function testCredentialsThatCannotBeHadAreUnavailableStorage(): void
    {
        $credentials = static fn (): PromiseInterface => Create::rejectionFor(
            new CredentialsException('Error retrieving credentials from the instance profile metadata service.')
        );

        $this->expectException(FeedStorageUnavailableException::class);
        $this->expectExceptionMessage('instance profile metadata service');

        $this->reader(['Body' => Utils::streamFor('')], $credentials)->open('mageos_aeo/store_1/llms.txt');
    }

    /**
     * Remote storage set up with no bucket is reported as it is, not as an error reading it.
     *
     * @return void
     */
    public function testStorageWithNoBucketIsReportedAsItIs(): void
    {
        $noBucket = new FeedStorageUnavailableException(__('Remote storage names no bucket or region.'));
        $provider = $this->createStub(S3ClientProvider::class);
        $provider->method('client')->willThrowException($noBucket);

        try {
            (new S3FeedReader($provider))->open('mageos_aeo/store_1/llms.txt');
            $this->fail('The read did not fail.');
        } catch (FeedStorageUnavailableException $e) {
            $this->assertSame($noBucket, $e);
        }
    }

    /**
     * A reader whose client answers with the given result or error.
     *
     * @param array<string,mixed>|callable $answer The result's data, or a function answering the command
     * @param array<string,string>|callable $credentials
     * @return S3FeedReader
     */
    private function reader(
        array|callable $answer,
        array|callable $credentials = ['key' => 'key', 'secret' => 'secret']
    ): S3FeedReader {
        $handler = new MockHandler();
        $handler->append(function (CommandInterface $command) use ($answer) {
            $this->commands[] = $command;

            return \is_callable($answer) ? $answer($command) : new Result($answer);
        });

        $client = new S3Client([
            'region'      => 'us-east-1',
            'version'     => 'latest',
            'credentials' => $credentials,
            'handler'     => $handler,
        ]);

        $provider = $this->createStub(S3ClientProvider::class);
        $provider->method('client')->willReturn($client);
        $provider->method('bucket')->willReturn('feeds-bucket');
        $provider->method('key')->willReturnCallback(static fn (string $path): string => 'tenant/' . $path);

        return new S3FeedReader($provider);
    }

    /**
     * An S3 error with the given HTTP status, worded as the SDK words one: S3's message in a longer
     * one of its own.
     *
     * @param int $status
     * @param string $code
     * @param string $message S3's message
     * @return callable
     */
    private function error(int $status, string $code, string $message = 'Error'): callable
    {
        return static fn (CommandInterface $command): S3Exception => new S3Exception(
            'Error executing "GetObject"; AWS HTTP error: ' . $status . ' ' . $code . ' (client): ' . $message,
            $command,
            ['code' => $code, 'message' => $message, 'response' => new Response($status)]
        );
    }
}
