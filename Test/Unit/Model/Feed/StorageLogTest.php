<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Unit\Model\Feed;

use Magento\Framework\App\CacheInterface;
use MageOS\Aeo\Model\Feed\StorageLog;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A storage problem every request meets is logged once in a while, not once per request.
 */
class StorageLogTest extends TestCase
{
    /**
     * What the cache holds, by key.
     *
     * @var array<string,string>
     */
    private array $cached = [];

    /**
     * The lifetimes the cache was given.
     *
     * @var int[]
     */
    private array $lifetimes = [];

    /**
     * The same error is logged once within the interval; another message or context is logged too.
     *
     * @return void
     */
    public function testTheSameErrorIsLoggedOnceWithinTheInterval(): void
    {
        $logged = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('notice');
        $logger->method('error')->willReturnCallback(
            static function (string $message, array $context) use (&$logged): void {
                $logged[] = [$message, $context];
            }
        );
        $log = new StorageLog($logger, $this->cache());

        $log->error('cannot be read', ['path' => 'a']);
        $log->error('cannot be read', ['path' => 'a']);
        $log->error('cannot be read', ['path' => 'b']);
        $log->error('another problem', ['path' => 'a']);

        $this->assertSame(
            [
                ['cannot be read', ['path' => 'a']],
                ['cannot be read', ['path' => 'b']],
                ['another problem', ['path' => 'a']],
            ],
            $logged
        );
        $this->assertSame([StorageLog::INTERVAL, StorageLog::INTERVAL, StorageLog::INTERVAL], $this->lifetimes);
    }

    /**
     * Notices too.
     *
     * @return void
     */
    public function testTheSameNoticeIsLoggedOnceWithinTheInterval(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');
        $logger->expects($this->once())->method('notice')->with('read whole', ['driver' => 'file']);
        $log = new StorageLog($logger, $this->cache());

        $log->notice('read whole', ['driver' => 'file']);
        $log->notice('read whole', ['driver' => 'file']);
    }

    /**
     * A cache that cannot be used does not keep a problem from being logged.
     *
     * @return void
     */
    public function testACacheThatCannotBeUsedDoesNotKeepAProblemFromTheLog(): void
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willThrowException(new \RuntimeException('cache down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))->method('error');
        $logger->expects($this->once())->method('notice');
        $log = new StorageLog($logger, $cache);

        $log->error('cannot be read', []);
        $log->error('cannot be read', []);
        $log->notice('read whole', []);
    }

    /**
     * A cache that keeps what it is given.
     *
     * @return CacheInterface
     */
    private function cache(): CacheInterface
    {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn (string $key): string|false => $this->cached[$key] ?? false);
        $cache->method('save')->willReturnCallback(
            function (string $data, string $key, array $tags = [], ?int $lifetime = null): bool {
                $this->cached[$key] = $data;
                $this->lifetimes[]  = (int) $lifetime;

                return true;
            }
        );

        return $cache;
    }
}
