<?php

namespace GlueAgency\Influx\controllers;

use Craft;
use craft\web\Controller;
use GlueAgency\Influx\Influx;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\records\Log as LogRecord;
use GlueAgency\Influx\records\LogItem as LogItemRecord;
use GlueAgency\Influx\web\assets\cp\InfluxAsset;
use GlueAgency\Influx\web\SharedComponentTranslations;
use ReflectionClass;
use Throwable;
use yii\base\Action;
use yii\web\ForbiddenHttpException;
use yii\web\HttpException;
use yii\web\NotFoundHttpException;

/**
 * Shared base for Influx's CP controllers. Centralises what every one of them
 * needs: anonymous access is always off, an access gate runs in beforeAction (so
 * it can't be forgotten or ordered after a resource lookup), a single read-only /
 * writeable check derived from `allowAdminChanges`, one JSON failure envelope for
 * every JSON route, the request-param readers, and the resource
 * lookup-or-404 helpers — so no controller reaches for a record class itself.
 *
 * The default gate is the plugin permission; controllers whose access model
 * differs (e.g. {@see LinksController}, which gates on admin per action)
 * override {@see requireAccess()} rather than re-implementing beforeAction.
 */
abstract class AbstractController extends Controller
{
    protected array|int|bool $allowAnonymous = false;

    /**
     * Answer any uncaught exception on a JSON route with the plugin's single
     * failure envelope — `{success: false, message, type}` plus the exception's
     * own status code — instead of an HTML error page inside a fetch.
     *
     * `message` is the key both SPA readers normalize on
     * (`builder/api.js`'s ApiError and `lib/requestError.js`), and `success:
     * false` is what api.js treats as a failure even on a 2xx, so every JSON
     * route now fails the same way whether it returned an envelope deliberately
     * or simply threw.
     *
     * HTML routes are left alone (rethrow): Craft's error page is the right
     * answer for a browser navigation, and a JSON body there would be
     * unreadable. That also means a non-JSON request to a JSON-only route gets
     * the HTML error page for its `requireAcceptsJson()` rejection.
     *
     * The message is passed through verbatim, where Yii's error handler would
     * replace a non-HTTP exception's with "An internal server error occurred."
     * outside devMode: every route reachable here is admin- or
     * plugin-permission-gated, and the SPA's own error panels are the fastest
     * read on a failing feed. The exception is logged either way.
     */
    public function runAction($id, $params = [])
    {
        try {
            return parent::runAction($id, $params);
        } catch (Throwable $e) {
            if (! Craft::$app->getRequest()->getAcceptsJson()) {
                throw $e;
            }

            if (! $e instanceof HttpException) {
                Craft::error($e, __METHOD__);
            }

            Craft::$app->getResponse()->setStatusCode($e instanceof HttpException ? $e->statusCode : 500);

            return $this->asJson([
                'success' => false,
                'message' => $e->getMessage(),
                'type'    => (new ReflectionClass($e))->getShortName(),
            ]);
        }
    }

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
     * Register one Vue app's UI strings for the screen about to render, together
     * with the shared component tree's — every app mounts something out of
     * `assets/cp/src/components`, so those strings belong on every screen that
     * mounts an app. Overlap between the two catalogues is harmless:
     * `registerTranslations()` keys each message once.
     *
     * Without this the app's `$t()` calls silently fall back to their English
     * source strings, so a screen that mounts an app must call it.
     *
     * @param string[] $strings
     */
    protected function registerAppTranslations(array $strings): void
    {
        Craft::$app->getView()->registerTranslations(
            'influx',
            array_merge($strings, SharedComponentTranslations::strings()),
        );
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
     * The full guard set for a JSON write endpoint: POST only, JSON only, and
     * not in a read-only environment. One call so a route can't accidentally
     * carry two of the three.
     */
    protected function requireJsonWrite(): void
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->assertWriteable();
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

    /**
     * Read a query param that must be one of `$allowed`, falling back to
     * `$default` when it's absent or isn't. Every toolbar filter validates
     * against what currently exists (or against its enum's values), so a stale
     * query string degrades to the default instead of filtering to nothing.
     *
     * @param string[] $allowed
     */
    protected function oneOfQueryParam(string $name, array $allowed, ?string $default = null): ?string
    {
        $value = $this->stringQueryParam($name);

        return $value !== null && in_array($value, $allowed, true) ? $value : $default;
    }

    /**
     * A link by numeric id (route segment) or handle (query param), 404ing when
     * it doesn't exist. An absent / blank key never reaches the DB — a request
     * that named no link is the same "not found" answer.
     *
     * @throws NotFoundHttpException
     */
    protected function linkOr404(int|string|null $idOrHandle): Link
    {
        $links = Influx::getInstance()->links;

        $link = match (true) {
            is_int($idOrHandle)                          => $links->getLinkById($idOrHandle),
            is_string($idOrHandle) && $idOrHandle !== '' => $links->getLinkByHandle($idOrHandle),
            default                                      => null,
        };

        if (! $link) {
            throw new NotFoundHttpException(
                $idOrHandle === null || $idOrHandle === '' ? 'Link not found.' : "Link {$idOrHandle} not found.",
            );
        }

        return $link;
    }

    /**
     * @throws NotFoundHttpException
     */
    protected function logOr404(int $id): LogRecord
    {
        $log = Influx::getInstance()->logs->getLogById($id);

        if (! $log) {
            throw new NotFoundHttpException("Log #{$id} not found.");
        }

        return $log;
    }

    /**
     * @throws NotFoundHttpException
     */
    protected function logItemOr404(int $id): LogItemRecord
    {
        $item = Influx::getInstance()->logs->getLogItemById($id);

        if (! $item) {
            throw new NotFoundHttpException("Log item #{$id} not found.");
        }

        return $item;
    }
}
