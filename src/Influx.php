<?php

namespace GlueAgency\Influx;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Entry;
use craft\events\DefineHtmlEvent;
use craft\events\RebuildConfigEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\helpers\Html;
use craft\services\Gc;
use craft\services\ProjectConfig as ProjectConfigService;
use craft\web\UrlManager;
use craft\web\View;
use GlueAgency\Influx\integrations\feedme\services\FeedMeService;
use GlueAgency\Influx\models\Settings;
use GlueAgency\Influx\services\AssetUploadService;
use GlueAgency\Influx\services\AuthService;
use GlueAgency\Influx\services\BackupService;
use GlueAgency\Influx\services\CooldownService;
use GlueAgency\Influx\services\DataService;
use GlueAgency\Influx\services\DebugService;
use GlueAgency\Influx\services\EndpointTokensService;
use GlueAgency\Influx\services\FieldsService;
use GlueAgency\Influx\services\LinkBuilderService;
use GlueAgency\Influx\services\LinksService;
use GlueAgency\Influx\services\LogsService;
use GlueAgency\Influx\services\SynchronizationService;
use GlueAgency\Influx\services\TargetsService;
use GlueAgency\Influx\web\twig\InfluxTwigExtension;
use yii\base\Event;

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
 * @property FeedMeService $feedMe
 */
class Influx extends Plugin
{
    public string $schemaVersion = '1.0.1';

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
                'feedMe'          => FeedMeService::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        Craft::setAlias('@influx', __DIR__);

        $this->registerProjectConfigEventListeners();

        Craft::$app->onInit(function() {
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

    /**
     * Craft removes `plugins.influx` on uninstall, but the links live under
     * the plugin's OWN root `influx` key — drop it too, so an uninstall
     * leaves no orphaned config in project.yaml.
     */
    protected function beforeUninstall(): void
    {
        Craft::$app->getProjectConfig()->remove('influx');
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

        // Flag error logs on the nav, like Utilities flags available updates:
        // a badge on the section and its Logs subitem when any log has errors.
        $errorCount = Influx::getInstance()->logs->errorLogCount();

        if ($errorCount > 0) {
            $parent['badgeCount'] = $errorCount;
            $parent['subnav']['logs']['badgeCount'] = $errorCount;
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
            function(RegisterUrlRulesEvent $event) {
                $event->rules['influx'] = 'influx/links/index';
                $event->rules['influx/links'] = 'influx/links/index';
                $event->rules['influx/links/new'] = 'influx/links/edit';
                $event->rules['influx/links/<id:\d+>/duplicate'] = 'influx/links/duplicate';
                // Same editor in read-only environments — the builder loads
                // the stored config with every field disabled; there's no
                // separate detail view.
                $event->rules['influx/links/<id:\d+>'] = 'influx/links/edit';
                $event->rules['influx/links/<id:\d+>/edit'] = 'influx/links/edit';

                // Debug is a standalone inspector scoped by link handle
                // (?link=<handle>), with a link switcher — not a per-link page.
                $event->rules['influx/debug'] = 'influx/links/debug';
                $event->rules['influx/debug/inspect'] = 'influx/links/debug-inspect';

                // LinkBuilder SPA — JSON CP routes
                $event->rules['influx/link-builder/bootstrap'] = 'influx/link-builder/bootstrap';
                $event->rules['influx/link-builder/save'] = 'influx/link-builder/save';
                $event->rules['influx/link-builder/fetch-sample'] = 'influx/link-builder/fetch-sample';
                $event->rules['influx/link-builder/mappable-fields'] = 'influx/link-builder/mappable-fields';
                $event->rules['influx/link-builder/endpoint-token-suggestions'] = 'influx/link-builder/endpoint-token-suggestions';
                $event->rules['influx/link-builder/render-element-select'] = 'influx/link-builder/render-element-select';

                $event->rules['influx/logs'] = 'influx/logs/index';
                $event->rules['influx/logs/<id:\d+>'] = 'influx/logs/view';
                $event->rules['influx/logs/<id:\d+>/items'] = 'influx/logs/items';
                $event->rules['influx/logs/items/<id:\d+>'] = 'influx/logs/item';

                $event->rules['influx/settings'] = 'influx/settings/edit';
            },
        );
    }

    protected function registerCpTemplateRoots(): void
    {
        Event::on(
            View::class,
            View::EVENT_REGISTER_CP_TEMPLATE_ROOTS,
            function(RegisterTemplateRootsEvent $event) {
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
            function(RebuildConfigEvent $event) {
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
        Event::on(Gc::class, Gc::EVENT_RUN, function() {
            $days = (int) $this->getSettings()->logRetentionDays;

            if ($days > 0) {
                $this->logs->deleteOlderThan($days);
            }
        });
    }

    /**
     * Add a "Sync from remote" button to the edit page of any entry that has
     * been linked via the link's match attribute.
     *
     * Additional buttons render INSIDE the edit page's #main-form, so this
     * must not output a <form> of its own: forms can't nest, the browser
     * would close #main-form early and every field input (plus Craft's hidden
     * action/redirect inputs) would fall outside it — silently breaking entry
     * saving and drafts. Instead the button uses Craft's `formsubmit` pattern;
     * `form: false` makes the CP JS post the action through a detached
     * temporary form (CSRF included) rather than hijacking the closest form.
     */
    protected function registerEntrySyncButton(): void
    {
        Event::on(Entry::class, Entry::EVENT_DEFINE_ADDITIONAL_BUTTONS, function(DefineHtmlEvent $event) {
            /** @var Entry $element */
            $element = $event->sender;

            $link = $this->links->findLinkForElement($element);

            if (! $link || ! $link->itemEndpoint) {
                return;
            }

            $cooldownRemaining = $this->cooldown->remaining($link, $element);
            $disabled = $cooldownRemaining > 0;
            $redirect = $element->getCpEditUrl();

            // Only a per-site-endpoints link scopes to one site; a link with a
            // single base endpoint always syncs the primary site, so don't pin
            // the button to the editor's current site there (it would just make
            // the sync build its item endpoint from a non-primary localization).
            $params = ['elementId' => $element->id];

            if ($link->siteHandles() !== []) {
                $params['site'] = $element->site->handle;
            }

            $event->html .= Html::button(Craft::t('influx', 'Sync from remote'), [
                'class'    => array_filter(['btn', 'formsubmit', $disabled ? 'disabled' : null]),
                'disabled' => $disabled,
                // The plugin's own accent — a blue→teal gradient, white text, no
                // icon — so it reads as an Influx action distinct from Craft's
                // teal "submit" buttons (see Entry.dc.html). `order: -1` pulls it
                // to the far left of #action-buttons: Craft prepends its own
                // buttons (e.g. "Create a draft") before firing the event that
                // appends ours, so DOM order alone leaves it mid-row.
                'style' => 'order: -1; background: linear-gradient(100deg, var(--blue-600) 45%, var(--teal-600) 115%); color: var(--white);',
                'data'  => array_filter([
                    'action'   => 'influx/synchronization/element',
                    'params'   => $params,
                    'form'     => 'false',
                    'redirect' => $redirect ? Craft::$app->getSecurity()->hashData($redirect) : null,
                ]),
                'title' => $disabled
                    ? Craft::t('influx', 'Available again in {n}s', ['n' => $cooldownRemaining])
                    : '',
            ]);

            // Sits right after the button (same `order: -1`), separating it
            // from Craft's native button group to its right.
            $event->html .= Html::tag('div', '', [
                'class' => 'influx-sync-divider',
                'style' => 'order: -1; align-self: stretch; width: 1px; margin-block: 5px; background: var(--gray-200);',
            ]);
        });
    }
}
