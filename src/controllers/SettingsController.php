<?php

namespace GlueAgency\Influx\controllers;

use Craft;
use GlueAgency\Influx\Influx;
use yii\base\Action;
use yii\web\Response;

class SettingsController extends AbstractController
{
    /**
     * Settings are Project Config — admin territory, like {@see LinksController}.
     * requireAdmin() (with its default allowAdminChanges check) gates both view
     * and save, mirroring how the Settings nav item only appears when admin
     * changes are allowed.
     */
    protected function requireAccess(Action $action): void
    {
        $this->requireAdmin();
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
                'synchronisation' => [
                    'label' => Craft::t('influx', 'Synchronisation'),
                    'url'   => '#synchronisation',
                ],
                'logging' => [
                    'label' => Craft::t('influx', 'Logging'),
                    'url'   => '#logging',
                ],
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
            'defaultItemCooldown' => (int) $request->getBodyParam('defaultItemCooldown', 30),
            'loggingEnabled'      => (bool) $request->getBodyParam('loggingEnabled', true),
            'logRetentionDays'    => (int) $request->getBodyParam('logRetentionDays', 14),
            'followRedirects'     => (bool) $request->getBodyParam('followRedirects', false),
        ];

        if (! Craft::$app->getPlugins()->savePluginSettings($plugin, $data)) {
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
