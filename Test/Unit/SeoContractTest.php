<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Decision D7b: this module uses MageOS_Seo only through Seo's `Api` interfaces, the contract Seo
 * keeps from 2.0.0. Any other Seo class may change in any Seo release, and this module's `^2.0`
 * would still accept that release, with nothing to flag the break.
 *
 * Two Seo classes are extended rather than called, so they are not interfaces: the rebuild's
 * in-progress exception, which FeedRebuildInProgressException subclasses so that the rebuild layer
 * catches it, and the connected resource model CategoryProductCount builds on.
 *
 * The scan is textual, over the production code: every PHP file outside Test/ and every XML file
 * under etc/ (where di.xml names types). Docblocks count too: one that points at a Seo class outside
 * the contract is out of date. vendor/ and dot-directories are skipped, because CI installs this
 * module's dependencies, Seo among them, inside the module.
 */
class SeoContractTest extends TestCase
{
    /**
     * Seo names this module may write outside the API: the classes it extends, and the name of a
     * patch MageOS_Seo applied before the split, a string in core's patch_list rather than a class
     * this module uses (Setup\Patch\Data\RemoveHreflangSitemap).
     */
    private const EXTENDED = [
        'Exception\\RebuildInProgressException',
        'Model\\ResourceModel\\AbstractConnectedResource',
        'Setup\\Patch\\Data\\RemoveHreflangSitemap',
    ];

    /**
     * @return void
     */
    public function testOnlySeosApiIsReferenced(): void
    {
        $root    = \dirname(__DIR__, 2);
        $scanned = [];
        $outside = [];

        foreach ($this->productionFiles($root) as $relative) {
            $scanned[] = $relative;
            $contents  = (string) file_get_contents($root . '/' . $relative);

            // One backslash in XML and `use` statements, two inside a PHP string literal.
            preg_match_all('/MageOS\\\\{1,2}Seo\\\\{1,2}([\w\\\\]+)/', $contents, $matches);
            foreach ($matches[1] as $name) {
                $name = (string) preg_replace('/\\\\+/', '\\', $name);
                if (!str_starts_with($name, 'Api\\') && !\in_array($name, self::EXTENDED, true)) {
                    $outside[] = $relative . ': MageOS\\Seo\\' . $name;
                }
            }
        }

        // A scan that found nothing to scan would pass for the wrong reason.
        $this->assertContains('Model/LlmsJsonl/JsonlBuilder.php', $scanned);
        $this->assertContains('etc/di.xml', $scanned);

        $outside = array_values(array_unique($outside));
        sort($outside);
        $this->assertSame([], $outside, 'Seo is used outside its Api: use the Api interface instead.');
    }

    /**
     * The module's production files, relative to its root: PHP outside Test/, and XML under etc/.
     *
     * @param string $root
     * @return string[]
     */
    private function productionFiles(string $root): array
    {
        $directories = new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            static function (\SplFileInfo $file, string $path, \RecursiveDirectoryIterator $iterator): bool {
                $name = $file->getFilename();
                if (str_starts_with($name, '.')) {
                    return false;
                }

                return $iterator->getSubPath() !== '' || !\in_array($name, ['Test', 'vendor'], true);
            }
        );

        $files = [];
        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator($directories) as $file) {
            $relative  = substr($file->getPathname(), \strlen($root) + 1);
            $extension = $file->getExtension();
            if ($extension === 'php' || ($extension === 'xml' && str_starts_with($relative, 'etc/'))) {
                $files[] = $relative;
            }
        }
        sort($files);

        return $files;
    }
}
