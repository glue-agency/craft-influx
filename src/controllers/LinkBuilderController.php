<?php

namespace TDM\Influx\controllers;

use Craft;
use craft\web\Controller;
use TDM\Influx\Influx;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * JSON CP routes powering the LinkBuilder Vue SPA. Thin layer over
 * {@see \TDM\Influx\services\LinkBuilderService} — request guards, body
 * parsing, and JSON wrapping; everything else lives in the service so it
 * can be reused from console / queue contexts too.
 *
 * Read-only environments (`allowAdminChanges = false`) get a 403 on any
 * mutating route, consistent with how {@see LinksController} gates writes.
 */
class LinkBuilderController extends Controller
{
    protected array|bool|int $allowAnonymous = false;

    public function beforeAction($action): bool
    {
        $this->requirePermission('accessPlugin-influx');
        return parent::beforeAction($action);
    }

    /**
     * Wrap the standard runAction so any uncaught exception comes back as
     * a JSON `{error, type}` envelope instead of an HTML 500. The SPA
     * surfaces the message inline — much friendlier than a blank screen.
     */
    public function runAction($id, $params = [])
    {
        try {
            return parent::runAction($id, $params);
        } catch (\yii\web\HttpException $e) {
            // HTTP-shaped errors (404, 403, …) keep their status code.
            Craft::$app->getResponse()->setStatusCode($e->statusCode);
            return $this->asJson([
                'ok'    => false,
                'error' => $e->getMessage(),
                'type'  => (new \ReflectionClass($e))->getShortName(),
            ]);
        } catch (\Throwable $e) {
            Craft::error($e, __METHOD__);
            Craft::$app->getResponse()->setStatusCode(500);
            return $this->asJson([
                'ok'    => false,
                'error' => $e->getMessage(),
                'type'  => (new \ReflectionClass($e))->getShortName(),
            ]);
        }
    }

    /**
     * Hydrate the SPA with everything it needs to mount.
     *
     *   GET influx/link-builder/bootstrap?handle=foo
     */
    public function actionBootstrap(): Response
    {
        $this->requireAcceptsJson();

        $handle = Craft::$app->getRequest()->getQueryParam('handle') ?: null;
        $readOnly = !Craft::$app->getConfig()->getGeneral()->allowAdminChanges;

        return $this->asJson(
            Influx::getInstance()->linkBuilder->bootstrap($handle, $readOnly),
        );
    }

    /**
     * Persist a link from the SPA payload.
     *
     *   POST influx/link-builder/save
     *
     * Body: JSON-serialised link state (see LinkBuilderService::serializeLink()).
     */
    public function actionSave(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->assertWriteable();

        $payload = $this->jsonBody();
        $result = Influx::getInstance()->linkBuilder->save($payload);

        return $this->asJson($result);
    }

    /**
     * Reactive update — mappable fields + match-attribute options for a
     * given element type / criteria combination. Called when the user
     * changes the section / entry-type dropdowns.
     *
     *   GET influx/link-builder/mappable-fields?elementType=...&criteria[section]=...&criteria[type]=...
     */
    public function actionMappableFields(): Response
    {
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $elementType = (string)$request->getQueryParam('elementType', '');
        $criteria = (array)$request->getQueryParam('criteria', []);

        if ($elementType === '') {
            throw new BadRequestHttpException('elementType is required.');
        }

        return $this->asJson(
            Influx::getInstance()->linkBuilder->mappableFields($elementType, $criteria),
        );
    }

    /**
     * Fetch a sample of the configured endpoint and report rootNode /
     * paginatorNode candidates + sample item. Powers the Pagination tab's
     * "Fetch sample" button. Operates on the in-flight link payload so
     * users can sample without saving.
     *
     *   POST influx/link-builder/sample
     *
     * Body: `{endpoint, rootNode?, paginatorNode?, auth?}`
     */
    public function actionSample(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $payload = $this->jsonBody();
        return $this->asJson(
            Influx::getInstance()->linkBuilder->sample($payload),
        );
    }

    /**
     * Render Craft's native `forms/elementSelect` for the Mapping tab's
     * default-value editor. The SPA mounts the returned HTML into a
     * Vue-controlled <div> and instantiates BaseElementSelectInput from
     * the jsSettings — that gives users the same element chip + modal UX
     * as everywhere else in the CP without re-implementing it in Vue.
     *
     *   GET influx/link-builder/render-element-select?elementType=...&ids[]=...
     */
    public function actionRenderElementSelect(): Response
    {
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $elementType = (string)$request->getQueryParam('elementType', '');
        $ids = (array)$request->getQueryParam('ids', []);

        if ($elementType === '') {
            throw new BadRequestHttpException('elementType is required.');
        }

        return $this->asJson(
            Influx::getInstance()->linkBuilder->renderElementSelect($elementType, $ids),
        );
    }

    /**
     * Resource Endpoint token-picker suggestions for the SPA — same data the
     * Twig form pre-computes, just reactive when criteria change.
     *
     *   GET influx/link-builder/endpoint-token-suggestions?elementType=...&criteria[...]=...
     */
    public function actionEndpointTokenSuggestions(): Response
    {
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $elementType = (string)$request->getQueryParam('elementType', '');
        $criteria = (array)$request->getQueryParam('criteria', []);

        if ($elementType === '') {
            throw new BadRequestHttpException('elementType is required.');
        }

        return $this->asJson([
            'suggestions' => Influx::getInstance()->linkBuilder->endpointTokenSuggestions($elementType, $criteria),
        ]);
    }

    protected function jsonBody(): array
    {
        $raw = Craft::$app->getRequest()->getRawBody();
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new BadRequestHttpException('Request body must be a JSON object.');
        }
        return $decoded;
    }

    protected function assertWriteable(): void
    {
        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            throw new ForbiddenHttpException('Administrative changes are disallowed in this environment.');
        }
    }
}
