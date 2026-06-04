<?php

namespace TDM\Influx\web\assets\links;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * CP styling + JS helpers for the Influx link editor.
 *
 * Registered from LinksController::actionEdit() so the bundle is only loaded
 * on the link edit screen.
 */
class LinksAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';

        $this->depends = [
            CpAsset::class,
        ];

        $this->css = [
            'css/links.css',
            // Compiled by `npm run build`. Holds the styles for the typed-
            // mapping extras Vue components.
            'css/influx-app.css',
        ];

        $this->js = [
            // Mounts the typed-mapping extras component on every row whose
            // field carries a fieldMeta.kind (asset, lightswitch, relation, ...).
            'js/influx-links.js',
        ];

        parent::init();
    }
}
