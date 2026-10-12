<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Unit\Plugin\RemoteStorage;

use Magento\RemoteStorage\Model\Synchronizer;
use MageOS\Aeo\Model\Feed\Remote\FeedCopier;
use MageOS\Aeo\Plugin\RemoteStorage\CopyFeedsOnSynchronize;
use PHPUnit\Framework\TestCase;

/**
 * `bin/magento remote-storage:sync` lists the feeds it copies after the media it copies.
 */
class CopyFeedsOnSynchronizeTest extends TestCase
{
    /**
     * @return void
     */
    public function testTheFeedsFollowTheMedia(): void
    {
        $copier = $this->createStub(FeedCopier::class);
        $copier->method('copy')->willReturnCallback(static function (): \Generator {
            yield 'mageos_aeo/store_1/llms.txt';
        });
        $media = (static function (): \Generator {
            yield 'catalog/product/a.jpg';
        })();

        $synchronized = (new CopyFeedsOnSynchronize($copier))->afterExecute(
            $this->createStub(Synchronizer::class),
            $media
        );

        $this->assertSame(
            ['catalog/product/a.jpg', 'mageos_aeo/store_1/llms.txt'],
            iterator_to_array($synchronized, false)
        );
    }
}
