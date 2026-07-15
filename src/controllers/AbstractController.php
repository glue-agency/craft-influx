<?php

namespace GlueAgency\Influx\controllers;

use Craft;
use craft\web\Controller;
use GlueAgency\Influx\web\assets\links\InfluxAsset;
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
        $this->registerCpAssets();

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
     * Register the plugin's single CP asset bundle for every screen that
     * renders a full HTML page. Skipped for the SPA's JSON data routes, where
     * there's no page to style and the bundle would just be published unused.
     */
    protected function registerCpAssets(): void
    {
        if (Craft::$app->getRequest()->getAcceptsJson()) {
            return;
        }

        Craft::$app->getView()->registerAssetBundle(InfluxAsset::class);
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

    /**
     * Read a query param as an int, falling back to `$default` when absent,
     * then clamping to `[$min, $max]` (either bound optional). The shared
     * shape behind the paginators' `page` (min 1) and the debug view's `limit`
     * (min 1, max 500).
     */
    protected function intQueryParam(string $name, int $default, ?int $min = null, ?int $max = null): int
    {
        $value = (int) Craft::$app->getRequest()->getQueryParam($name, $default);

        if ($min !== null) {
            $value = max($min, $value);
        }

        if ($max !== null) {
            $value = min($max, $value);
        }

        return $value;
    }

    /**
     * Read a query param as a trimmed string, normalising an absent or
     * whitespace-only value to null (so callers can `?? default` / whitelist
     * without re-trimming).
     */
    protected function stringQueryParam(string $name): ?string
    {
        return trim((string) Craft::$app->getRequest()->getQueryParam($name, '')) ?: null;
    }
}
