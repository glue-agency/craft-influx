<?php

namespace GlueAgency\Influx\Tests\unit\web;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use craft\elements\Entry;
use craft\models\Site;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;
use GlueAgency\Influx\web\SyncButtonPresenter;

/**
 * The entry-edit "Sync from remote" offering rules: a link needs a resource
 * endpoint to be listed at all, an entry with no match value or an active
 * cool-down is offered DISABLED with a reason rather than hidden, and the posted
 * params pin the site only for a link with per-site endpoints.
 *
 * The presenter's two plugin lookups (which links target the element, and the
 * element's cool-down) are seams, so the rules spec here without a booted
 * plugin. Entries are anonymous Entry subclasses with a skipped constructor,
 * matching the pattern in the target specs.
 */
class SyncButtonCandidateTest extends Unit
{
    public function testOnlyLinksWithAResourceEndpointAreOffered(): void
    {
        $withEndpoint = FakeLink::make(['handle' => 'withItem', 'name' => 'With item', 'itemEndpoint' => 'https://api.test/items/{id}']);
        $withoutEndpoint = FakeLink::make(['handle' => 'listOnly', 'name' => 'List only']);

        $presenter = $this->presenter([$withoutEndpoint, $withEndpoint]);
        $candidates = $presenter->candidates($this->entry('abc'));

        $this->assertCount(1, $candidates);
        $this->assertSame('With item', $candidates[0]['name']);
        $this->assertTrue($candidates[0]['enabled']);
        $this->assertNull($candidates[0]['reason']);
    }

    public function testNoTargetingLinkMeansNothingIsOffered(): void
    {
        $this->assertSame([], $this->presenter([])->candidates($this->entry('abc')));
    }

    public function testOnlyLinksTheUserMaySyncAreOffered(): void
    {
        $allowed = FakeLink::make(['handle' => 'allowed', 'name' => 'Allowed', 'itemEndpoint' => 'https://api.test/items/{id}']);
        $denied = FakeLink::make(['handle' => 'denied', 'name' => 'Denied', 'itemEndpoint' => 'https://api.test/items/{id}']);

        $candidates = $this->presenter([$allowed, $denied], 0, ['allowed'])->candidates($this->entry('abc'));

        $this->assertCount(1, $candidates);
        $this->assertSame('Allowed', $candidates[0]['name']);

        // Permission is all-or-nothing per link: denied for every one of an
        // element's links leaves no affordance at all, not a disabled button.
        $this->assertSame([], $this->presenter([$allowed, $denied], 0, [])->candidates($this->entry('abc')));
    }

    public function testAMissingMatchValueDisablesWithAReason(): void
    {
        $link = FakeLink::make(['itemEndpoint' => 'https://api.test/items/{id}']);
        $presenter = $this->presenter([$link]);

        foreach ([null, ''] as $noValue) {
            $candidate = $presenter->candidate($link, $this->entry($noValue));

            $this->assertFalse($candidate['enabled']);
            $this->assertStringContainsString('match field', (string) $candidate['reason']);
        }
    }

    public function testALinkWithNoMatchAttributeDisablesToo(): void
    {
        $link = FakeLink::make(['itemEndpoint' => 'https://api.test/items/{id}', 'match' => []]);

        $candidate = $this->presenter([$link])->candidate($link, $this->entry('abc'));

        $this->assertFalse($candidate['enabled']);
        $this->assertStringContainsString('match field', (string) $candidate['reason']);
    }

    public function testAnActiveCooldownDisablesWithItsOwnReason(): void
    {
        $link = FakeLink::make(['itemEndpoint' => 'https://api.test/items/{id}']);

        $candidate = $this->presenter([$link], cooldown: 12)->candidate($link, $this->entry('abc'));

        $this->assertFalse($candidate['enabled']);
        $this->assertSame('Recently synced', $candidate['reason']);
    }

    public function testTheCooldownIsOnlyConsultedOnceThereIsAMatchValue(): void
    {
        $link = FakeLink::make(['itemEndpoint' => 'https://api.test/items/{id}']);
        $presenter = $this->presenter([$link], cooldown: 12);

        $candidate = $presenter->candidate($link, $this->entry(null));

        $this->assertStringContainsString('match field', (string) $candidate['reason']);
        $this->assertSame(0, $presenter->cooldownCalls);
    }

    public function testParamsCarryTheExplicitLinkHandleAndNoSiteByDefault(): void
    {
        $link = FakeLink::make(['handle' => 'articles', 'itemEndpoint' => 'https://api.test/items/{id}']);

        $candidate = $this->presenter([$link])->candidate($link, $this->entry('abc'));

        $this->assertSame(['elementId' => 42, 'link' => 'articles'], $candidate['params']);
    }

    public function testOnlyAPerSiteLinkPinsTheEditorsSite(): void
    {
        $link = FakeLink::make([
            'handle'        => 'articles',
            'itemEndpoint'  => 'https://api.test/items/{id}',
            'siteEndpoints' => [['site' => 'nl', 'endpoint' => 'https://api.test/nl/articles']],
        ]);

        $candidate = $this->presenter([$link])->candidate($link, $this->entry('abc'));

        $this->assertSame(['elementId' => 42, 'link' => 'articles', 'site' => 'nl'], $candidate['params']);
    }

    /**
     * @param Link[] $links
     */
    protected function presenter(array $links, int $cooldown = 0, array $syncableHandles = null): SyncButtonPresenter
    {
        return new class($links, $cooldown, $syncableHandles) extends SyncButtonPresenter {
            public int $cooldownCalls = 0;

            /** @var Link[] */
            protected array $stubLinks;

            protected int $stubCooldown;

            /** @var string[]|null Handles the stub user may sync; null = all of them. */
            protected ?array $stubSyncableHandles;

            public function __construct(array $links, int $cooldown, ?array $syncableHandles)
            {
                $this->stubLinks = $links;
                $this->stubCooldown = $cooldown;
                $this->stubSyncableHandles = $syncableHandles;
            }

            protected function linksForElement(ElementInterface $element): array
            {
                return $this->stubLinks;
            }

            protected function cooldownRemaining(Link $link, ElementInterface $element): int
            {
                $this->cooldownCalls++;

                return $this->stubCooldown;
            }

            protected function canSyncLink(Link $link): bool
            {
                return $this->stubSyncableHandles === null
                    || in_array($link->handle, $this->stubSyncableHandles, true);
            }
        };
    }

    protected function entry(mixed $matchValue): Entry
    {
        $entry = new class() extends Entry {
            public mixed $importId = null;

            public function __construct()
            {
                // Skip Entry::init()'s Craft dependencies.
            }

            public function getSite(): Site
            {
                return new Site(['handle' => 'nl']);
            }
        };

        $entry->id = 42;
        // FakeLink matches on `importId`; a real property, so the presenter reads
        // it directly instead of through the field magic getter (which would need
        // a booted Craft).
        $entry->importId = $matchValue;

        return $entry;
    }
}
