<?php

declare(strict_types=1);

namespace MageOS\Aeo\Test\Integration\Controller;

use Magento\TestFramework\TestCase\AbstractController;

/**
 * The standard-router URL behind the llms documents answers with a 301 to the document's canonical
 * path.
 *
 * @magentoAppArea frontend
 */
class InternalRoutesTest extends AbstractController
{
    /**
     * @return void
     */
    public function testTheLlmsDocumentsInternalUrlRedirectsToItsCanonicalPath(): void
    {
        $this->dispatch('mageos-aeo/llms/index');

        $this->assertSame(301, $this->getResponse()->getHttpResponseCode());
        $this->assertRedirect($this->stringEndsWith('/llms.txt'));
    }
}
