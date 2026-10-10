<?php

declare(strict_types=1);

namespace MageOS\Aeo\Plugin\RemoteStorage;

use Magento\RemoteStorage\Model\Synchronizer;
use MageOS\Aeo\Model\Feed\Remote\FeedCopier;

/**
 * Makes `bin/magento remote-storage:sync` copy the feeds too, after the media it copies.
 *
 * The command is the step Magento documents after switching remote storage on, and lists each file
 * the synchronisation yields: the feeds follow, the same way (Model\Feed\Remote\FeedCopier).
 */
class CopyFeedsOnSynchronize
{
    /**
     * @param FeedCopier $feedCopier
     */
    public function __construct(
        private readonly FeedCopier $feedCopier
    ) {
    }

    /**
     * Copy the feeds once the media are copied.
     *
     * @param Synchronizer $subject
     * @param \Generator $result
     * @return \Generator
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function afterExecute(Synchronizer $subject, \Generator $result): \Generator
    {
        yield from $result;
        yield from $this->feedCopier->copy();
    }
}
