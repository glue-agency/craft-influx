<?php

namespace GlueAgency\Influx\controllers;

use Craft;
use craft\web\Controller;
use GlueAgency\Influx\Influx;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

class SettingsController extends Controller
{
    protected array|int|bool $allowAnonymous = false;

    public function beforeAction($action): bool
    {
        $this->requirePermission('accessPlugin-influx');
        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            throw new ForbiddenHttpException('Administrative changes are disallowed in this environment.');
        }
        return parent::beforeAction($action);
    }

    public function actionEdit(): Response
    {
        $settings = Influx::getInstance()->getSettings();

        return $this->asCpScreen()
            ->title(Craft::t('influx', 'Settings'))
            ->addCrumb(Craft::t('influx', 'Influx'), 'influx')
            ->action('influx/settings/save')
            ->redirectUrl('influx/settings')
            ->tabs([
                'synchronisation' => ['label' => Craft::t('influx', 'Synchronisation'), 'url' => '#synchronisation'],
                'logging'         => ['label' => Craft::t('influx', 'Logging'),         'url' => '#logging'],
            ])
            ->contentTemplate('influx/settings/index', [
                'settings' => $settings,
            ]);
    }

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $plugin = Influx::getInstance();

        $data = [
            'defaultItemCooldown' => (int)$request->getBodyParam('defaultItemCooldown', 30),
            'loggingEnabled'      => (bool)$request->getBodyParam('loggingEnabled', true),
            'logRetentionDays'    => (int)$request->getBodyParam('logRetentionDays', 0),
        ];

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $data)) {
            Craft::$app->getSession()->setError(
                Craft::t('influx', 'Couldn’t save settings.')
            );
            Craft::$app->getUrlManager()->setRouteParams([
                'settings' => $plugin->getSettings(),
            ]);
            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('influx', 'Settings saved.'));
        return $this->redirectToPostedUrl();
    }
}
