/**
 * Shared JSDoc type definitions for the LinkBuilder SPA — the PHP↔JS wire
 * contract, documented once. Nothing here executes; import types via:
 *
 *   @param {import('./types.js').LinkPayload} link
 *
 * PHP is the authority for these shapes: LinkBuilderService::serializeLink()
 * (LinkPayload), the target's getMappableFields() (MappableField), and
 * DataService::inspect() (SampleReport). Change them there first.
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
 * @property {Object<string, Mapping>} [fields] Recursive sub-mappings for a related element's custom fields.
 * @property {Object<string, Mapping>} [nativeFields] Recursive sub-mappings for a related element's native attrs.
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
 * @property {Object<string, string>} elementCriteria e.g. {section, type, author}.
 * @property {?string} endpoint
 * @property {?string} itemEndpoint
 * @property {Array<{site: string, endpoint: string}>} siteEndpoints ordered per-site endpoints (run order).
 * @property {Object<string, *>} auth {type, token?, header?, param?} or empty.
 * @property {?string} rootNode
 * @property {?string} paginatorNode
 * @property {?string} totalCountNode response path to the total item count, if the feed reports one.
 * @property {?string} pageCountNode response path to the total page count, if the feed reports one.
 * @property {{attribute?: string}} match
 * @property {Object<string, Mapping>} mappings field handle → mapping.
 * @property {string[]} processing Subset of create/update/disable/delete/delete-for-site.
 * @property {Object<string, {since: string, queryParam: string, format?: string}>} offset
 * @property {boolean} backup
 */

/**
 * One mappable field reported by the element target.
 *
 * @typedef {Object} MappableField
 * @property {string} handle
 * @property {string} name
 * @property {boolean} native
 * @property {string} group Field-layout tab name, or 'Native'.
 * @property {('text'|'select'|'element')} defaultType
 * @property {Object<string, string>} [options] For defaultType 'select': value → label.
 * @property {string} [elementType] For defaultType 'element': FQCN to pick from.
 * @property {?string} [fieldClass] FQCN of the Craft field class.
 * @property {Object<string, *>} [fieldMeta] Per-kind UI meta: {schema, subfieldsOnly, ...} — an extras block exists when schema is non-empty.
 */

/**
 * DataService::inspect() output — the "Fetch sample" report.
 *
 * @typedef {Object} SampleReport
 * @property {string} url
 * @property {?string} rootNode
 * @property {string[]} rootNodeCandidates
 * @property {?string} paginatorNode
 * @property {string[]} paginatorNodeCandidates
 * @property {?Object} sampleItem
 * @property {Array<{field: string, type: string, node: string}>} mappingSuggestions
 * @property {SelectOption[]} flatNodes
 */

/**
 * The bootstrap envelope that hydrates the SPA.
 *
 * @typedef {Object} BootstrapResponse
 * @property {LinkPayload} link
 * @property {Object} options elementTypes, sections, sectionEntryTypes, sites, processingActions, authTypes, authStrategies.
 * @property {Object} meta isNew, readOnly, handle, csrfTokenName, csrfToken, actionUrls, envSuggestions.
 */

export {};
