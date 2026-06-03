<?php

namespace TDM\Influx;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\DefineHtmlEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RebuildConfigEvent;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\elements\Entry;
use craft\services\ProjectConfig as ProjectConfigService;
use craft\web\UrlManager;
use craft\web\View;
use yii\base\Event;
use TDM\Influx\models\Settings;
use TDM\Influx\services\LinksService;
use TDM\Influx\services\DataService;
use TDM\Influx\services\SynchronizationService;
use TDM\Influx\services\MappingService;
use TDM\Influx\services\LogsService;
use TDM\Influx\services\TargetsService;
use TDM\Influx\services\CooldownService;
use TDM\Influx\services\BackupService;
use TDM\Influx\targets\EntryTarget;

/**
 * Influx plugin.
 *
 * @method static Influx getInstance()
 * @method Settings getSettings()
 * @property LinksService $links
 * @property DataService $data
 * @property SynchronizationService $synchronization
 * @property MappingService $mapping
 * @property LogsService $logs
 * @property TargetsService $targets
 * @property CooldownService $cooldown
 * @property BackupService $backup
 */
class Influx extends Plugin
{
    public string $schemaVersion = '1.1.0';

    public bool $hasCpSettings = false;

    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'links'           => LinksService::class,
                'data'            => DataService::class,
                'synchronization' => SynchronizationService::class,
                'mapping'         => MappingService::class,
                'logs'            => LogsService::class,
                'targets'         => TargetsService::class,
                'cooldown'        => CooldownService::class,
                'backup'          => BackupService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        Craft::setAlias('@influx', __DIR__);

        $this->registerProjectConfigEventListeners();

        Craft::$app->onInit(function () {
            $this->registerControllers();
            $this->registerCpRoutes();
            $this->registerCpTemplateRoots();
            $this->registerBuiltInTargets();
            $this->registerEntrySyncButton();
        });
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    public function getCpNavItem(): ?array
    {
        $parent = parent::getCpNavItem();

        $parent['url'] = 'influx';
        $parent['label'] = Craft::t('influx', 'Influx');
        $parent['subnav'] = [
            'links' => [
                'label' => Craft::t('influx', 'Links'),
                'url'   => 'influx/links',
            ],
            'logs' => [
                'label' => Craft::t('influx', 'Logs'),
                'url'   => 'influx/logs',
            ],
        ];

        return $parent;
    }

    protected function registerControllers(): void
    {
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'TDM\\Influx\\console\\controllers';
            return;
        }

        $this->controllerNamespace = 'TDM\\Influx\\controllers';
    }

    protected function registerCpRoutes(): void
    {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                $event->rules['influx']                                = 'influx/links/index';
                $event->rules['influx/links']                          = 'influx/links/index';
                $event->rules['influx/links/new']                      = 'influx/links/edit';
                $event->rules['influx/links/<handle:[\w\-]+>']         = 'influx/links/view';
                $event->rules['influx/links/<handle:[\w\-]+>/edit']    = 'influx/links/edit';

                $event->rules['influx/logs']                           = 'influx/logs/index';
                $event->rules['influx/logs/<id:\d+>']                  = 'influx/logs/view';
            },
        );
    }

    protected function registerCpTemplateRoots(): void
    {
        Event::on(
            View::class,
            View::EVENT_REGISTER_CP_TEMPLATE_ROOTS,
            function (RegisterTemplateRootsEvent $event) {
                $event->roots['influx'] = __DIR__ . '/templates';
            },
        );
    }

    protected function registerBuiltInTargets(): void
    {
        $this->targets->register(EntryTarget::class);
    }

    /**
     * Wire LinksService into Craft's Project Config lifecycle so that:
     *   - new/changed links applied from YAML (e.g. via `project-config/apply`)
     *     invalidate the in-memory cache
     *   - removed links do the same
     *   - rebuilds (`project-config/rebuild`) emit the current state to YAML
     */
    protected function registerProjectConfigEventListeners(): void
    {
        $projectConfig = Craft::$app->getProjectConfig();

        $projectConfig
            ->onAdd(LinksService::CONFIG_LINKS_KEY . '.{uid}', [$this->links, 'handleChangedLink'])
            ->onUpdate(LinksService::CONFIG_LINKS_KEY . '.{uid}', [$this->links, 'handleChangedLink'])
            ->onRemove(LinksService::CONFIG_LINKS_KEY . '.{uid}', [$this->links, 'handleDeletedLink']);

        Event::on(
            ProjectConfigService::class,
            ProjectConfigService::EVENT_REBUILD,
            function (RebuildConfigEvent $event) {
                $links = [];
                foreach ($this->links->getAllLinks() as $link) {
                    if ($link->uid) {
                        $links[$link->uid] = $link->getConfig();
                    }
                }
                $event->config['influx']['links'] = $links;
            },
        );
    }

    /**
     * Add a "Sync from remote" button to the edit page of any entry that has
     * been linked via the link's match attribute.
     */
    protected function registerEntrySyncButton(): void
    {
        Event::on(Entry::class, Entry::EVENT_DEFINE_ADDITIONAL_BUTTONS, function (DefineHtmlEvent $event) {
            /** @var Entry $element */
            $element = $event->sender;

            $link = $this->links->findLinkForElement($element);
            if (!$link || !$link->itemEndpoint) {
                return;
            }

            $cooldownRemaining = $this->cooldown->remaining($link, $element);
            $disabled = $cooldownRemaining > 0;

            $event->html .= Html::beginForm(
                UrlHelper::actionUrl('influx/synchronization/element'),
                'post',
                ['class' => 'inline-block'],
            );
            $event->html .= Html::hiddenInput('elementId', (string)$element->id);
            $event->html .= Html::submitButton(Craft::t('influx', 'Sync from remote'), [
                'class'    => array_filter(['btn', $disabled ? 'disabled' : null]),
                'disabled' => $disabled,
                'data'     => ['icon' => 'refresh'],
                'title'    => $disabled
                    ? Craft::t('influx', 'Available again in {n}s', ['n' => $cooldownRemaining])
                    : '',
            ]);
            $event->html .= Html::endForm();
        });
    }
}
