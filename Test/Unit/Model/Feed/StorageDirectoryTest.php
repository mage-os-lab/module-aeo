<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Unit\Model\Feed;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use MageOS\Aeo\Exception\FeedStorageUnavailableException;
use MageOS\Aeo\Model\Feed\StorageDirectory;
use PHPUnit\Framework\TestCase;

/**
 * Review finding S2: an administrator could point feed writes at any directory PHP can reach.
 *
 * Real directories, because the rules are about resolved paths — a symlink out of var/ has to be
 * seen for what it points at, which no amount of stubbing would exercise.
 */
class StorageDirectoryTest extends TestCase
{
    /**
     * A throwaway directory holding both the installation and a mount beside it.
     *
     * @var string
     */
    private string $base = '';

    /**
     * A throwaway installation root for the test.
     *
     * @var string
     */
    private string $root = '';

    /**
     * A mount outside the installation, as a multi-server deployment would have.
     *
     * @var string
     */
    private string $outside = '';

    protected function setUp(): void
    {
        $this->base    = (string) realpath((string) sys_get_temp_dir()) . '/mageos-seo-s2-' . uniqid();
        $this->root    = $this->base . '/install';
        // Beside the installation, not inside it: that is what a shared mount is.
        $this->outside = $this->base . '/mnt/feeds';

        mkdir($this->root . '/var/mageos_aeo', 0o775, true);
        mkdir($this->root . '/app/etc', 0o775, true);
        mkdir($this->root . '/pub/media', 0o775, true);
        mkdir($this->root . '/var/.hidden', 0o775, true);
        mkdir($this->outside . '/site-a', 0o775, true);
    }

    protected function tearDown(): void
    {
        (new FileDriver())->deleteDirectory($this->base);
    }

    public function testNoConfiguredDirectoryIsAllowed(): void
    {
        // The default: var/mageos_aeo, which needs no configuration.
        $this->assertTrue($this->storageDirectory()->isAllowed(''));
        $this->assertTrue($this->storageDirectory()->isAllowed('   '));
    }

    public function testADirectoryInsideVarIsAllowed(): void
    {
        $this->assertTrue($this->storageDirectory()->isAllowed($this->root . '/var/mageos_aeo'));
    }

    public function testEveryOtherInstallationDirectoryIsRefused(): void
    {
        foreach (['/app', '/app/etc', '/pub', '/pub/media', ''] as $suffix) {
            $this->assertFalse(
                $this->storageDirectory()->isAllowed($this->root . $suffix),
                $suffix === '' ? 'the installation root' : $suffix
            );
        }
    }

    public function testAHiddenDirectoryIsRefusedEvenInsideVar(): void
    {
        $this->assertFalse($this->storageDirectory()->isAllowed($this->root . '/var/.hidden'));
    }

    public function testARelativePathOrOneWithParentSegmentsIsRefused(): void
    {
        $this->assertFalse($this->storageDirectory()->isAllowed('var/mageos_aeo'));
        $this->assertFalse($this->storageDirectory()->isAllowed($this->root . '/var/../app'));
    }

    public function testADirectoryThatDoesNotExistIsRefused(): void
    {
        $this->assertFalse($this->storageDirectory()->isAllowed($this->root . '/var/not-created'));
    }

    public function testASymlinkOutOfVarIsJudgedByWhereItPoints(): void
    {
        symlink($this->root . '/app/etc', $this->root . '/var/escape');

        $this->assertFalse(
            $this->storageDirectory()->isAllowed($this->root . '/var/escape'),
            'A link inside var/ must not stand for a target outside it.'
        );
    }

    public function testVarItselfIsRefused(): void
    {
        // Issue #6: var/ holds everything else's files too, and cleanup works by directory name.
        $this->assertFalse($this->storageDirectory()->isAllowed($this->root . '/var'));
    }

    public function testADirectoryThisHostCanOnlyReadIsAllowed(): void
    {
        // Issue #7: a web host reads the shared directory the cron host writes. Saving the setting
        // there must work too.
        mkdir($this->root . '/var/shared-read-only');
        chmod($this->root . '/var/shared-read-only', 0o550);

        $this->assertTrue($this->storageDirectory()->isAllowed($this->root . '/var/shared-read-only'));
        $this->storageDirectory()->validate($this->root . '/var/shared-read-only');

        chmod($this->root . '/var/shared-read-only', 0o750);
    }

    public function testAVisibleAliasOfAHiddenDirectoryIsRefused(): void
    {
        // Issue #2: hidden directories were looked for in the path as typed only, so a link with a
        // visible name was accepted for var/.hidden.
        symlink($this->root . '/var/.hidden', $this->root . '/var/visible');

        $this->assertFalse($this->storageDirectory()->isAllowed($this->root . '/var/visible'));
    }

    public function testADirectoryEveryUserCanWriteToIsRefused(): void
    {
        // Issue #2: whoever can write to the storage directory can put a link in it.
        mkdir($this->root . '/var/open');
        chmod($this->root . '/var/open', 0o777);

        $this->assertFalse($this->storageDirectory()->isAllowed($this->root . '/var/open'));
    }

    public function testAGroupWritableOrWorldReadableDirectoryIsAllowed(): void
    {
        // The web server and the cron or queue user may differ and share a group; 0755 is the
        // commonest mode of all, and only writing is refused.
        foreach (['shared' => 0o770, 'readable' => 0o755] as $name => $mode) {
            mkdir($this->root . '/var/' . $name);
            chmod($this->root . '/var/' . $name, $mode);

            $this->assertTrue($this->storageDirectory()->isAllowed($this->root . '/var/' . $name), $name);
        }
    }

    public function testThePermittedDirectoryIsGivenResolved(): void
    {
        // Storage works on the resolved path, so a link swapped after the check cannot move it.
        symlink($this->root . '/var/mageos_aeo', $this->root . '/var/alias');

        $read = StorageDirectory::READ;
        $this->assertSame(
            $this->root . '/var/mageos_aeo',
            $this->storageDirectory()->locate(' ' . $this->root . '/var/alias ', $read)
        );
        $this->assertNull($this->storageDirectory()->locate($this->root . '/pub', $read), 'a refused one');
        $this->assertNull($this->storageDirectory()->locate('', $read), 'the default, which has no path of its own');
    }

    public function testADirectoryMissingOnThisHostIsReportedNotRefused(): void
    {
        // Issue #7: a mount that is not there is the server's fault, not the setting's, and must
        // not send this host's feeds to its own var/.
        $this->expectException(FeedStorageUnavailableException::class);
        $this->expectExceptionMessage('does not exist');

        $this->storageDirectory()->locate($this->outside . '/not-mounted', StorageDirectory::READ);
    }

    public function testADirectoryThisHostCanOnlyReadIsReportedForWriting(): void
    {
        mkdir($this->root . '/var/read-only');
        chmod($this->root . '/var/read-only', 0o550);

        try {
            $this->assertSame(
                $this->root . '/var/read-only',
                $this->storageDirectory()->locate($this->root . '/var/read-only', StorageDirectory::READ),
                'It is served from.'
            );
            $this->expectException(FeedStorageUnavailableException::class);
            $this->expectExceptionMessage('cannot be written');
            $this->storageDirectory()->locate($this->root . '/var/read-only', StorageDirectory::WRITE);
        } finally {
            chmod($this->root . '/var/read-only', 0o750);
        }
    }

    public function testADirectoryOutsideTheInstallationIsRefusedUnlessDeclared(): void
    {
        $this->assertFalse($this->storageDirectory()->isAllowed($this->outside));
    }

    public function testADirectoryDeclaredInDeploymentConfigIsAllowed(): void
    {
        // The multi-server case: a shared mount, declared in env.php, which an admin cannot edit.
        $storageDirectory = $this->storageDirectory([$this->outside]);

        $this->assertTrue($storageDirectory->isAllowed($this->outside), 'the declared root itself');
        $this->assertTrue(
            $storageDirectory->isAllowed($this->outside . '/site-a'),
            'and anything below it'
        );
    }

    public function testADeclaredRootDoesNotOpenUpTheInstallation(): void
    {
        $storageDirectory = $this->storageDirectory([$this->outside]);

        $this->assertFalse($storageDirectory->isAllowed($this->root . '/app/etc'));
    }

    public function testDeclaringAnInstallationDirectoryInEnvPhpDoesNotMakeItUsable(): void
    {
        // An entry copied between environments, or left by a template, must not open up the
        // codebase: pub/ is web-served, and the escape hatch exists for mounts outside the
        // installation. Declaring it is ignored rather than obeyed.
        $storageDirectory = $this->storageDirectory([$this->root . '/pub']);

        $this->assertFalse($storageDirectory->isAllowed($this->root . '/pub'));
        $this->assertFalse($storageDirectory->isAllowed($this->root . '/pub/media'));
    }

    public function testDeclaringVarStillWorksBecauseVarIsAllowedAnyway(): void
    {
        $storageDirectory = $this->storageDirectory([$this->root . '/var']);

        $this->assertTrue($storageDirectory->isAllowed($this->root . '/var/mageos_aeo'));
    }

    public function testValidateExplainsTheRefusal(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/only var\//');

        $this->storageDirectory()->validate($this->root . '/pub/media');
    }

    /**
     * @param string[] $declaredRoots
     * @return StorageDirectory
     */
    private function storageDirectory(array $declaredRoots = []): StorageDirectory
    {
        $directoryList = $this->createStub(DirectoryList::class);
        $directoryList->method('getPath')->willReturnCallback(
            fn (string $code): string => $code === DirectoryList::VAR_DIR ? $this->root . '/var' : $this->root
        );

        // Only the documented env.php key answers, so a test that declares roots also pins the key.
        $deploymentConfig = $this->createStub(DeploymentConfig::class);
        $deploymentConfig->method('get')->willReturnMap([
            ['mageos_aeo/feed_storage_roots', null, $declaredRoots === [] ? null : $declaredRoots],
        ]);

        // The real driver: these rules are about what is on disk, so stubbing it would only
        // restate the expectations back to the test.
        return new StorageDirectory($directoryList, $deploymentConfig, new FileDriver());
    }
}
