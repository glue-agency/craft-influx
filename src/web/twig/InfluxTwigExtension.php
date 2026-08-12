<?php

namespace GlueAgency\Influx\web\twig;

use GlueAgency\Influx\enums\ProcessingAction;
use GlueAgency\Influx\helpers\Compat;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * CP Twig helpers: the cross-major chip shims, plus the enum-derived processing
 * vocabulary. The latter comes through a function rather than a controller
 * variable because `influx/_overview`'s pill macros need it and Twig macros are
 * context-isolated.
 *
 * Craft 5 ships an `elementChip()` Twig function; Craft 4 has none. Rather
 * than branching in templates, every template uses `influxElementChip()`
 * unconditionally and {@see Compat::elementChipHtml()} picks the right
 * renderer at runtime. `influxSiteChip()` is the same deal for a site, which
 * Craft 5 can chip as a component but Craft 4 cannot chip at all
 * ({@see Compat::siteChipHtml()}), and `influxValueChip()` for a fact that is
 * only ever a label ({@see Compat::valueChipHtml()}).
 */
class InfluxTwigExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('influxElementChip', [Compat::class, 'elementChipHtml'], ['is_safe' => ['html']]),
            new TwigFunction('influxSiteChip', [Compat::class, 'siteChipHtml'], ['is_safe' => ['html']]),
            new TwigFunction('influxSiteChips', [Compat::class, 'siteChipsHtml'], ['is_safe' => ['html']]),
            new TwigFunction('influxValueChip', [Compat::class, 'valueChipHtml'], ['is_safe' => ['html']]),
            new TwigFunction('influxProcessingValues', [$this, 'processingValues']),
            new TwigFunction('influxProcessingColor', [$this, 'processingColor']),
        ];
    }

    /**
     * Every processing value, in the order the Links overview renders its pills.
     *
     * @return list<string>
     */
    public function processingValues(): array
    {
        return ProcessingAction::values();
    }

    /**
     * Pill colour for one processing value; gray for a value the enum doesn't
     * know (hand-edited config).
     */
    public function processingColor(string $value): string
    {
        return ProcessingAction::tryFrom($value)?->color() ?? 'gray';
    }
}
