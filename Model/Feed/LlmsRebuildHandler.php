<?php

declare(strict_types=1);

namespace MageOS\Aeo\Model\Feed;

use Magento\Framework\Phrase;
use MageOS\Aeo\Cron\RegenerateFeeds;
use MageOS\Seo\Api\Rebuild\GroupDescriptionInterface;
use MageOS\Seo\Api\Rebuild\GroupHandlerInterface;

/**
 * Registers the llms documents with the rebuild queue: `llms` (/llms.txt and /llms-full.txt) and
 * `jsonl` (/llms.jsonl).
 *
 * The queue's consumer, the regenerate command and the setup run find these groups here; the
 * documents themselves are built by FeedRegenerator, and whether one is worth queueing is
 * LlmsInvalidationPolicy's call. When one is out of date, the admin is shown it by its file names,
 * retried by the nightly cron.
 */
class LlmsRebuildHandler implements GroupHandlerInterface, GroupDescriptionInterface
{
    /**
     * @param FeedRegenerator $feedRegenerator
     * @param LlmsInvalidationPolicy $invalidationPolicy
     */
    public function __construct(
        private readonly FeedRegenerator        $feedRegenerator,
        private readonly LlmsInvalidationPolicy $invalidationPolicy
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getGroups(): array
    {
        return FeedRegenerator::GROUPS;
    }

    /**
     * @inheritDoc
     */
    public function isEnabled(string $group): bool
    {
        return $this->invalidationPolicy->isGroupEnabled($group);
    }

    /**
     * @inheritDoc
     */
    public function rebuild(?string $group): array
    {
        return $this->feedRegenerator->regenerate($group);
    }

    /**
     * @inheritDoc
     */
    public function getLabel(string $group): Phrase
    {
        return match ($group) {
            FeedRegenerator::GROUP_LLMS  => __('llms.txt and llms-full.txt'),
            FeedRegenerator::GROUP_JSONL => __('llms.jsonl'),
            default                      => __('Feed %1', $group),
        };
    }

    /**
     * @inheritDoc
     */
    public function getScheduledJob(string $group): ?string
    {
        // The nightly cron rebuilds every group (FeedRegenerator::regenerate() with no group).
        return \in_array($group, FeedRegenerator::GROUPS, true) ? RegenerateFeeds::JOB : null;
    }
}
