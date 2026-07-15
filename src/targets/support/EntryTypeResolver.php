<?php

namespace GlueAgency\Influx\targets\support;

use craft\models\EntryType;
use craft\models\Section;
use GlueAgency\Influx\exceptions\InfluxException;
use GlueAgency\Influx\helpers\Compat;
use GlueAgency\Influx\models\Link;

/**
 * Resolves a link's `elementCriteria` (section/type handles) to the actual
 * Section + EntryType pair. This resolution used to exist three times —
 * {@see \GlueAgency\Influx\targets\EntryTarget::buildNew()},
 * {@see \GlueAgency\Influx\targets\EntryTarget::getMappableFields()} and the
 * endpoint-token field walker — each one free to drift from the others.
 */
class EntryTypeResolver
{
    /**
     * Strict resolution for write paths (building new entries): every
     * misconfiguration throws with a message naming the offending handle.
     *
     * @return array{0: Section, 1: EntryType}
     * @throws InfluxException when the section criteria is missing, the
     * section doesn't exist, a configured type isn't attached to it, or
     * the section has no usable entry type.
     */
    public function resolve(Link $link): array
    {
        if (! ($sectionHandle = $link->elementCriteria['section'] ?? null)) {
            throw new InfluxException(
                "Link '{$link->handle}' must declare elementCriteria.section for Entry targets.",
            );
        }

        if (! ($section = Compat::getSectionByHandle($sectionHandle))) {
            throw new InfluxException("Section '{$sectionHandle}' does not exist.");
        }

        $typeHandle = $link->elementCriteria['type'] ?? null;

        // Entry types are global in Craft 5; ensure the resolved type is attached to the section
        $sectionEntryTypes = $section->getEntryTypes();
        $entryType = null;

        if ($typeHandle) {
            foreach ($sectionEntryTypes as $candidate) {
                if ($candidate->handle === $typeHandle) {
                    $entryType = $candidate;

                    break;
                }
            }

            if (! $entryType) {
                throw new InfluxException(
                    "Entry type '{$typeHandle}' is not attached to section '{$sectionHandle}'.",
                );
            }
        } else {
            $entryType = $sectionEntryTypes[0] ?? null;
        }

        if (! $entryType) {
            throw new InfluxException("Section '{$sectionHandle}' has no usable entry type.");
        }

        return [$section, $entryType];
    }

    /**
     * Lenient resolution for UI/read paths (mappable fields, token pickers):
     * anything unresolvable yields null, and an unknown type handle falls
     * back to the section's first entry type instead of failing.
     *
     * @return array{0: Section, 1: EntryType}|null
     */
    public function tryResolve(Link $link): ?array
    {
        $sectionHandle = $link->elementCriteria['section'] ?? null;

        if (! $sectionHandle) {
            return null;
        }

        $section = Compat::getSectionByHandle($sectionHandle);

        if (! $section) {
            return null;
        }

        $typeHandle = $link->elementCriteria['type'] ?? null;
        $entryTypes = $section->getEntryTypes();
        $entryType = null;

        if ($typeHandle) {
            foreach ($entryTypes as $candidate) {
                if ($candidate->handle === $typeHandle) {
                    $entryType = $candidate;

                    break;
                }
            }
        }
        $entryType ??= $entryTypes[0] ?? null;

        return $entryType ? [$section, $entryType] : null;
    }
}
