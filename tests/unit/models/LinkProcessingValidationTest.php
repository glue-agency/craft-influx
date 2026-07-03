<?php

namespace GlueAgency\Influx\Tests\unit\models;

use Codeception\Test\Unit;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * Endpoint-shape gating for the two delete policies
 * ({@see Link::validateProcessing()}): global `delete` is only valid without
 * site endpoints; `delete-for-site` is only valid with them.
 */
class LinkProcessingValidationTest extends Unit
{
    public function testGlobalDeleteRejectedWithSiteEndpoints(): void
    {
        $link = FakeLink::make([
            'siteEndpoints' => [['site' => 'nl', 'endpoint' => 'https://example.test/nl']],
            'processing'    => [Link::PROCESSING_CREATE, Link::PROCESSING_DELETE],
        ]);

        $this->assertFalse($link->validate(['processing']));
        $this->assertArrayHasKey('processing', $link->getErrors());
    }

    public function testDeleteForSiteRejectedWithoutSiteEndpoints(): void
    {
        $link = FakeLink::make([
            'processing' => [Link::PROCESSING_CREATE, Link::PROCESSING_DELETE_FOR_SITE],
        ]);

        $this->assertFalse($link->validate(['processing']));
        $this->assertArrayHasKey('processing', $link->getErrors());
    }

    public function testGlobalDeleteAcceptedWithoutSiteEndpoints(): void
    {
        $link = FakeLink::make([
            'processing' => [Link::PROCESSING_CREATE, Link::PROCESSING_DELETE],
        ]);

        $this->assertTrue($link->validate(['processing']));
        $this->assertArrayNotHasKey('processing', $link->getErrors());
    }

    public function testDeleteForSiteAcceptedWithSiteEndpoints(): void
    {
        $link = FakeLink::make([
            'siteEndpoints' => [['site' => 'nl', 'endpoint' => 'https://example.test/nl']],
            'processing'    => [Link::PROCESSING_CREATE, Link::PROCESSING_DELETE_FOR_SITE],
        ]);

        $this->assertTrue($link->validate(['processing']));
        $this->assertArrayNotHasKey('processing', $link->getErrors());
    }
}
