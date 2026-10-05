<?php

declare(strict_types=1);

namespace MageOS\Aeo\Model\Feed;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Framework\Phrase;
use MageOS\Aeo\Exception\FeedStorageUnavailableException;

/**
 * Decides whether a configured feed storage directory may be used, and for what.
 *
 * The setting exists for multi-server deployments, where web servers and the cron host need a
 * shared mount, so it has to accept an absolute path outside the installation. Left unguarded,
 * that is an administrator writing files anywhere PHP can reach.
 *
 * Two rules, applied in this order:
 *
 *  - inside the installation, only var/ — every other standard directory (app, bin, dev,
 *    generated, lib, pub, setup, update, vendor) and the root itself are refused, so no value can
 *    reach the codebase, and no dot directory (.git, .ssh, .magento) is reachable anywhere;
 *  - outside the installation, only a root listed in env.php under mageos_aeo/feed_storage_roots,
 *    which is deployment configuration an administrator cannot edit from the admin panel.
 *
 * Paths are resolved before they are judged, so a symlink inside var/ cannot stand for a target
 * outside it, and a link with a visible name cannot stand for a hidden directory (issue #2): the
 * hidden-directory rule applies to the path as typed and as resolved. A directory every user can
 * write to is refused too, since anyone could then put a link in it. var/ itself is refused
 * (issue #6): it holds everything else's files, and feed storage keeps store directories in its
 * root. FeedStorage works on the resolved path (locate()), so a link swapped after the check does
 * not move it.
 *
 * A location the rules refuse is the setting's fault, and storage falls back to var/mageos_aeo. An
 * allowed location this host cannot use for what is asked — missing, not a directory, not
 * readable for a request, not writable for a rebuild — is the server's, and is reported instead
 * (FeedStorageUnavailableException, issue #7): a web host may only read the directory the cron
 * host writes, and a mount missing on one host must not send that host to its own var/.
 */
class StorageDirectory
{
    /**
     * The deployment-config key holding the roots an installation permits.
     */
    public const DEPLOYMENT_CONFIG_PATH = 'mageos_aeo/feed_storage_roots';

    /**
     * Using the directory to serve feeds: it must be readable.
     */
    public const READ = 'read';

    /**
     * Using the directory to write feeds: it must be writable.
     */
    public const WRITE = 'write';

    /**
     * @param DirectoryList $directoryList
     * @param DeploymentConfig $deploymentConfig
     * @param FileDriver $fileDriver
     */
    public function __construct(
        private readonly DirectoryList    $directoryList,
        private readonly DeploymentConfig $deploymentConfig,
        private readonly FileDriver       $fileDriver
    ) {
    }

    /**
     * Whether a configured value may be used to serve feeds on this host.
     *
     * An empty value is the default: var/mageos_aeo, which needs no configuration at all.
     *
     * @param string $path
     * @return bool
     */
    public function isAllowed(string $path): bool
    {
        [$refused, $unavailable] = $this->judge(trim($path), self::READ);

        return $refused === null && $unavailable === null;
    }

    /**
     * The resolved directory a configured value stands for, when it can be used for the purpose.
     *
     * @param string $path
     * @param string $purpose self::READ or self::WRITE
     * @throws FeedStorageUnavailableException When the location is allowed but this host cannot use it
     * @return string|null Null for an empty value, and for a location the rules refuse: storage then
     *                     uses var/mageos_aeo
     */
    public function locate(string $path, string $purpose): ?string
    {
        [$refused, $unavailable, $resolved] = $this->judge(trim($path), $purpose);
        if ($refused !== null || $resolved === '') {
            return null;
        }
        if ($unavailable !== null) {
            throw new FeedStorageUnavailableException($unavailable);
        }

        return $resolved;
    }

    /**
     * Check a value and explain the refusal, for the admin save path.
     *
     * The directory must be readable from where it is saved; whether it is writable is for the
     * processes that write the feeds to find out, and a rebuild that cannot write says so in the
     * admin. The admin may run on a web host that only reads the shared directory.
     *
     * @param string $path
     * @throws LocalizedException
     * @return void
     */
    public function validate(string $path): void
    {
        [$refused, $unavailable] = $this->judge(trim($path), self::READ);
        $reason = $refused ?? $unavailable;
        if ($reason !== null) {
            throw new LocalizedException($reason);
        }
    }

    /**
     * Why this path may not be used for the purpose, with the path resolved.
     *
     * @param string $path
     * @param string $purpose
     * @return array{0: Phrase|null, 1: Phrase|null, 2: string} Why the rules refuse the location,
     *                                                          why this host cannot use it, and the
     *                                                          resolved path ('' when none)
     */
    private function judge(string $path, string $purpose): array
    {
        if ($path === '') {
            return [null, null, ''];
        }

        if (!str_starts_with($path, '/')) {
            return [new Phrase('The feed storage directory must be an absolute path.'), null, ''];
        }
        if (str_contains($path, '..')) {
            return [new Phrase('The feed storage directory must not contain "..".'), null, ''];
        }
        if ($this->hasHiddenSegment($path)) {
            return [new Phrase('The feed storage directory must not contain hidden directories.'), null, ''];
        }

        // Resolve before judging: a symlink under var/ must not stand for a target outside it.
        $resolved = $this->resolve($path);
        if ($resolved === '') {
            // A mount missing on this host: nothing to judge the location by, and nothing to use.
            return [null, new Phrase('The feed storage directory does not exist: %1', [$path]), $path];
        }
        if ($this->hasHiddenSegment($resolved)) {
            return [new Phrase('The feed storage directory must not contain hidden directories.'), null, $resolved];
        }
        $location = $this->rejectLocation($resolved);
        if ($location !== null) {
            return [$location, null, $resolved];
        }
        if ($this->isWorldWritable($resolved)) {
            return [
                new Phrase(
                    'The feed storage directory can be written to by every user: %1. Allow only the users'
                    . ' that run Magento.',
                    [$path]
                ),
                null,
                $resolved,
            ];
        }

        return [null, $this->rejectUse($resolved, $path, $purpose), $resolved];
    }

    /**
     * Why this host cannot use an allowed directory for the purpose, or null when it can.
     *
     * @param string $resolved
     * @param string $path As configured, for the message
     * @param string $purpose
     * @return Phrase|null
     */
    private function rejectUse(string $resolved, string $path, string $purpose): ?Phrase
    {
        if (!$this->fileDriver->isDirectory($resolved)) {
            return new Phrase('The feed storage directory is not a directory: %1', [$path]);
        }
        if ($purpose === self::WRITE && !$this->fileDriver->isWritable($resolved)) {
            return new Phrase('The feed storage directory cannot be written to from here: %1', [$path]);
        }
        if (!$this->fileDriver->isReadable($resolved)) {
            return new Phrase('The feed storage directory cannot be read from here: %1', [$path]);
        }

        return null;
    }

    /**
     * Why a resolved directory is in a place feeds may not go, or null when they may.
     *
     * @param string $resolved
     * @return Phrase|null
     */
    private function rejectLocation(string $resolved): ?Phrase
    {
        $var = $this->realRoot(DirectoryList::VAR_DIR);
        if ($var !== '' && $resolved === $var) {
            return new Phrase(
                'var/ itself cannot be the feed storage directory, as it holds other files too. Use a'
                . ' directory inside it, such as var/mageos_aeo, or leave the setting empty.'
            );
        }
        if ($this->isWithin($resolved, $var)) {
            return null;
        }

        // The installation rule is checked before the declared roots, not after: a root declared
        // in env.php extends where feeds may go, it does not open up the codebase. Entries copied
        // between environments or left behind by a template are exactly how one would come to
        // point at pub/ — which is web-served — so such a root is ignored rather than obeyed.
        if ($this->isWithin($resolved, $this->realRoot(DirectoryList::ROOT))) {
            return new Phrase(
                'Inside the installation only var/ may be used. To store feeds elsewhere, add the'
                . ' directory to "%1" in app/etc/env.php.',
                [self::DEPLOYMENT_CONFIG_PATH]
            );
        }

        foreach ($this->permittedRoots() as $root) {
            if ($this->isWithin($resolved, $root)) {
                return null;
            }
        }

        return new Phrase(
            'The feed storage directory must be inside var/, or a directory listed under "%1" in'
            . ' app/etc/env.php.',
            [self::DEPLOYMENT_CONFIG_PATH]
        );
    }

    /**
     * Roots this installation has declared in deployment configuration.
     *
     * @return string[] Resolved absolute paths
     */
    private function permittedRoots(): array
    {
        $configured = $this->deploymentConfig->get(self::DEPLOYMENT_CONFIG_PATH);
        if (!\is_array($configured)) {
            $configured = $configured === null || $configured === '' ? [] : [$configured];
        }

        $roots = [];
        foreach ($configured as $root) {
            $resolved = $this->resolve((string) $root);
            if ($resolved !== '') {
                $roots[] = $resolved;
            }
        }

        return $roots;
    }

    /**
     * A path with symlinks and relative segments resolved, or an empty string if it does not exist.
     *
     * @param string $path
     * @return string
     */
    private function resolve(string $path): string
    {
        try {
            $resolved = $this->fileDriver->getRealPath($path);
        } catch (\Exception) {
            return '';
        }

        return \is_string($resolved) ? $resolved : '';
    }

    /**
     * Whether any directory in a path is hidden (starts with a dot).
     *
     * @param string $path
     * @return bool
     */
    private function hasHiddenSegment(string $path): bool
    {
        foreach (explode('/', trim($path, '/')) as $segment) {
            if (str_starts_with($segment, '.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether every user may write to a directory.
     *
     * @param string $resolved
     * @return bool
     */
    private function isWorldWritable(string $resolved): bool
    {
        try {
            $stat = $this->fileDriver->stat($resolved);
        } catch (\Exception) {
            return true;
        }

        return ((int) ($stat['mode'] ?? 0) & 0o002) !== 0;
    }

    /**
     * Whether a resolved path is the given directory or sits inside it.
     *
     * @param string $path
     * @param string $directory
     * @return bool
     */
    private function isWithin(string $path, string $directory): bool
    {
        if ($directory === '') {
            return false;
        }

        return $path === $directory || str_starts_with($path, rtrim($directory, '/') . '/');
    }

    /**
     * A resolved installation directory, or an empty string when it cannot be resolved.
     *
     * @param string $code
     * @return string
     */
    private function realRoot(string $code): string
    {
        try {
            return $this->resolve($this->directoryList->getPath($code));
        } catch (\Exception) {
            return '';
        }
    }
}
