<?php

namespace GlueAgency\Influx\web\assets\links;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * CSS-only slice of the link editor styling — just `links.css`, no Vue bundle
 * or JS. Lets the server-rendered CP pages (the debug inspector, the log
 * viewer's item drill-down) reuse the same chrome as the SPA — notably the
 * `.influx-mapping-group` card — without pulling in the whole LinkBuilder app.
 *
 * {@see LinksAsset} depends on this so the editor screen gets the same CSS
 * from a single published source.
 */
class StylesAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';

        $this->depends = [
            CpAsset::class,
        ];

        $this->css = [
            'css/links.css',
        ];

        parent::init();
    }
}
