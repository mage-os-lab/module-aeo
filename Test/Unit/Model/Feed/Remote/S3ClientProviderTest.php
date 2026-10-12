<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Unit\Model\Feed\Remote;

use Aws\CommandInterface;
use Aws\Credentials\Credentials;
use Aws\MockHandler;
use Aws\Result;
use GuzzleHttp\Psr7\Utils;
use Magento\AwsS3\Driver\CachedCredentialsProvider;
use Magento\Framework\ObjectManagerInterface;
use Magento\RemoteStorage\Model\Config as RemoteStorageConfig;
use MageOS\Aeo\Exception\FeedStorageUnavailableException;
use MageOS\Aeo\Model\Feed\Remote\S3ClientProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

/**
 * The feeds' S3 client, configured from `remote_storage` as Magento configures its own.
 */
class S3ClientProviderTest extends TestCase
{
    /**
     * Keys sit behind the remote storage prefix as Magento's adapter puts them.
     *
     * @return void
     */
    public function testKeysSitBehindThePrefix(): void
    {
        $this->assertSame('mageos_aeo/store_1/llms.txt', $this->provider([], '')->key('/mageos_aeo/store_1/llms.txt'));
        $this->assertSame('tenant/mageos_aeo/llms.txt', $this->provider([], 'tenant')->key('mageos_aeo/llms.txt'));
        $this->assertSame('/tenant/mageos_aeo/llms.txt', $this->provider([], '/tenant/')->key('mageos_aeo/llms.txt'));
    }

    /**
     * The client reaches the configured endpoint, path-style when configured — as MinIO needs — and
     * the configured bucket.
     *
     * @return void
     */
    public function testTheClientUsesTheConfiguredEndpointAndAddressing(): void
    {
        $request  = null;
        $provider = $this->provider([
            'endpoint'    => 'http://minio:9000',
            'path_style'  => '1',
            'credentials' => ['key' => 'key', 'secret' => 'secret'],
            'handler'     => $this->handler($request),
        ]);

        $provider->client()->getObject(['Bucket' => $provider->bucket(), 'Key' => 'mageos_aeo/llms.txt']);

        $this->assertSame('feeds-bucket', $provider->bucket());
        $this->assertInstanceOf(RequestInterface::class, $request);
        $this->assertSame('minio', $request->getUri()->getHost());
        $this->assertSame('/feeds-bucket/mageos_aeo/llms.txt', $request->getUri()->getPath());
    }

    /**
     * Without both a key and a secret, the credentials Magento_AwsS3 caches are used; with them, they
     * are not asked for.
     *
     * @return void
     */
    public function testWithoutAKeyMagentosCachedCredentialsAreUsed(): void
    {
        $cached = $this->createMock(CachedCredentialsProvider::class);
        $cached->expects($this->once())->method('get')->willReturn(new Credentials('cached-key', 'cached-secret'));
        $client = $this->provider([], '', $cached)->client();
        $this->assertSame('cached-key', $client->getCredentials()->wait()->getAccessKeyId());

        $cached = $this->createMock(CachedCredentialsProvider::class);
        $cached->expects($this->once())->method('get')->willReturn(new Credentials('cached-key', 'cached-secret'));
        $client = $this->provider(['credentials' => ['key' => 'own-key', 'secret' => '']], '', $cached)->client();
        $this->assertSame(
            'cached-key',
            $client->getCredentials()->wait()->getAccessKeyId(),
            'A key alone is not used.'
        );

        $unused = $this->createMock(CachedCredentialsProvider::class);
        $unused->expects($this->never())->method('get');
        $this->provider(['credentials' => ['key' => 'own-key', 'secret' => 'own-secret']], '', $unused)->client();
    }

    /**
     * A configured HTTP handler is built as Magento builds it.
     *
     * @return void
     */
    public function testAConfiguredHttpHandlerIsBuilt(): void
    {
        $built         = false;
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->expects($this->once())->method('create')->with('Vendor\HttpHandlerFactory')
            ->willReturn(static function (array $config) use (&$built): callable {
                $built = true;
                return static fn () => null;
            });

        $this->provider(
            ['http_handler' => 'Vendor\HttpHandlerFactory', 'credentials' => ['key' => 'k', 'secret' => 's']],
            '',
            null,
            $objectManager
        )->client();

        $this->assertTrue($built);
    }

    /**
     * Remote storage naming no bucket or region cannot be read.
     *
     * @return void
     */
    public function testNoBucketIsUnavailableStorage(): void
    {
        $this->expectException(FeedStorageUnavailableException::class);

        $this->provider(['bucket' => '', 'credentials' => ['key' => 'k', 'secret' => 's']])->client();
    }

    /**
     * One client per request.
     *
     * @return void
     */
    public function testTheClientIsBuiltOncePerRequest(): void
    {
        $provider = $this->provider(['credentials' => ['key' => 'k', 'secret' => 's']]);
        $client   = $provider->client();

        $this->assertSame($client, $provider->client());
        $provider->_resetState();
        $this->assertNotSame($client, $provider->client());
    }

    /**
     * A provider over the given `remote_storage/config` values.
     *
     * @param array<string,mixed> $config Merged over a bucket and region
     * @param string $prefix
     * @param CachedCredentialsProvider|null $cached
     * @param ObjectManagerInterface|null $objectManager
     * @return S3ClientProvider
     */
    private function provider(
        array $config,
        string $prefix = '',
        ?CachedCredentialsProvider $cached = null,
        ?ObjectManagerInterface $objectManager = null
    ): S3ClientProvider {
        $remoteStorage = $this->createStub(RemoteStorageConfig::class);
        $remoteStorage->method('getConfig')->willReturn(
            $config + ['bucket' => 'feeds-bucket', 'region' => 'us-east-1']
        );
        $remoteStorage->method('getPrefix')->willReturn($prefix);

        if ($cached === null) {
            $cached = $this->createStub(CachedCredentialsProvider::class);
            $cached->method('get')->willReturn(new Credentials('k', 's'));
        }

        return new S3ClientProvider(
            $remoteStorage,
            $cached,
            $objectManager ?? $this->createStub(ObjectManagerInterface::class)
        );
    }

    /**
     * A handler answering every command with an empty object, remembering the HTTP request.
     *
     * @param RequestInterface|null $request
     * @return MockHandler
     */
    private function handler(?RequestInterface &$request): MockHandler
    {
        $handler = new MockHandler();
        $handler->append(static function (CommandInterface $command, RequestInterface $sent) use (&$request): Result {
            $request = $sent;

            return new Result(['Body' => Utils::streamFor('')]);
        });

        return $handler;
    }
}
