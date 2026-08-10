<?php

namespace GlueAgency\Influx\Tests\unit\models;

use Codeception\Test\Unit;
use GlueAgency\Influx\exceptions\InfluxException;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\models\OffsetPreset;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * The sliding-window preset ({@see OffsetPreset}): what it accepts off a link's
 * offset map, and what it refuses.
 *
 * The one thing every case here is really pinning is that NOTHING degrades to a
 * missing query param. A preset that can't be used throws, so the run fails
 * loudly — the alternative is a scheduled delta quietly fetching the whole feed
 * on every tick, which is exactly what the old shape's "invalid `since`" branch
 * did.
 *
 * The Twig render is the class's one impure call and is stubbed here through
 * {@see RecordingPreset}; the unit suite has no booted Craft.
 */
class OffsetPresetTest extends Unit
{
    public function testResolveSendsTheRenderedValueUnderItsQueryParam(): void
    {
        $preset = new RecordingPreset('hour', 'modified_since', "{{ now|date('c') }}");
        $preset->rendered = '2026-08-10T13:00:00+00:00';

        [$params, $label] = $preset->resolve();

        $this->assertSame(['modified_since' => '2026-08-10T13:00:00+00:00'], $params);
        $this->assertSame('modified_since = 2026-08-10T13:00:00+00:00', $label);
        $this->assertSame("{{ now|date('c') }}", $preset->renderedTemplate);
    }

    public function testForLinkBuildsAConfiguredPreset(): void
    {
        $preset = OffsetPreset::forLink($this->link(), 'hour');

        $this->assertSame('hour', $preset->handle);
        $this->assertSame('modified_since', $preset->queryParam);
        $this->assertSame("{{ now|date_modify('-1 hour')|date('c', 'UTC') }}", $preset->value);
    }

    /**
     * No offset asked for is the only null: every run that doesn't pass
     * `--offset` takes this path, so it can't be an error.
     */
    public function testNoRequestedHandleIsNoPreset(): void
    {
        $this->assertNull(OffsetPreset::forLink($this->link(), null));
        $this->assertNull(OffsetPreset::forLink($this->link(), ''));
        $this->assertNull(OffsetPreset::forLink($this->link(), '  '));
    }

    /**
     * A typo in a cron's `--offset=` used to run a full sync under the wrong
     * name; it fails now.
     */
    public function testUnknownHandleThrows(): void
    {
        $this->expectException(InfluxException::class);
        $this->expectExceptionMessage("has no offset preset 'hourly'");

        OffsetPreset::forLink($this->link(), 'hourly');
    }

    public function testHandleIsTrimmedBeforeLookup(): void
    {
        $this->assertSame('hour', OffsetPreset::forLink($this->link(), ' hour ')?->handle);
    }

    /**
     * @dataProvider unusableConfigs
     */
    public function testUnusableConfigThrows(array $config): void
    {
        $this->expectException(InfluxException::class);
        $this->expectExceptionMessage("Offset preset 'hour' needs both a query param and a value.");

        OffsetPreset::fromConfig('hour', $config);
    }

    public static function unusableConfigs(): array
    {
        return [
            'empty'          => [[]],
            'no value'       => [['queryParam' => 'modified_since']],
            'no query param' => [['value' => '20 minutes']],
            'blank value'    => [['queryParam' => 'modified_since', 'value' => '   ']],
            'blank param'    => [['queryParam' => ' ', 'value' => '20 minutes']],
        ];
    }

    public function testCellsAreTrimmed(): void
    {
        $preset = OffsetPreset::fromConfig('hour', [
            'queryParam' => "  modified_since\n",
            'value'      => '  20 minutes  ',
        ]);

        $this->assertSame('modified_since', $preset->queryParam);
        $this->assertSame('20 minutes', $preset->value);
    }

    protected function link(): Link
    {
        return FakeLink::make([
            'offset' => [
                'hour' => [
                    'queryParam' => 'modified_since',
                    'value'      => "{{ now|date_modify('-1 hour')|date('c', 'UTC') }}",
                ],
            ],
        ]);
    }
}

/**
 * Stands in for Craft's template parser: records what it was handed and returns
 * a canned string, so {@see OffsetPreset::resolve()} can be specced without a
 * booted app.
 */
class RecordingPreset extends OffsetPreset
{
    public string $rendered = '';

    public ?string $renderedTemplate = null;

    protected function render(): string
    {
        $this->renderedTemplate = $this->value;

        return $this->rendered;
    }
}
