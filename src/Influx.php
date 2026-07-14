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
use craft\services\Gc;
use craft\services\ProjectConfig as ProjectConfigService;
use craft\web\UrlManager;
use craft\web\View;
use GlueAgency\Influx\integrations\feedme\services\FeedMeService;
use GlueAgency\Influx\models\Link;
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
    public string $schemaVersion = '1.0.0';

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
     * Add a "Sync from remote" affordance to the edit page of any entry the
     * plugin targets, offering every link that both structurally targets the
     * entry AND has a resource (item) endpoint. One targeting link renders a
     * single button; several render a menu, one item per link.
     *
     * A link is offered even when the entry can't currently be synced (no
     * match value, or an active cool-down): those render as a DISABLED button
     * / menu item with a Craft `.info` icon explaining why, so the action is
     * discoverable rather than silently absent.
     *
     * Additional buttons render INSIDE the edit page's #main-form, so this
     * must not output a <form> of its own: forms can't nest, the browser
     * would close #main-form early and every field input (plus Craft's hidden
     * action/redirect inputs) would fall outside it — silently breaking entry
     * saving and drafts. Instead each button uses Craft's `formsubmit` pattern;
     * `form: false` makes the CP JS post the action through a detached
     * temporary form (CSRF included) rather than hijacking the closest form.
     * Markup is built by a CP template (influx/_sync-button) rather than
     * concatenated here, so the branching and styling live in one readable place.
     */
    protected function registerEntrySyncButton(): void
    {
        Event::on(Entry::class, Entry::EVENT_DEFINE_ADDITIONAL_BUTTONS, function(DefineHtmlEvent $event) {
            /** @var Entry $element */
            $element = $event->sender;

            $candidates = [];

            foreach ($this->links->findLinksForElement($element) as $link) {
                // A resource mapping is required to sync a single element — a
                // link without one can only run the full-list sweep.
                if (! $link->itemEndpoint) {
                    continue;
                }

                $candidates[] = $this->syncButtonCandidate($link, $element);
            }

            if ($candidates === []) {
                return;
            }

            $redirect = $element->getCpEditUrl();

            $event->html .= Craft::$app->getView()->renderTemplate('influx/_sync-button', [
                'candidates'     => $candidates,
                'hashedRedirect' => $redirect ? Craft::$app->getSecurity()->hashData($redirect) : null,
            ], View::TEMPLATE_MODE_CP);
        });
    }

    /**
     * Build one candidate descriptor for the sync button/menu: the link's
     * display name, whether it's currently syncable, the reason it isn't (for
     * the disabled state's info HUD), and the `data-params` the formsubmit
     * posts — carrying the explicit link handle so the action syncs THIS link
     * ({@see \GlueAgency\Influx\controllers\SynchronizationController::actionElement()}).
     *
     * @return array{name: string, enabled: bool, reason: ?string, params: array<string, mixed>}
     */
    protected function syncButtonCandidate(Link $link, Entry $element): array
    {
        $enabled = true;
        $reason = null;

        $matchAttr = $link->matchAttribute();

        if (! $matchAttr || $element->{$matchAttr} === null || $element->{$matchAttr} === '') {
            $enabled = false;
            $reason = Craft::t('influx', 'This entry has no value for the match field, so it can’t be synced from remote.');
        } else {
            $remaining = $this->cooldown->remaining($link, $element);

            if ($remaining > 0) {
                $enabled = false;
                $reason = Craft::t('influx', 'Recently synced');
            }
        }

        // Only a per-site-endpoints link scopes to one site; a link with a
        // single base endpoint always syncs the primary site, so don't pin the
        // action to the editor's current site there (it would just make the
        // sync build its item endpoint from a non-primary localization).
        $params = ['elementId' => $element->id, 'link' => $link->handle];

        if ($link->siteHandles() !== []) {
            $params['site'] = $element->site->handle;
        }

        return [
            'name'    => $link->name,
            'enabled' => $enabled,
            'reason'  => $reason,
            'params'  => $params,
        ];
    }
}
