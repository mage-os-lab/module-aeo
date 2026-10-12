<?php

declare(strict_types=1);

namespace MageOS\Aeo\Model\Feed\Remote;

use Aws\Exception\AwsException;
use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Psr7\StreamWrapper;
use MageOS\Aeo\Exception\FeedStorageUnavailableException;
use MageOS\Aeo\Model\Feed\FeedFile;

/**
 * Opens a feed in S3 as a stream: the object's body is read as it is sent, never held whole.
 *
 * The body comes back unread (`@http` stream), so FeedDelivery serves a large llms.jsonl in pieces
 * as it serves one from local disk, whatever its size.
 */
class S3FeedReader implements RemoteFeedReaderInterface
{
    /**
     * @param S3ClientProvider $s3ClientProvider
     */
    public function __construct(
        private readonly S3ClientProvider $s3ClientProvider
    ) {
    }

    /**
     * @inheritDoc
     */
    public function open(string $path): ?FeedFile
    {
        try {
            $result = $this->s3ClientProvider->client()->getObject([
                'Bucket' => $this->s3ClientProvider->bucket(),
                'Key'    => $this->s3ClientProvider->key($path),
                '@http'  => ['stream' => true],
            ]);
        } catch (S3Exception $e) {
            if ($e->getStatusCode() === 404) {
                return null;
            }
            throw $this->unavailable($e);
        } catch (FeedStorageUnavailableException $e) {
            throw $e;
        } catch (\Exception $e) {
            // Not an S3 error: credentials the SDK could not get, for one, come as its own exception.
            throw $this->unavailable($e);
        }

        $body = $result['Body'];

        return new FeedFile(
            StreamWrapper::getResource($body),
            (int) ($result['ContentLength'] ?? $body->getSize())
        );
    }

    /**
     * Remote storage that answered with an error, or could not be asked.
     *
     * S3's own message when it gave one, as the SDK's wraps it in request details.
     *
     * @param \Exception $e
     * @return FeedStorageUnavailableException
     */
    private function unavailable(\Exception $e): FeedStorageUnavailableException
    {
        $message = $e instanceof AwsException ? ($e->getAwsErrorMessage() ?: $e->getMessage()) : $e->getMessage();

        return new FeedStorageUnavailableException(__('Remote storage could not be read: %1', $message), $e);
    }
}
