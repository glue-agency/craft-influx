<?php

namespace TDM\Influx;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\DefineHtmlEvent;
use craft\helpers\Html;
use craft\helpers\UrlHelper;
use craft\elements\Entry;
use yii\base\Event;
use TDM\Influx\models\Settings;
use TDM\Influx\services\FeedsService;
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
 * @property FeedsService $feeds
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
    public string $schemaVersion = '1.0.0';

    public bool $hasCpSettings = false;

    public bool $hasCpSection = false;

    public static function config(): array
    {
        return [
            'components' => [
                'feeds'           => FeedsService::class,
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

        Craft::$app->onInit(function () {
            $this->registerControllers();
            $this->registerBuiltInTargets();
            $this->registerEntrySyncButton();
        });
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    protected function registerControllers(): void
    {
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'TDM\\Influx\\console\\controllers';
            return;
        }

        $this->controllerNamespace = 'TDM\\Influx\\controllers';
    }

    protected function registerBuiltInTargets(): void
    {
        $this->targets->register(EntryTarget::class);
    }

    /**
     * Add a "Sync from remote" button to the edit page of any entry that has
     * been linked to a feed via its match attribute. The button is rendered
     * only when at least one feed claims this element.
     */
    protected function registerEntrySyncButton(): void
    {
        Event::on(Entry::class, Entry::EVENT_DEFINE_ADDITIONAL_BUTTONS, function (DefineHtmlEvent $event) {
            /** @var Entry $element */
            $element = $event->sender;

            $feed = $this->feeds->findFeedForElement($element);
            if (!$feed || !$feed->itemEndpoint) {
                return;
            }

            $cooldownRemaining = $this->cooldown->remaining($feed, $element);
            $disabled = $cooldownRemaining > 0;

            // POST form, not <a>, so a stray browser prefetch / revisit can't
            // re-trigger a sync.
            $event->html .= Html::beginForm(
                UrlHelper::actionUrl('influx/synchronization/element'),
                'post',
                ['class' => 'inline-block'],
            );
            $event->html .= Html::hiddenInput('elementId', (string)$element->id);
            $event->html .= Html::submitButton(Craft::t('influx', 'Sync from remote'), [
                'class' => array_filter(['btn', $disabled ? 'disabled' : null]),
                'disabled' => $disabled,
                'data' => ['icon' => 'refresh'],
                'title' => $disabled
                    ? Craft::t('influx', 'Available again in {n}s', ['n' => $cooldownRemaining])
                    : '',
            ]);
            $event->html .= Html::endForm();
        });
    }
}
