<?php

namespace TDM\Influx\Tests\feature\Support;

use Codeception\Test\Unit;
use Craft;
use TDM\Influx\Influx;
use TDM\Influx\services\LinksService;

/**
 * Base feature test. Boots Craft via craftcms/test-framework (the codeception
 * `\craft\test\Craft` module wires this up for us), wipes any links from a
 * previous run, and swaps the `data` plugin component with a stub so no real
 * HTTP requests fire.
 *
 * Subclasses queue feed responses on `$this->data`, persist a Link via
 * `LinkBuilder::articles()->...->save()`, then invoke the sync service.
 */
abstract class FeatureTestCase extends Unit
{
    protected StubDataService $data;

    protected function _before(): void
    {
        // Replace the data component with a stub for the duration of the test.
        // Yii's $module->set() overrides the lazy-loaded component definition,
        // so as long as nothing else has called $plugin->data yet this wins.
        $this->data = new StubDataService();
        Influx::getInstance()->set('data', $this->data);
    }

    protected function _after(): void
    {
        // craftcms/test-framework rolls back the DB transaction at teardown,
        // but Project Config writes go through `Craft::$app->getProjectConfig()`
        // which also touches in-memory PC state and (in test mode) the snapshot
        // tied to the suite, not the test. Wipe any links we wrote so the next
        // test starts from the seeded snapshot.
        Craft::$app->getProjectConfig()->remove(LinksService::CONFIG_LINKS_KEY);
        Influx::getInstance()->links->handleDeletedLink(new \craft\events\ConfigEvent());
    }
}
