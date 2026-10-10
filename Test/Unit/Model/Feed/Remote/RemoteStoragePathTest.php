<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Unit\Model\Feed\Remote;

use Magento\Framework\Exception\LocalizedException;
use MageOS\Aeo\Model\Feed\Remote\RemoteStoragePath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The storage directory setting as a path in remote storage, from its root.
 */
class RemoteStoragePathTest extends TestCase
{
    /**
     * @dataProvider settings
     * @param string $configured
     * @param string|null $path
     * @return void
     */
    #[DataProvider('settings')]
    public function testTheSettingIsTakenFromTheRoot(string $configured, ?string $path): void
    {
        $remoteStoragePath = new RemoteStoragePath();

        $this->assertSame($path, $remoteStoragePath->pathFor($configured));
        if ($path === null) {
            $this->expectException(LocalizedException::class);
        }
        $remoteStoragePath->validate($configured);
    }

    /**
     * @return array<string,array{string,string|null}>
     */
    public static function settings(): array
    {
        return [
            'none: the default'            => ['', 'mageos_aeo'],
            'blank: the default'           => ['  ', 'mageos_aeo'],
            'an absolute path'             => ['/mnt/shared/feeds', 'mnt/shared/feeds'],
            'slashes around it'            => ['/mnt/shared/feeds/', 'mnt/shared/feeds'],
            'a relative path'              => ['feeds/site-a', 'feeds/site-a'],
            'dots and underscores'         => ['/srv/feeds_v2/site.a', 'srv/feeds_v2/site.a'],
            'an empty part'                => ['/mnt//feeds', null],
            'a parent part'                => ['/mnt/../etc', null],
            'a current part'               => ['/mnt/./feeds', null],
            'a hidden part'                => ['/mnt/.hidden', null],
            'a space'                      => ['/mnt/my feeds', null],
            'a backslash'                  => ['mnt\\feeds', null],
        ];
    }
}
