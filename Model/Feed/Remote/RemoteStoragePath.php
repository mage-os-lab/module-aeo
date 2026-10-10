<?php

declare(strict_types=1);

namespace MageOS\Aeo\Model\Feed\Remote;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Where in Magento's remote storage the feed storage directory setting puts the feeds.
 *
 * The one setting says where the feeds are kept whether storage is local or remote. In remote
 * storage it is taken from the storage's root: `/mnt/shared/feeds` is `mnt/shared/feeds/` in the
 * bucket, and no setting is `mageos_aeo/`, as var/mageos_aeo/ is on local disk. A bucket has no
 * directories to check, so the value is checked as a path in it instead: segments separated by
 * single slashes, none of them `.` or `..`, of characters an object key can carry unescaped.
 */
class RemoteStoragePath
{
    /**
     * The feeds' directory in remote storage when none is configured.
     */
    public const DEFAULT = 'mageos_aeo';

    private const SEGMENT = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    /**
     * The path in remote storage the setting stands for, or null when it cannot be one.
     *
     * @param string $configured The storage directory setting
     * @return string|null
     */
    public function pathFor(string $configured): ?string
    {
        return $this->reason($configured) === null ? $this->normalised($configured) : null;
    }

    /**
     * Refuse a setting that cannot be a path in remote storage, saying why.
     *
     * @param string $configured
     * @throws LocalizedException
     * @return void
     */
    public function validate(string $configured): void
    {
        $reason = $this->reason($configured);
        if ($reason !== null) {
            throw new LocalizedException($reason);
        }
    }

    /**
     * Why the setting cannot be a path in remote storage, or null when it can.
     *
     * @param string $configured
     * @return Phrase|null
     */
    private function reason(string $configured): ?Phrase
    {
        foreach (explode('/', $this->normalised($configured)) as $segment) {
            if ($segment === '') {
                return __(
                    'The storage directory "%1" has an empty part: separate its parts with single slashes.',
                    $configured
                );
            }
            if (preg_match(self::SEGMENT, $segment) !== 1) {
                return __(
                    'The storage directory "%1" is a path in remote storage: use letters, digits, ".", "_" and "-",'
                    . ' starting each part with a letter or digit.',
                    $configured
                );
            }
        }

        return null;
    }

    /**
     * The setting without surrounding blanks and slashes; the default for none.
     *
     * @param string $configured
     * @return string
     */
    private function normalised(string $configured): string
    {
        $path = trim(trim($configured), '/');

        return $path === '' ? self::DEFAULT : $path;
    }
}
