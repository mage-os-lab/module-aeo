<?php

declare(strict_types=1);

namespace MageOS\Aeo\Model\Feed;

use Magento\Framework\App\CacheInterface;
use Psr\Log\LoggerInterface;

/**
 * Logs a feed storage problem at most once every INTERVAL seconds per message.
 *
 * Every request for a feed meets a storage problem until someone fixes it; once a few minutes says
 * as much as once a request. Shared by the local and the remote storage backends.
 */
class StorageLog
{
    /**
     * Seconds between two log entries about the same storage problem.
     */
    public const INTERVAL = 300;

    /**
     * @param LoggerInterface $logger
     * @param CacheInterface $cache Remembers which storage problems were logged lately
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly CacheInterface  $cache
    ) {
    }

    /**
     * Log the problem as an error, unless the same was logged within the interval.
     *
     * @param string $message
     * @param mixed[] $context
     * @return void
     */
    public function error(string $message, array $context): void
    {
        if ($this->loggedLately($message, $context)) {
            return;
        }

        $this->logger->error($message, $context);
    }

    /**
     * Log the matter as a notice, unless the same was logged within the interval.
     *
     * @param string $message
     * @param mixed[] $context
     * @return void
     */
    public function notice(string $message, array $context): void
    {
        if ($this->loggedLately($message, $context)) {
            return;
        }

        $this->logger->notice($message, $context);
    }

    /**
     * Whether the same message was logged within the interval; if not, remember that it is now.
     *
     * A cache that cannot be read or written does not keep the message from being logged.
     *
     * @param string $message
     * @param mixed[] $context
     * @return bool
     */
    private function loggedLately(string $message, array $context): bool
    {
        $key = 'mageos_aeo_storage_log_' . sha1($message . "\0" . json_encode($context));
        try {
            if ($this->cache->load($key) !== false) {
                return true;
            }
            $this->cache->save('1', $key, [], self::INTERVAL);
        } catch (\Exception) { // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock.DetectedCatch -- log it anyway
        }

        return false;
    }
}
