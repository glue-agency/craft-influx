/**
 * Shared JSDoc type definitions for the LinkBuilder SPA — the PHP↔JS wire
 * contract, documented once. Nothing here executes; import types via:
 *
 *   @param {import('./types.js').LinkPayload} link
 *
 * PHP is the authority for these shapes: LinkBuilderSerializer::serialize()
 * (LinkPayload), schema\MappableField::toArray() (MappableField), and
 * FeedInspector::report() (SampleReport — DataService::inspect() only delegates
 * to it). Change them there first.
 */

/** @typedef {{value: string, label: string}} SelectOption */

/** @typedef {Object<string, string[]>} ValidationErrors attribute → messages */

/**
 * One entry of `LinkPayload.mappings`. Mirrors PHP's FieldMapping config
 * shape — empty slots are pruned before save (see lib/mappings.js).
 *
 * @typedef {Object} Mapping
 * @property {string} [node] Hash dot-path into the remote item.
 * @property {*} [default] Fallback when the node is missing or empty.
 * @property {boolean} [useDefault] Apply `default` with no node mapped (the "— use default —" choice); without it a node-less default writes nothing.
 * @property {Object<string, *>} [options] Per-field-type options (match, mode, ...).
 * @property {Object<string, Mapping>} [fields] Sub-mappings the owning field strategy interprets: a related element's custom fields, or a Table field's columns (keyed by column id).
 * @property {Object<string, Mapping>} [nativeFields] Recursive sub-mappings for a related element's native attrs.
 * @property {Object<string, {fields?: Object<string, Mapping>, nativeFields?: Object<string, Mapping>}>} [blocks] Per-block-type sub-mapping trees for a Matrix field, keyed by block-type handle (see FieldMapping::$blocks).
 */

/**
 * The wire shape of a link — what bootstrap returns, what save() POSTs.
 *
 * @typedef {Object} LinkPayload
 * @property {?number} id
 * @property {?string} uid
 * @property {string} handle
 * @property {string} name
 * @property {string} elementType FQCN of the target element type.
 * @property {Object<string, string>} elementCriteria Keyed by the target's criteriaKeys(), e.g. {section, type} for entries; empty for a target that declares none.
 * @property {?string} endpoint
 * @property {?string} itemEndpoint
 * @property {Array<{site: string, endpoint: string}>} siteEndpoints ordered per-site endpoints (run order).
 * @property {Object<string, *>} auth {type} plus the keys that type's schema declares (username, token, header, param), or empty.
 * @property {?string} rootNode
 * @property {?string} paginatorNode
 * @property {?string} totalCountNode response path to the total item count, if the feed reports one.
 * @property {?string} pageCountNode response path to the total page count, if the feed reports one.
 * @property {{attribute?: string}} match
 * @property {Object<string, Mapping>} mappings field handle → mapping.
 * @property {string[]} processing Subset of create/update/disable/disable-for-site/delete/delete-for-site.
 * @property {Object<string, {since: string, queryParam: string, format?: string}>} offset
 * @property {boolean} backup
 */

/**
 * One mappable field reported by the element target. Pinned against
 * `tests/fixtures/mappable-field.json` from both sides (see
 * `__tests__/mappable-field.contract.test.js`).
 *
 * @typedef {Object} MappableField
 * @property {string} handle
 * @property {string} name
 * @property {boolean} native
 * @property {string} group Field-layout tab name, or 'Native'.
 * @property {('text'|'select'|'element')} defaultType
 * @property {Object<string, string>} [options] For defaultType 'select': value → label.
 * @property {string} [elementType] For defaultType 'element': FQCN to pick from.
 * @property {string} [fieldClass] FQCN of the Craft field class; absent for natives.
 * @property {Object<string, *>} [fieldMeta] Per-kind UI meta: {schema, subfieldsOnly, ...} — an extras block exists when schema is non-empty.
 */

/**
 * FeedInspector::report() output — the "Fetch sample" report.
 *
 * @typedef {Object} SampleReport
 * @property {string} url
 * @property {?string} rootNode
 * @property {string[]} rootNodeCandidates
 * @property {?string} paginatorNode
 * @property {string[]} paginatorNodeCandidates
 * @property {string[]} countNodeCandidates Scalar-leaf paths offered as totalCountNode / pageCountNode.
 * @property {?Object} sampleItem
 * @property {Array<{field: string, type: string, node: string}>} mappingSuggestions
 * @property {SelectOption[]} flatNodes
 * @property {?string} warning Server-translated reason the report is partial (no list of items resolved); the candidates are still populated so the root node can be picked.
 */

/**
 * The bootstrap envelope that hydrates the SPA.
 *
 * `options` and `meta` are grab-bags rather than fixed records, so they stay
 * loosely typed here; LinkBuilderOptionsPresenter and LinkBuilderService own
 * their shapes. The non-obvious members:
 *
 *   options.elementTypes[]      {value, label, criteria, multiSite, sweeping}
 *   options.sectionEntryTypes   sectionHandle → {typeHandle: typeName} (a map, not a list)
 *   options.processingActions[] {value, label, note, missingPolicy}
 *   options.authStrategies[]    {type, schema} — schema definitions, not select options
 *   meta.envSuggestions[]       {kind, label, data: [{name, hint, type}]}
 *
 * @typedef {Object} BootstrapResponse
 * @property {LinkPayload} link
 * @property {Object} options elementTypes, sections, sectionEntryTypes, sites, processingActions, authTypes, authStrategies.
 * @property {Object} meta isNew, readOnly, handle, uid, csrfTokenName, csrfToken, envSuggestions.
 */

/**
 * The failure envelope every JSON route answers with. Two producers, one shape:
 * AbstractController::runAction() catches an uncaught throwable and reports its
 * class in `type`, while an action that fails validation reports per-attribute
 * `errors` instead. Neither ever carries both.
 *
 * @typedef {Object} ErrorEnvelope
 * @property {false} success
 * @property {string} message
 * @property {string} [type] Short class name of the thrown exception.
 * @property {ValidationErrors} [errors] Present on a validation failure.
 */

/**
 * The enum-derived UI vocabulary, built by web\Vocabulary and carried on the log
 * and debug bootstrap configs under `vocabulary`. `lib/vocabulary.js` installs
 * it; a page that ships none falls back to `lib/vocabulary.generated.json`.
 *
 * @typedef {Object} Vocabulary
 * @property {Object<string, ('live'|'pending'|'expired')>} actionColors Action string — committed and `would-…` alike — → Craft status colour.
 * @property {Array<{key: string, action: ?string, label: string, tone: ?string}>} counters Log-viewer counters in display order, leading with `itemsSeen` (action null = clears the filter).
 */

export {};
