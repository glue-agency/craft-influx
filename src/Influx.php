<?php

namespace GlueAgency\Influx;

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
use craft\services\Gc;
use craft\services\ProjectConfig as ProjectConfigService;
use craft\web\UrlManager;
use craft\web\View;
use yii\base\Event;
use GlueAgency\Influx\models\Settings;
use GlueAgency\Influx\services\AuthService;
use GlueAgency\Influx\services\EndpointTokensService;
use GlueAgency\Influx\services\LinkBuilderService;
use GlueAgency\Influx\services\LinksService;
use GlueAgency\Influx\services\DataService;
use GlueAgency\Influx\services\FieldsService;
use GlueAgency\Influx\services\SynchronizationService;
use GlueAgency\Influx\services\LogsService;
use GlueAgency\Influx\services\TargetsService;
use GlueAgency\Influx\services\CooldownService;
use GlueAgency\Influx\services\AssetUploadService;
use GlueAgency\Influx\services\BackupService;
use GlueAgency\Influx\services\DebugService;
use GlueAgency\Influx\web\twig\InfluxTwigExtension;

/**
 * Influx plugin.
 *
 * @method static Influx getInstance()
 * @method Settings getSettings()
 * @property LinksService $links
 * @property DataService $data
 * @property SynchronizationService $synchronization
 * @property FieldsService $fields
 * @property LogsService $logs
 * @property TargetsService $targets
 * @property CooldownService $cooldown
 * @property BackupService $backup
 * @property AssetUploadService $assetUpload
 * @property DebugService $debug
 * @property AuthService $auth
 * @property EndpointTokensService $endpointTokens
 */
class Influx extends Plugin
{
    public string $schemaVersion = '1.5.0';

    public bool $hasCpSettings = false;

    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'linkBuilder'     => LinkBuilderService::class,
                'links'           => LinksService::class,
                'data'            => DataService::class,
                'synchronization' => SynchronizationService::class,
                'fields'          => FieldsService::class,
                'logs'            => LogsService::class,
                'targets'         => TargetsService::class,
                'cooldown'        => CooldownService::class,
                'backup'          => BackupService::class,
                'assetUpload'     => AssetUploadService::class,
                'debug'           => DebugService::class,
                'auth'            => AuthService::class,
                'endpointTokens'  => EndpointTokensService::class,
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
            $this->registerTwigExtensions();
            $this->registerEntrySyncButton();
            $this->registerGarbageCollection();
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

        if (Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            $parent['subnav']['settings'] = [
                'label' => Craft::t('influx', 'Settings'),
                'url'   => 'influx/settings',
            ];
        }

        return $parent;
    }

    protected function registerControllers(): void
    {
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'GlueAgency\\Influx\\console\\controllers';
            return;
        }

        $this->controllerNamespace = 'GlueAgency\\Influx\\controllers';
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
                $event->rules['influx/links/<handle:[\w\-]+>/debug']        = 'influx/links/debug';
                $event->rules['influx/links/<handle:[\w\-]+>/debug/stream'] = 'influx/links/debug-stream';

                // LinkBuilder SPA — JSON CP routes
                $event->rules['influx/link-builder/bootstrap']                  = 'influx/link-builder/bootstrap';
                $event->rules['influx/link-builder/save']                       = 'influx/link-builder/save';
                $event->rules['influx/link-builder/sample']                     = 'influx/link-builder/sample';
                $event->rules['influx/link-builder/mappable-fields']            = 'influx/link-builder/mappable-fields';
                $event->rules['influx/link-builder/endpoint-token-suggestions'] = 'influx/link-builder/endpoint-token-suggestions';
                $event->rules['influx/link-builder/render-element-select']      = 'influx/link-builder/render-element-select';

                $event->rules['influx/logs']                           = 'influx/logs/index';
                $event->rules['influx/logs/<id:\d+>']                  = 'influx/logs/view';
                $event->rules['influx/logs/<id:\d+>/stream']           = 'influx/logs/stream';
                $event->rules['influx/logs/items/<id:\d+>']            = 'influx/logs/item';

                $event->rules['influx/settings']                       = 'influx/settings/edit';
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

    protected function registerTwigExtensions(): void
    {
        Craft::$app->getView()->registerTwigExtension(new InfluxTwigExtension());
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
     * Prune old log rows on Craft's periodic garbage-collection cycle when
     * the user has set a retention window. Zero (the default) keeps logs
     * indefinitely.
     */
    protected function registerGarbageCollection(): void
    {
        Event::on(Gc::class, Gc::EVENT_RUN, function () {
            $days = (int)$this->getSettings()->logRetentionDays;
            if ($days > 0) {
                $this->logs->deleteOlderThan($days);
            }
        });
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
