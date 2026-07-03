<?php

namespace GlueAgency\Influx\Tests\unit\sync;

use Codeception\Test\Unit;
use GlueAgency\Influx\enums\ItemAction;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\services\SynchronizationService;

/**
 * Precedence spec for {@see SynchronizationService::missingPolicy()} — the rule
 * that maps a link's `processing` flags onto which missing-elements action a
 * sweep performs. The most destructive action wins when more than one is
 * enabled (a delete subsumes a disable):
 *
 *   DELETE  >  DELETE_FOR_SITE  >  DISABLE
 *
 * No Craft boot: missingPolicy() reads only {@see Link::$processing}, and the
 * service's init() builds a plain {@see \GlueAgency\Influx\sync\ItemProcessor}
 * that touches no app services — so a bare service instance is enough. The
 * method is protected, so an anonymous subclass exposes it (same seam the rest
 * of the suite favours over reflection).
 */
class MissingPolicyTest extends Unit
{
    public function testNoneEnabledYieldsNull(): void
    {
        $this->assertNull($this->policyFor(['create', 'update']));
        $this->assertNull($this->policyFor([]));
    }

    public function testDisableAlone(): void
    {
        $this->assertSame(ItemAction::DISABLED, $this->policyFor(['create', 'update', 'disable']));
    }

    public function testDeleteForSiteAlone(): void
    {
        $this->assertSame(ItemAction::DELETED_FOR_SITE, $this->policyFor(['update', 'delete-for-site']));
    }

    public function testDeleteAlone(): void
    {
        $this->assertSame(ItemAction::DELETED, $this->policyFor(['update', 'delete']));
    }

    public function testDeleteBeatsDeleteForSite(): void
    {
        $this->assertSame(ItemAction::DELETED, $this->policyFor(['delete', 'delete-for-site']));
    }

    public function testDeleteForSiteBeatsDisable(): void
    {
        $this->assertSame(ItemAction::DELETED_FOR_SITE, $this->policyFor(['disable', 'delete-for-site']));
    }

    public function testDeleteBeatsDisable(): void
    {
        $this->assertSame(ItemAction::DELETED, $this->policyFor(['disable', 'delete']));
    }

    public function testDeleteWinsWhenAllThreeSet(): void
    {
        $this->assertSame(
            ItemAction::DELETED,
            $this->policyFor(['disable', 'delete-for-site', 'delete']),
        );
    }

    /**
     * Resolve the missing policy for a link with the given processing flags.
     *
     * @param list<string> $processing
     */
    private function policyFor(array $processing): ?ItemAction
    {
        $link = new Link();
        $link->processing = $processing;

        $service = new class() extends SynchronizationService {
            public function publicMissingPolicy(Link $link): ?ItemAction
            {
                return $this->missingPolicy($link);
            }
        };

        return $service->publicMissingPolicy($link);
    }
}
