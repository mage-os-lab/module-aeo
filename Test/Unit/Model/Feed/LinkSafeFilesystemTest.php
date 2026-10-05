<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Unit\Model\Feed;

// phpcs:disable Magento2.Functions.DiscouragedFunction -- the test builds links and checks the disk directly

use Magento\Framework\Exception\FileSystemException;
use MageOS\Aeo\Model\Feed\LinkSafeFilesystem;
use PHPUnit\Framework\TestCase;

/**
 * Issue #2: no feed storage operation follows a symbolic link.
 *
 * On real directories: what a link does is the filesystem's behaviour, which no stub would show.
 * Every link points into "outside", whose files must survive every test.
 */
class LinkSafeFilesystemTest extends TestCase
{
    /**
     * The test's own directory: "storage" and "outside" live in it.
     *
     * @var string
     */
    private string $base = '';

    /**
     * @var string
     */
    private string $storage = '';

    /**
     * @var string
     */
    private string $outside = '';

    protected function setUp(): void
    {
        $this->base    = (string) realpath((string) sys_get_temp_dir()) . '/mageos-aeo-links-' . uniqid();
        $this->storage = $this->base . '/storage';
        $this->outside = $this->base . '/outside';
        mkdir($this->storage, 0o750, true);
        mkdir($this->outside . '/nested', 0o750, true);
        file_put_contents($this->outside . '/secret.txt', 'outside');
        file_put_contents($this->outside . '/nested/deeper.txt', 'nested');
    }

    protected function tearDown(): void
    {
        $this->remove($this->base);
    }

    public function testTypeLooksAtTheEntryNotAtWhatALinkPointsTo(): void
    {
        $filesystem = new LinkSafeFilesystem();
        symlink($this->outside, $this->storage . '/to-directory');
        symlink($this->outside . '/secret.txt', $this->storage . '/to-file');
        symlink($this->outside . '/gone', $this->storage . '/dangling');

        $this->assertSame(LinkSafeFilesystem::DIRECTORY, $filesystem->type($this->storage));
        $this->assertSame(LinkSafeFilesystem::FILE, $filesystem->type($this->outside . '/secret.txt'));
        $this->assertSame(LinkSafeFilesystem::LINK, $filesystem->type($this->storage . '/to-directory'));
        $this->assertSame(LinkSafeFilesystem::LINK, $filesystem->type($this->storage . '/to-file'));
        $this->assertSame(LinkSafeFilesystem::LINK, $filesystem->type($this->storage . '/dangling'));
        $this->assertSame(LinkSafeFilesystem::MISSING, $filesystem->type($this->storage . '/none'));
    }

    public function testAStorageDirectoryMustBeARealDirectoryNotEveryUserCanWriteTo(): void
    {
        $filesystem = new LinkSafeFilesystem();
        symlink($this->outside, $this->storage . '/link');
        mkdir($this->storage . '/open');
        chmod($this->storage . '/open', 0o777);
        mkdir($this->storage . '/group');
        chmod($this->storage . '/group', 0o770);
        mkdir($this->storage . '/readable');
        chmod($this->storage . '/readable', 0o755);

        $this->assertTrue($filesystem->isSafeDirectory($this->storage));
        $this->assertTrue($filesystem->isSafeDirectory($this->storage . '/group'), 'group-writable');
        $this->assertTrue($filesystem->isSafeDirectory($this->storage . '/readable'), 'world-readable');
        $this->assertFalse($filesystem->isSafeDirectory($this->storage . '/link'), 'a link');
        $this->assertFalse($filesystem->isSafeDirectory($this->storage . '/open'), 'world-writable');
        $this->assertFalse($filesystem->isSafeDirectory($this->storage . '/none'), 'missing');

        $this->expectException(FileSystemException::class);
        $this->expectExceptionMessage('symbolic link');
        $filesystem->assertDirectory($this->storage . '/link');
    }

    public function testEnsureDirectoryCreatesItOrConvergesAnExistingOneOnTheMode(): void
    {
        $filesystem = new LinkSafeFilesystem();
        mkdir($this->storage . '/existing');
        chmod($this->storage . '/existing', 0o777);

        $filesystem->ensureDirectory($this->storage . '/new', 0o750);
        $filesystem->ensureDirectory($this->storage . '/existing', 0o750);

        $this->assertSame(0o750, fileperms($this->storage . '/new') & 0o777);
        $this->assertSame(0o750, fileperms($this->storage . '/existing') & 0o777);
    }

    public function testEnsureDirectoryRefusesALinkAndLeavesItsTargetAlone(): void
    {
        $filesystem = new LinkSafeFilesystem();
        symlink($this->outside, $this->storage . '/store_1');
        $mode = fileperms($this->outside) & 0o777;

        try {
            $filesystem->ensureDirectory($this->storage . '/store_1', 0o700);
            $this->fail('A link was accepted as a directory.');
        } catch (FileSystemException) {
            // Refused.
        }

        $this->assertSame($mode, fileperms($this->outside) & 0o777, 'The target keeps its mode.');
    }

    public function testOpenFileReadsARegularFileOnly(): void
    {
        $filesystem = new LinkSafeFilesystem();
        file_put_contents($this->storage . '/llms.txt', 'feed');
        symlink($this->outside . '/secret.txt', $this->storage . '/linked.txt');

        $file = $filesystem->openFile($this->storage . '/llms.txt');
        $this->assertNotNull($file);
        $this->assertSame(4, $file->size());
        $this->assertSame('feed', $file->contents());

        $this->assertNull($filesystem->openFile($this->storage . '/linked.txt'), 'a link');
        $this->assertNull($filesystem->openFile($this->storage), 'a directory');
        $this->assertNull($filesystem->openFile($this->storage . '/none'), 'missing');
    }

    public function testCreateFileNeverOpensWhatIsAlreadyThere(): void
    {
        $filesystem = new LinkSafeFilesystem();
        file_put_contents($this->storage . '/existing', 'kept');
        symlink($this->outside . '/created-through-link', $this->storage . '/dangling');

        foreach (['existing', 'dangling'] as $name) {
            try {
                $filesystem->createFile($this->storage . '/' . $name, 0o640);
                $this->fail("{$name} was opened.");
            } catch (FileSystemException) {
                // Refused.
            }
        }

        $this->assertSame('kept', file_get_contents($this->storage . '/existing'));
        $this->assertFileDoesNotExist($this->outside . '/created-through-link');
    }

    public function testCreateFileAppliesTheModeBeforeAnythingIsWritten(): void
    {
        $filesystem = new LinkSafeFilesystem();

        $handle = $filesystem->createFile($this->storage . '/.new.tmp', 0o640);
        $this->assertSame(0o640, fileperms($this->storage . '/.new.tmp') & 0o777);
        $filesystem->write($handle, 'feed');
        $filesystem->close($handle);

        $this->assertSame('feed', file_get_contents($this->storage . '/.new.tmp'));
    }

    public function testRenameReplacesALinkAtTheDestination(): void
    {
        $filesystem = new LinkSafeFilesystem();
        file_put_contents($this->storage . '/.new.tmp', 'feed');
        symlink($this->outside . '/secret.txt', $this->storage . '/llms.txt');

        $filesystem->rename($this->storage . '/.new.tmp', $this->storage . '/llms.txt');

        $this->assertFalse(is_link($this->storage . '/llms.txt'));
        $this->assertSame('feed', file_get_contents($this->storage . '/llms.txt'));
        $this->assertOutsideUntouched();
    }

    public function testNamesListsARealDirectoryOnly(): void
    {
        $filesystem = new LinkSafeFilesystem();
        mkdir($this->storage . '/store_1');
        symlink($this->outside, $this->storage . '/store_2');

        $names = $filesystem->names($this->storage);
        sort($names);

        $this->assertSame(['store_1', 'store_2'], $names);
        $this->assertSame([], $filesystem->names($this->storage . '/store_2'), 'a link to a directory');
    }

    public function testRemoveFromRemovesFilesAndLinksButNotDirectories(): void
    {
        $filesystem = new LinkSafeFilesystem();
        file_put_contents($this->storage . '/llms.txt', 'feed');
        symlink($this->outside . '/secret.txt', $this->storage . '/linked.txt');
        mkdir($this->storage . '/directory');

        foreach (['llms.txt', 'linked.txt', 'directory', 'none'] as $name) {
            $filesystem->removeFrom($this->storage, $name);
        }

        $this->assertFileDoesNotExist($this->storage . '/llms.txt');
        $this->assertFalse(is_link($this->storage . '/linked.txt'));
        $this->assertDirectoryExists($this->storage . '/directory');
        $this->assertOutsideUntouched();
    }

    public function testRemoveFromRefusesADirectoryThatIsALink(): void
    {
        // The directory is the path's parent: as a link, it would take the removal elsewhere.
        symlink($this->outside, $this->storage . '/store_1');

        try {
            (new LinkSafeFilesystem())->removeFrom($this->storage . '/store_1', 'secret.txt');
            $this->fail('A removal through a linked directory was not refused.');
        } catch (FileSystemException) {
            // Refused.
        }

        $this->assertOutsideUntouched();
    }

    public function testRemoveDirectoryRemovesALinkItselfAndNotItsTarget(): void
    {
        symlink($this->outside, $this->storage . '/store_999');

        (new LinkSafeFilesystem())->removeDirectory($this->storage . '/store_999');

        $this->assertFalse(is_link($this->storage . '/store_999'));
        $this->assertOutsideUntouched();
    }

    public function testRemoveDirectoryRemovesFilesAndLinksWithoutFollowingThem(): void
    {
        mkdir($this->storage . '/store_1');
        file_put_contents($this->storage . '/store_1/llms.txt', 'feed');
        symlink($this->outside, $this->storage . '/store_1/inner');
        symlink($this->outside . '/secret.txt', $this->storage . '/store_1/llms-full.txt');

        (new LinkSafeFilesystem())->removeDirectory($this->storage . '/store_1');

        $this->assertFileDoesNotExist($this->storage . '/store_1');
        $this->assertOutsideUntouched();
    }

    public function testRemoveDirectoryRefusesOneHoldingADirectoryAndRemovesNothing(): void
    {
        mkdir($this->storage . '/store_1/unexpected', 0o750, true);
        file_put_contents($this->storage . '/store_1/llms.txt', 'feed');

        try {
            (new LinkSafeFilesystem())->removeDirectory($this->storage . '/store_1');
            $this->fail('A directory holding a directory was removed.');
        } catch (FileSystemException) {
            // Refused.
        }

        $this->assertFileExists($this->storage . '/store_1/llms.txt');
    }

    /**
     * @doesNotPerformAssertions
     */
    public function testRemoveDirectoryIgnoresAMissingOne(): void
    {
        (new LinkSafeFilesystem())->removeDirectory($this->storage . '/store_404');
    }

    /**
     * Assert that the outside directory holds exactly what setUp() put there.
     *
     * @return void
     */
    private function assertOutsideUntouched(): void
    {
        $this->assertSame('outside', file_get_contents($this->outside . '/secret.txt'));
        $this->assertSame('nested', file_get_contents($this->outside . '/nested/deeper.txt'));
    }

    /**
     * Remove a path without following links.
     *
     * @param string $path
     * @return void
     */
    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        chmod($path, 0o750);
        foreach ((array) scandir($path) as $name) {
            if ($name !== '.' && $name !== '..') {
                $this->remove($path . '/' . $name);
            }
        }
        rmdir($path);
    }
}
