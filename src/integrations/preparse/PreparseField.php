<?php

namespace GlueAgency\Influx\integrations\preparse;

use Craft;
use craft\base\FieldInterface as CraftFieldInterface;
use GlueAgency\Influx\fields\Field;
use GlueAgency\Influx\schema\MappingSchema;
use GlueAgency\Influx\schema\MappingSchemaBuilder;
use GlueAgency\Influx\sync\FieldContext;

/**
 * Mapping strategy for a Preparse field — whose whole job is to declare that the
 * field CAN'T be mapped.
 *
 * A Preparse field's value is a Twig template rendered against the element, and
 * the plugin re-renders it on `Elements::EVENT_BEFORE_SAVE_ELEMENT` (or after
 * propagate, per the field's own `parseBeforeSave`), walking every Preparse field
 * on the layout unconditionally. So the template always wins — over a sync AND
 * over anything an editor types.
 *
 * Which made a mapped one worse than useless. The write was discarded on every
 * save, and the row churned: the stored value is the rendered template while the
 * incoming one is the feed's, so they never matched and the element re-saved with
 * a fresh revision on every single run — the failure {@see \GlueAgency\Influx\fields\Time} and {@see \GlueAgency\Influx\fields\Money}
 * used to have, except here there's no value worth writing at all.
 *
 * So the row keeps its label and says why, rather than vanishing: an operator
 * looking for the field should find it and learn why it isn't mappable, not
 * wonder where it went. Same reasoning as an entry's `uri`, which is matchable
 * but never writable because Craft derives it on save
 * ({@see \GlueAgency\Influx\schema\NativeAttributes}).
 *
 * Keyed to the `jalendport/craft-preparse` fork, the one still maintained for
 * Craft 5. Another fork is a subclass declaring its own `craftFieldClass()` and
 * nothing else — the class STRING is the only thing that differs, and an absent
 * one is an inert registry key ({@see \GlueAgency\Influx\fields\RichText}).
 */
class PreparseField extends Field
{
    public static function craftFieldClass(): ?string
    {
        return 'jalendport\preparse\fields\PreparseFieldType';
    }

    /**
     * The one thing this row has to say, as the same {@see MappingSchemaBuilder::note()}
     * node {@see \GlueAgency\Influx\fields\Matrix} and
     * {@see \GlueAgency\Influx\fields\Table} use for their "nothing to map yet"
     * copy.
     *
     * The note takes the SOURCE region — the cell the node select would have
     * occupied — rather than riding a flag of its own. That placement is the whole
     * declaration: there IS a source region, so the row still reads as a field an
     * operator went looking for, and what's in it says why nothing can be mapped
     * onto it. A {@see \GlueAgency\Influx\fields\Matrix} declares no source region
     * at all, which is a different statement: its value comes from sub-mappings.
     *
     * No default region either. A default is what a sync falls back to when the
     * feed carries nothing, and this field's value never comes from a sync.
     */
    public function schema(CraftFieldInterface $field): MappingSchema
    {
        return MappingSchemaBuilder::make()->mapping([
            'source' => fn(MappingSchemaBuilder $b) => $b->note([
                'text' => Craft::t('influx', 'This field can’t be mapped, its value is computed from a template.'),
            ]),
            'default' => false,
        ]);
    }

    /**
     * Never addressed, whatever the feed carries and whatever a stored mapping
     * from before this strategy existed says — so the applier skips the row
     * entirely and no write is ever attempted.
     */
    public function addressed(FieldContext $context): bool
    {
        return false;
    }

    /**
     * Unreachable in practice: {@see addressed()} gates the applier before this.
     * Null rather than a throw so a caller reaching it out of band gets the same
     * "nothing to write" answer.
     */
    public function parse(FieldContext $context): mixed
    {
        return null;
    }
}
