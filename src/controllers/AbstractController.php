<?php

namespace GlueAgency\Influx\controllers;

use Craft;
use craft\web\Controller;
use yii\base\Action;
use yii\web\ForbiddenHttpException;

/**
 * Shared base for Influx's CP controllers. Centralises the three things every
 * one of them needs: anonymous access is always off, an access gate runs in
 * beforeAction (so it can't be forgotten or ordered after a resource lookup),
 * and a single read-only / writeable check derived from `allowAdminChanges`.
 *
 * The default gate is the plugin permission; controllers whose access model
 * differs (e.g. {@see LinksController}, which gates on admin per action)
 * override {@see requireAccess()} rather than re-implementing beforeAction.
 */
abstract class AbstractController extends Controller
{
    protected array|int|bool $allowAnonymous = false;

    public function beforeAction($action): bool
    {
        if (! parent::beforeAction($action)) {
            return false;
        }

        $this->requireAccess($action);

        return true;
    }

    /**
     * Access gate for this controller's actions, run once in beforeAction.
     * Default: require the plugin permission. Override for a different model.
     */
    protected function requireAccess(Action $action): void
    {
        $this->requirePermission('accessPlugin-influx');
    }

    /**
     * Whether this environment forbids administrative (Project Config) changes.
     * The links + settings screens render read-only and reject writes when true.
     */
    protected function readOnly(): bool
    {
        return ! Craft::$app->getConfig()->getGeneral()->allowAdminChanges;
    }

    /**
     * Guard a mutating action: 403 when the environment is read-only. The one
     * definition of the "no admin changes here" write-block, shared by every
     * controller that persists configuration.
     *
     * @throws ForbiddenHttpException
     */
    protected function assertWriteable(): void
    {
        if ($this->readOnly()) {
            throw new ForbiddenHttpException(
                Craft::t('influx', 'Administrative changes are disallowed in this environment.'),
            );
        }
    }
}
