<?php

namespace GlueAgency\Influx\web\twig;

use GlueAgency\Influx\helpers\Compat;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * CP Twig helpers that have to work on both Craft 4 and Craft 5.
 *
 * Craft 5 ships an `elementChip()` Twig function; Craft 4 has none. Rather
 * than branching in templates, every template uses `influxElementChip()`
 * unconditionally and {@see Compat::elementChipHtml()} picks the right
 * renderer at runtime.
 */
class InfluxTwigExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('influxElementChip', [Compat::class, 'elementChipHtml'], ['is_safe' => ['html']]),
        ];
    }
}
