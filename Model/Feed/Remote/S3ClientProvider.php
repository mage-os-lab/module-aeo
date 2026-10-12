<?php

declare(strict_types=1);

namespace MageOS\Aeo\Model\Feed\Remote;

use Aws\S3\S3Client;
use Magento\AwsS3\Driver\CachedCredentialsProvider;
use Magento\Framework\ObjectManager\ResetAfterRequestInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\RemoteStorage\Model\Config as RemoteStorageConfig;
use MageOS\Aeo\Exception\FeedStorageUnavailableException;

/**
 * An S3 client for the bucket Magento's remote storage uses, for reading feeds as streams.
 *
 * Magento's S3 driver reads an object whole into memory before it hands back a stream
 * (Magento\AwsS3\Driver\AwsS3::fileOpen() calls the adapter's read()), and builds its client and
 * adapter where nothing can reach them. Feeds are served a piece at a time at any size, so they are
 * read with a client of their own, configured exactly as Magento's is: from `remote_storage` in
 * app/etc/env.php — bucket, region, endpoint, path-style addressing, key and secret — and, without
 * a key, with the credentials Magento_AwsS3 caches from the instance or the environment.
 *
 * Built once per request, as the configuration does not change within one.
 */
class S3ClientProvider implements ResetAfterRequestInterface
{
    /**
     * @var S3Client|null
     */
    private ?S3Client $client = null;

    /**
     * @param RemoteStorageConfig $remoteStorageConfig
     * @param CachedCredentialsProvider $cachedCredentialsProvider
     * @param ObjectManagerInterface $objectManager Builds a configured http_handler, as Magento does
     */
    public function __construct(
        private readonly RemoteStorageConfig       $remoteStorageConfig,
        private readonly CachedCredentialsProvider $cachedCredentialsProvider,
        private readonly ObjectManagerInterface    $objectManager
    ) {
    }

    /**
     * The client.
     *
     * @throws FeedStorageUnavailableException When remote storage names no bucket or region
     * @return S3Client
     */
    public function client(): S3Client
    {
        return $this->client ??= new S3Client($this->clientConfig());
    }

    /**
     * The bucket.
     *
     * @return string
     */
    public function bucket(): string
    {
        return (string) ($this->remoteStorageConfig->getConfig()['bucket'] ?? '');
    }

    /**
     * The key of a path in remote storage, behind the configured prefix as Magento's adapter puts it.
     *
     * Mirrors League\Flysystem\PathPrefixer, which Magento's AwsS3V3Adapter applies with
     * `remote_storage/prefix`.
     *
     * @param string $path
     * @return string
     */
    public function key(string $path): string
    {
        $prefix = $this->remoteStorageConfig->getPrefix();
        $path   = ltrim($path, '\\/');

        return $prefix === '' ? $path : rtrim($prefix, '\\/') . '/' . $path;
    }

    /**
     * Forget the client between worker-mode requests.
     *
     * @return void
     */
    public function _resetState(): void // phpcs:ignore PSR2.Methods.MethodDeclaration.Underscore -- framework interface
    {
        $this->client = null;
    }

    /**
     * The client's configuration, prepared as Magento prepares its own.
     *
     * Mirrors the private Magento\AwsS3\Driver\AwsS3Factory::prepareConfig(): compare the two when
     * Magento changes it.
     *
     * @throws FeedStorageUnavailableException
     * @return array<string,mixed>
     */
    private function clientConfig(): array
    {
        $config            = $this->remoteStorageConfig->getConfig();
        $config['version'] = 'latest';

        if (empty($config['credentials']['key']) || empty($config['credentials']['secret'])) {
            $config['credentials'] = $this->cachedCredentialsProvider->get();
        }

        if (empty($config['bucket']) || empty($config['region'])) {
            throw new FeedStorageUnavailableException(
                __('Remote storage names no bucket or region, so the feeds cannot be read from it.')
            );
        }

        if (!empty($config['http_handler'])) {
            $config['http_handler'] = $this->objectManager->create($config['http_handler'])($config);
        }

        if (!empty($config['path_style'])) {
            $config['use_path_style_endpoint'] = (bool) $config['path_style'];
        }

        return $config;
    }
}
