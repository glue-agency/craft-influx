<?php

namespace GlueAgency\Influx\web\assets\editor;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * A minimal, framework-free CP asset for the element editor: it draws a small
 * "linked by Influx" icon next to every field an active mapping writes.
 *
 * Deliberately hand-authored and served straight from `resources/` — not part
 * of the Vue/Vite bundle in web/assets/links. It's a few lines of vanilla JS
 * with nothing to build, and keeping it separate means the editor page never
 * loads the heavier link-builder SPA. Registered on demand from
 * {@see \GlueAgency\Influx\Influx::registerFieldIndicators()}, only when the
 * element being edited actually has mapped fields, with the list of mapped
 * field handles handed alongside as the `influxFieldIndicators` JS var.
 */
class FieldIndicatorAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/resources';

        $this->depends = [
            CpAsset::class,
        ];

        $this->css = [
            'field-indicators.css',
        ];

        $this->js = [
            'field-indicators.js',
        ];

        parent::init();
    }
}
