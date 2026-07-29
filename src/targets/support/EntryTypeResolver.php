<?php

namespace GlueAgency\Influx\targets\support;

use craft\models\EntryType;
use craft\models\Section;
use GlueAgency\Influx\exceptions\InfluxException;
use GlueAgency\Influx\helpers\Compat;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\targets\EntryTarget;

/**
 * Resolves a link's `elementCriteria` (section/type handles) to the actual
 * Section + EntryType pair. This resolution used to exist three times —
 * {@see EntryTarget::buildNew()}, {@see EntryTarget::getMappableFields()} and the
 * endpoint-token field walker — each one free to drift from the others.
 *
 * The criteria keys belong to {@see EntryTarget}, which declares them; this
 * reads them through {@see Link::criterion()} with that target's constants
 * rather than repeating the literals.
 */
class EntryTypeResolver
{
    /**
     * Strict resolution for write paths (building new entries): every
     * misconfiguration throws with a message naming the offending handle.
     *
     * Entry types are global in Craft 5, so a configured type handle is only
     * accepted when it is actually attached to the resolved section — never
     * looked up globally.
     *
     * @return array{0: Section, 1: EntryType}
     * @throws InfluxException when the section criteria is missing, the
     * section doesn't exist, a configured type isn't attached to it, or
     * the section has no usable entry type.
     */
    public function resolve(Link $link): array
    {
        if (! ($sectionHandle = $link->criterion(EntryTarget::CRITERIA_SECTION))) {
            throw new InfluxException(
                "Link '{$link->handle}' must declare elementCriteria.section for Entry targets.",
            );
        }

        if (! ($section = Compat::getSectionByHandle($sectionHandle))) {
            throw new InfluxException("Section '{$sectionHandle}' does not exist.");
        }

        $typeHandle = $link->criterion(EntryTarget::CRITERIA_TYPE);

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
        $sectionHandle = $link->criterion(EntryTarget::CRITERIA_SECTION);

        if (! $sectionHandle) {
            return null;
        }

        $section = Compat::getSectionByHandle($sectionHandle);

        if (! $section) {
            return null;
        }

        $typeHandle = $link->criterion(EntryTarget::CRITERIA_TYPE);
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
