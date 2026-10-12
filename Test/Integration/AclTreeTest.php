<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Integration;

use Magento\Framework\Acl\AclResource\ProviderInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * Review low item, decision D11a: this module's permission sits where its admin page is, as core's
 * do.
 *
 * It was under MageOS_Seo's top-level "SEO" node, beside Marketing and Stores. The configuration
 * section's permission now sits under Stores → Settings → Configuration, with every other section's.
 * The resource ID does not change, so the grants roles already hold keep working.
 *
 * @magentoAppArea adminhtml
 */
class AclTreeTest extends TestCase
{
    /**
     * @return void
     */
    public function testTheConfigurationSectionSitsWithEveryOtherSection(): void
    {
        $this->assertSame(
            [
                'Magento_Backend::admin',
                'Magento_Backend::stores',
                'Magento_Backend::stores_settings',
                'Magento_Config::config',
                'MageOS_Aeo::config',
            ],
            $this->ancestry('MageOS_Aeo::config')
        );
    }

    /**
     * The resource IDs from the root down to the one given, as the merged ACL tree has them.
     *
     * @param string $resourceId
     * @return string[]
     */
    private function ancestry(string $resourceId): array
    {
        $tree = Bootstrap::getObjectManager()->get(ProviderInterface::class)->getAclResources();

        return $this->find($tree, $resourceId, []) ?? [];
    }

    /**
     * Depth-first search for a resource, carrying the path to it.
     *
     * @param array<int, array<string, mixed>> $nodes
     * @param string $resourceId
     * @param string[] $path
     * @return string[]|null
     */
    private function find(array $nodes, string $resourceId, array $path): ?array
    {
        foreach ($nodes as $node) {
            $here   = $path;
            $here[] = (string) $node['id'];
            if ($node['id'] === $resourceId) {
                return $here;
            }
            $found = $this->find($node['children'] ?? [], $resourceId, $here);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
