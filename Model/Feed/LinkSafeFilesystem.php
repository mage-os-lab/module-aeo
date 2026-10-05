<?php

declare(strict_types=1);

namespace MageOS\Aeo\Model\Feed;

// phpcs:disable Magento2.Functions.DiscouragedFunction -- lstat, symlink-aware operations the framework's driver lacks
// phpcs:disable Generic.PHP.NoSilencedErrors.Discouraged -- a failure is reported by the return value, as in
// the framework's driver; unsilenced, Magento's error handler throws on the warning first

use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Phrase;

/**
 * Filesystem operations for the feed storage directories that never follow a symbolic link.
 *
 * Magento's filesystem driver follows links: it reads, writes and deletes through them, and its
 * recursive delete descends into a linked directory and empties the target. Feed storage can sit
 * where other processes write too — a shared mount, a var/ several users share — so a link planted
 * there must not take a read, a write or a deletion outside it. Every method here looks at the
 * entry itself (lstat) and refuses a link, or removes the link itself, rather than following it.
 * The driver has neither lstat nor is_link, so these are native calls, kept to this one class.
 *
 * What PHP cannot close: it has no openat() or unlinkat(), and it resolves links in a path itself
 * before opening it, so a path is checked and then used, and a process able to rename entries in a
 * storage directory in between can still race an operation. A read compares the file it opened with
 * the one it checked; a write and a deletion check the directory again just before acting. Keeping
 * the storage directories writable only by the users that run Magento closes the rest
 * (docs/feeds.md), which is why a directory every user can write to is refused.
 */
class LinkSafeFilesystem
{
    public const MISSING   = 'missing';
    public const LINK      = 'link';
    public const FILE      = 'file';
    public const DIRECTORY = 'directory';
    public const OTHER     = 'other';

    private const TYPE_BITS      = 0o170000;
    private const TYPE_LINK      = 0o120000;
    private const TYPE_FILE      = 0o100000;
    private const TYPE_DIRECTORY = 0o040000;
    private const WORLD_WRITABLE = 0o002;

    /**
     * What is at a path, without following a link there.
     *
     * Read from the disk on every call: the answer can change between two calls.
     *
     * @param string $path
     * @return string One of the type constants
     * @phpstan-impure
     */
    public function type(string $path): string
    {
        $stat = $this->lstat($path);
        if ($stat === null) {
            return self::MISSING;
        }

        return match ($stat['mode'] & self::TYPE_BITS) {
            self::TYPE_LINK      => self::LINK,
            self::TYPE_FILE      => self::FILE,
            self::TYPE_DIRECTORY => self::DIRECTORY,
            default              => self::OTHER,
        };
    }

    /**
     * Require a real directory that not every user can write to.
     *
     * @param string $path
     * @throws FileSystemException
     * @return void
     */
    public function assertDirectory(string $path): void
    {
        $type = $this->type($path);
        if ($type === self::LINK) {
            throw new FileSystemException(new Phrase(
                'Feed storage does not follow symbolic links, and "%1" is one.',
                [$path]
            ));
        }
        if ($type !== self::DIRECTORY) {
            throw new FileSystemException(new Phrase('The feed storage directory "%1" does not exist.', [$path]));
        }
        if ((($this->lstat($path)['mode'] ?? 0) & self::WORLD_WRITABLE) !== 0) {
            throw new FileSystemException(new Phrase(
                'The feed storage directory "%1" can be written to by every user. Allow only the users'
                . ' that run Magento.',
                [$path]
            ));
        }
    }

    /**
     * Whether a path is a real directory that not every user can write to.
     *
     * @param string $path
     * @return bool
     */
    public function isSafeDirectory(string $path): bool
    {
        try {
            $this->assertDirectory($path);
        } catch (FileSystemException) {
            return false;
        }

        return true;
    }

    /**
     * Create a directory unless it exists, then require it to be a real one not every user can write to.
     *
     * The mode, when given, is applied to an existing directory as well, so directories made before
     * this policy or by another tool converge on it. Changing the mode of a directory another user
     * owns fails and is ignored; the check after it then decides.
     *
     * @param string $path
     * @param int|null $mode
     * @throws FileSystemException
     * @return void
     */
    public function ensureDirectory(string $path, ?int $mode): void
    {
        // mkdir() does not follow a link at the path itself: it fails when anything is there.
        if ($this->type($path) === self::MISSING
            && !@mkdir($path, $mode ?? 0o750)
            && $this->type($path) !== self::DIRECTORY
        ) {
            throw new FileSystemException(new Phrase('The directory "%1" could not be created.', [$path]));
        }
        if ($mode !== null && $this->type($path) === self::DIRECTORY) {
            @chmod($path, $mode);
        }

        $this->assertDirectory($path);
    }

    /**
     * Open a regular file for reading, or null when the path is missing, a link or anything else.
     *
     * The opened file is compared with the one checked (device and inode), so a link swapped in
     * between the check and the open is refused rather than read.
     *
     * @param string $path
     * @return FeedFile|null
     */
    public function openFile(string $path): ?FeedFile
    {
        $checked = $this->lstat($path);
        if ($checked === null || ($checked['mode'] & self::TYPE_BITS) !== self::TYPE_FILE) {
            return null;
        }

        clearstatcache(true, \dirname($path));
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        $opened = fstat($handle);
        if ($opened === false || $opened['dev'] !== $checked['dev'] || $opened['ino'] !== $checked['ino']) {
            fclose($handle);
            return null;
        }

        return new FeedFile($handle, (int) $opened['size']);
    }

    /**
     * Create a new file for writing, with the given mode.
     *
     * It fails when anything is at the path already, so it never opens an existing file. The path is
     * checked first: PHP resolves a link in a path before it opens it, so the exclusive mode on its
     * own creates a dangling link's target rather than failing. The mode is applied before anything
     * is written; chmod() takes a path, so the created file is compared with the path afterwards.
     *
     * @param string $path
     * @param int $mode
     * @throws FileSystemException
     * @return resource
     */
    public function createFile(string $path, int $mode)
    {
        // PHP also keeps resolved directories for a while; the parent's must be read afresh.
        clearstatcache(true, \dirname($path));
        $handle = $this->type($path) === self::MISSING ? @fopen($path, 'xb') : false;
        if ($handle === false) {
            throw new FileSystemException(new Phrase('The file "%1" could not be created.', [$path]));
        }

        @chmod($path, $mode);
        $created = fstat($handle);
        $atPath  = $this->lstat($path);
        if ($created === false || $atPath === null
            || $created['dev'] !== $atPath['dev'] || $created['ino'] !== $atPath['ino']
        ) {
            fclose($handle);
            throw new FileSystemException(new Phrase(
                'The file "%1" was replaced while it was being created.',
                [$path]
            ));
        }

        return $handle;
    }

    /**
     * Write all of a string to an open file.
     *
     * @param resource $handle
     * @param string $content
     * @throws FileSystemException
     * @return void
     */
    public function write($handle, string $content): void
    {
        $length = \strlen($content);
        $offset = 0;
        while ($offset < $length) {
            $written = fwrite($handle, substr($content, $offset));
            if ($written === false || $written === 0) {
                throw new FileSystemException(new Phrase('A feed file could not be written.'));
            }
            $offset += $written;
        }
    }

    /**
     * Close an open file, making sure what was written reached it.
     *
     * @param resource $handle
     * @throws FileSystemException
     * @return void
     */
    public function close($handle): void
    {
        if (!fclose($handle)) {
            throw new FileSystemException(new Phrase('A feed file could not be written.'));
        }
    }

    /**
     * Rename an entry. A link at the destination is replaced, never written through.
     *
     * @param string $from
     * @param string $to
     * @throws FileSystemException
     * @return void
     */
    public function rename(string $from, string $to): void
    {
        if (!@rename($from, $to)) {
            throw new FileSystemException(new Phrase('The file "%1" could not be put in place.', [$to]));
        }
    }

    /**
     * The names in a real directory; none for anything else, a link to a directory included.
     *
     * @param string $directory
     * @return string[]
     */
    public function names(string $directory): array
    {
        if ($this->type($directory) !== self::DIRECTORY) {
            return [];
        }

        $names = [];
        foreach (@scandir($directory) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
                $names[] = (string) $name;
            }
        }

        return $names;
    }

    /**
     * Remove a file or a link from a directory; a real directory of that name is left alone.
     *
     * The directory is checked first: a link put in its place would take the removal elsewhere.
     *
     * @param string $directory
     * @param string $name
     * @throws FileSystemException
     * @return void
     */
    public function removeFrom(string $directory, string $name): void
    {
        $this->assertDirectory($directory);
        $path = $directory . '/' . $name;
        $type = $this->type($path);
        if ($type === self::MISSING || $type === self::DIRECTORY) {
            return;
        }
        if (!@unlink($path)) {
            throw new FileSystemException(new Phrase('The file "%1" could not be deleted.', [$path]));
        }
    }

    /**
     * Remove what feed storage put in a store directory, and the directory once nothing else is in it.
     *
     * Only entries matching the patterns — the file names feed storage writes — are removed, as
     * files or links, never followed. Anything else was put there by something else and stays, and
     * so does the directory (issue #6). A link in place of the directory is removed itself.
     *
     * @param string $path
     * @param string[] $patterns Glob patterns of the names feed storage writes
     * @throws FileSystemException
     * @return bool Whether nothing is left at the path
     */
    public function removeOwned(string $path, array $patterns): bool
    {
        $type = $this->type($path);
        if ($type === self::MISSING) {
            return true;
        }
        if ($type !== self::DIRECTORY) {
            if (!@unlink($path)) {
                throw new FileSystemException(new Phrase('The file "%1" could not be deleted.', [$path]));
            }
            return true;
        }

        $left = false;
        foreach ($this->names($path) as $name) {
            if ($this->matchesAny($name, $patterns)) {
                $this->removeFrom($path, $name);
                $left = $left || $this->type($path . '/' . $name) !== self::MISSING;
            } else {
                $left = true;
            }
        }
        if ($left) {
            return false;
        }
        if (!@rmdir($path)) {
            throw new FileSystemException(new Phrase('The directory "%1" could not be deleted.', [$path]));
        }

        return true;
    }

    /**
     * Whether a name matches one of the glob patterns.
     *
     * @param string $name
     * @param string[] $patterns
     * @return bool
     */
    private function matchesAny(string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (fnmatch($pattern, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The status of the entry itself, freshly read, or null when nothing is there.
     *
     * @param string $path
     * @return array<int|string, int>|null
     */
    private function lstat(string $path): ?array
    {
        // PHP caches the last status it read, and resolved paths: neither may answer for this one.
        clearstatcache(true, $path);
        $stat = @lstat($path);

        return $stat === false ? null : $stat;
    }
}
