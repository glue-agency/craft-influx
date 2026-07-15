<?php

namespace GlueAgency\Influx\web\assets\links;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * The plugin's single CP asset bundle: the compiled Vue apps (link builder, log
 * viewer, debug inspector) plus the server-rendered chrome classes they share
 * with the plain CP pages, all bundled into `influx-app.css`.
 *
 * Registered once for the whole plugin from
 * {@see \GlueAgency\Influx\controllers\AbstractController}, so every
 * Influx CP screen gets it. The JS self-selects which app to mount by DOM
 * marker, and all styling is `.influx-`-scoped, so loading it everywhere costs
 * an idle screen nothing and never leaks into the rest of the CP.
 */
class InfluxAsset extends AssetBundle
{
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';

        $this->depends = [
            CpAsset::class,
        ];

        $this->css = [
            'css/influx-app.css',
        ];

        $this->js = [
            'js/influx-links.js',
        ];

        parent::init();
    }
}
