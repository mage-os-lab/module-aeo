<?php

declare(strict_types=1);

namespace MageOS\Aeo\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * Raised when the configured feed storage directory is allowed but cannot be used on this host:
 * missing (a mount that is not there), not a directory, or not readable or writable for what is
 * asked of it.
 *
 * Deliberately not the same as a feed file that does not exist yet (issue #7). A missing file is
 * fixed by a rebuild, so a request for it queues one; this is fixed by whoever runs the server, so
 * a request answers 503 without queueing anything, and a rebuild fails and shows the reason in the
 * admin rather than writing to var/ instead, where the web hosts may never look.
 */
class FeedStorageUnavailableException extends LocalizedException
{
}
