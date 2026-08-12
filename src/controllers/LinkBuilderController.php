<?php

namespace GlueAgency\Influx\controllers;

use Craft;
use GlueAgency\Influx\enums\Permission;
use GlueAgency\Influx\Influx;
use yii\base\Action;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * JSON CP routes powering the LinkBuilder Vue SPA. Thin layer over
 * {@see \GlueAgency\Influx\services\LinkBuilderService} — request guards, body
 * parsing, HTTP status decisions, and JSON wrapping; everything else lives in
 * the service so it can be reused from console / queue contexts too. The shared
 * `{success: false, message, type}` failure envelope every route here answers
 * with is {@see AbstractController::runAction()}'s.
 *
 * Anyone the builder renders read-only for — a read-only environment, or a
 * non-admin viewer — gets a 403 on any mutating route, consistent with how
 * {@see LinksController} gates writes.
 */
class LinkBuilderController extends AbstractController
{
    /**
     * Mirrors {@see LinksController}, whose screens these routes serve: `save`
     * is the one that writes Project Config, so it stays admin-and-
     * allowAdminChanges; the read / helper endpoints answer to
     * {@see Permission::VIEW_LINKS}, which is what lets a non-admin
     * viewer's builder mount at all. None of them write, and the payload they
     * hydrate is already marked read-only for that user.
     */
    protected function requireAccess(Action $action): void
    {
        parent::requireAccess($action);

        if ($action->id === 'save') {
            $this->requireAdmin();

            return;
        }

        $this->requirePermission(Permission::VIEW_LINKS->value);
    }

    /**
     * Hydrate the SPA with everything it needs to mount: an existing link
     * (`?id=42`), an unsaved copy of one (`?duplicateOf=42`), or a fresh draft
     * (neither). A link the service can't find is this layer's 404 to make.
     *
     *   GET influx/link-builder/bootstrap?id=42
     */
    public function actionBootstrap(): Response
    {
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $id = $request->getQueryParam('id');
        $id = $id !== null && $id !== '' ? (int) $id : null;
        $duplicateOf = $request->getQueryParam('duplicateOf');
        $duplicateOf = $duplicateOf !== null && $duplicateOf !== '' ? (int) $duplicateOf : null;

        $payload = Influx::getInstance()->linkBuilder->bootstrap($id, $duplicateOf, $this->readOnly());

        if ($payload === null) {
            throw new NotFoundHttpException('Link ' . ($duplicateOf ?? $id) . ' not found.');
        }

        return $this->asJson($payload);
    }

    /**
     * Persist a link from the SPA payload.
     *
     *   POST influx/link-builder/save
     *
     * Body: JSON-serialised link state (see LinkBuilderSerializer::serialize()).
     */
    public function actionSave(): Response
    {
        $this->requireJsonWrite();

        $result = Influx::getInstance()->linkBuilder->save($this->jsonBody());

        if (! ($result['success'] ?? false)) {
            Craft::$app->getResponse()->setStatusCode(400);
        }

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

        $criteria = Craft::$app->getRequest()->getQueryParam('criteria', []);

        return $this->asJson(
            Influx::getInstance()->linkBuilder->mappableFields($this->requiredElementType(), $criteria),
        );
    }

    /**
     * Fetch a sample of the configured endpoint and report rootNode /
     * paginatorNode candidates + sample item. Powers the Pagination tab's
     * "Fetch sample" button. Operates on the in-flight link payload so
     * users can sample without saving.
     *
     *   POST influx/link-builder/fetch-sample
     *
     * Body: `{endpoint, rootNode?, paginatorNode?, auth?}`
     */
    public function actionFetchSample(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $result = Influx::getInstance()->linkBuilder->fetchSample($this->jsonBody());

        if (! ($result['success'] ?? false)) {
            Craft::$app->getResponse()->setStatusCode(400);
        }

        return $this->asJson($result);
    }

    /**
     * Render Craft's native `forms/elementSelect` for the Mapping tab's
     * default-value editor. The SPA mounts the returned HTML into a
     * Vue-controlled <div> and instantiates BaseElementSelectInput from
     * the jsSettings — that gives users the same element chip + modal UX
     * as everywhere else in the CP without re-implementing it in Vue.
     *
     * `fieldHandle` is optional and names the custom field the default belongs
     * to, which shapes the picker after that field's own sources and relation
     * limit ({@see \GlueAgency\Influx\services\LinkBuilderService::elementSelectConfigFor()}).
     * The SPA sends it for custom-field rows only.
     *
     *   GET influx/link-builder/render-element-select?elementType=...&ids[]=...&fieldHandle=...
     */
    public function actionRenderElementSelect(): Response
    {
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $ids = $request->getQueryParam('ids', []);
        $fieldHandle = $request->getQueryParam('fieldHandle');

        return $this->asJson(
            Influx::getInstance()->linkBuilder->renderElementSelect(
                $this->requiredElementType(),
                $ids,
                $this->readOnly(),
                is_string($fieldHandle) && $fieldHandle !== '' ? $fieldHandle : null,
            ),
        );
    }

    /**
     * Craft's own icon picker for an Icon field's default cell, the counterpart of
     * {@see actionRenderElementSelect()}. Whether Pro icons are selectable is the
     * field's own setting, so the handle is what shapes the control.
     *
     *   GET influx/link-builder/render-icon-picker?fieldHandle=...&value=...
     */
    public function actionRenderIconPicker(): Response
    {
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $fieldHandle = $request->getQueryParam('fieldHandle');
        $value = $request->getQueryParam('value');

        return $this->asJson(
            Influx::getInstance()->linkBuilder->renderIconPicker(
                is_string($fieldHandle) && $fieldHandle !== '' ? $fieldHandle : null,
                is_string($value) ? $value : null,
                $this->readOnly(),
            ),
        );
    }

    /**
     * The options a lazily-declared default select fetches on first use — the
     * lists too big to ride every bootstrap, a field type's worth at a time
     * ({@see \GlueAgency\Influx\fields\Field::defaultOptions()}).
     *
     *   GET influx/link-builder/default-options?fieldHandle=...
     */
    public function actionDefaultOptions(): Response
    {
        $this->requireAcceptsJson();

        $fieldHandle = Craft::$app->getRequest()->getQueryParam('fieldHandle');

        return $this->asJson([
            'options' => Influx::getInstance()->linkBuilder->defaultOptionsFor(
                is_string($fieldHandle) && $fieldHandle !== '' ? $fieldHandle : null,
            ),
        ]);
    }

    /**
     * Resource Endpoint token-picker suggestions for the SPA — same data the
     * Twig form pre-computes, just reactive when criteria change. Served by the
     * service that owns the token vocabulary, not proxied through the builder.
     *
     *   GET influx/link-builder/endpoint-token-suggestions?elementType=...&criteria[...]=...
     */
    public function actionEndpointTokenSuggestions(): Response
    {
        $this->requireAcceptsJson();

        $criteria = Craft::$app->getRequest()->getQueryParam('criteria', []);

        return $this->asJson([
            'suggestions' => Influx::getInstance()->endpointTokens->suggestionsFor($this->requiredElementType(), $criteria),
        ]);
    }

    /**
     * The `elementType` query param the three reactive endpoints all key off.
     *
     * @throws BadRequestHttpException
     */
    protected function requiredElementType(): string
    {
        $elementType = Craft::$app->getRequest()->getQueryParam('elementType');

        if (! $elementType) {
            throw new BadRequestHttpException('elementType is required.');
        }

        return (string) $elementType;
    }

    protected function jsonBody(): array
    {
        $raw = Craft::$app->getRequest()->getRawBody();

        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            throw new BadRequestHttpException('Request body must be a JSON object.');
        }

        return $decoded;
    }
}
