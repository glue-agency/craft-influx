<?php

namespace GlueAgency\Influx\schema;

use Craft;
use craft\models\EntryType;
use GlueAgency\Influx\helpers\Compat;

/**
 * THE list of native element attributes Influx offers per element type — once,
 * for every consumer: a relation field's "Match by" dropdown and its sub-field
 * rows ({@see \GlueAgency\Influx\fields\Relation}), and the link-level surfaces
 * on the targets ({@see \GlueAgency\Influx\targets\UserTarget},
 * {@see \GlueAgency\Influx\targets\EntryTarget}). They used to be written out
 * per call site, which is how a Users relation ended up offering `slug` and
 * `title` — neither of which a user has — while hiding `username` and `email`.
 *
 * Two shapes, because the two vocabularies genuinely differ:
 *
 *   - `…Matchable()` → `[{value, label}]`, the option list of a select. Labels
 *     carry the handle (`Username (username)`) the way every match-by list does.
 *   - `…Writable()` → `[{handle, label}]`, a sub-field row.
 *
 * They are not one list because the SETS differ: `id` and `uri` can identify an
 * element but must never be written to one, while `fullName` / `firstName` /
 * `lastName` are writable and useless as keys.
 *
 * Writable rows are strings only. {@see \GlueAgency\Influx\sync\item\MappingApplier::applyNativeSubField()}
 * assigns the resolved value straight onto the attribute and compares as string,
 * so a typed property like `postDate` would throw there, and `enabled` needs the
 * coercion its own target does — which is why this is deliberately narrower than
 * "every native attribute a target offers".
 */
class NativeAttributes
{
    /**
     * Entry identifiers. `uri` is offered without checking whether the sections
     * have URLs: where they don't the column is null and the option simply
     * matches nothing, and gating it would mean reading every section's per-site
     * settings at schema-build time for a case the operator can see for
     * themselves.
     *
     * @param list<EntryType> $entryTypes The types the field's sources allow. An
     * empty list means "couldn't resolve" and gates nothing — hiding a row on a
     * failed lookup would look like Craft doesn't support it.
     * @return list<array{value: string, label: string}>
     */
    public static function entryMatchable(array $entryTypes = [], ?string $titleLabel = null): array
    {
        $options = [['value' => 'id', 'label' => Craft::t('influx', 'ID (id)')]];

        if (static::anyEntryTypeShowsTitle($entryTypes)) {
            $options[] = ['value' => 'title', 'label' => static::labelWithHandle($titleLabel ?: Craft::t('app', 'Title'), 'title')];
        }

        if (static::anyEntryTypeShowsSlug($entryTypes)) {
            $options[] = ['value' => 'slug', 'label' => static::labelWithHandle(Craft::t('app', 'Slug'), 'slug')];
        }

        $options[] = ['value' => 'uri', 'label' => static::labelWithHandle(Craft::t('app', 'URI'), 'uri')];

        return $options;
    }

    /**
     * Entry attributes a mapping can write. No `uri`: Craft derives it from the
     * section's URI format on save, so a written one is overwritten.
     *
     * @param list<EntryType> $entryTypes
     * @return list<array{handle: string, label: string}>
     */
    public static function entryWritable(array $entryTypes = [], ?string $titleLabel = null): array
    {
        $rows = [];

        if (static::anyEntryTypeShowsTitle($entryTypes)) {
            $rows[] = ['handle' => 'title', 'label' => $titleLabel ?: Craft::t('app', 'Title')];
        }

        if (static::anyEntryTypeShowsSlug($entryTypes)) {
            $rows[] = ['handle' => 'slug', 'label' => Craft::t('app', 'Slug')];
        }

        return $rows;
    }

    /**
     * User identifiers. `username` is dropped where the site uses the email as
     * the username: it's then a copy of the email rather than a second key, and
     * Craft itself drops the field from the user layout under that setting.
     *
     * Neither `title` nor `slug` appears: `User::hasTitles()` is false, so
     * `elements_sites.title` is null for every user and a title match can never
     * resolve one.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function userMatchable(): array
    {
        $options = [['value' => 'id', 'label' => Craft::t('influx', 'ID (id)')]];

        if (static::usesSeparateUsername()) {
            $options[] = ['value' => 'username', 'label' => static::labelWithHandle(Craft::t('app', 'Username'), 'username')];
        }

        $options[] = ['value' => 'email', 'label' => static::labelWithHandle(Craft::t('app', 'Email'), 'email')];

        return $options;
    }

    /** @return list<array{handle: string, label: string}> */
    public static function userWritable(): array
    {
        $rows = [];

        if (static::usesSeparateUsername()) {
            $rows[] = ['handle' => 'username', 'label' => Craft::t('app', 'Username')];
        }

        return array_merge($rows, [
            ['handle' => 'email',     'label' => Craft::t('app', 'Email')],
            ['handle' => 'fullName',  'label' => Craft::t('app', 'Full Name')],
            ['handle' => 'firstName', 'label' => Craft::t('app', 'First Name')],
            ['handle' => 'lastName',  'label' => Craft::t('app', 'Last Name')],
        ]);
    }

    /** @return list<array{value: string, label: string}> */
    public static function categoryMatchable(): array
    {
        return [
            ['value' => 'id',    'label' => Craft::t('influx', 'ID (id)')],
            ['value' => 'title', 'label' => static::labelWithHandle(Craft::t('app', 'Title'), 'title')],
            ['value' => 'slug',  'label' => static::labelWithHandle(Craft::t('app', 'Slug'), 'slug')],
        ];
    }

    /** @return list<array{handle: string, label: string}> */
    public static function categoryWritable(): array
    {
        return [
            ['handle' => 'title', 'label' => Craft::t('app', 'Title')],
            ['handle' => 'slug',  'label' => Craft::t('app', 'Slug')],
        ];
    }

    /**
     * A tag is identified by its title alone. It HAS a slug column, but Craft
     * derives it from the title on save and its own editor never shows one, so
     * offering it would promise an edit that doesn't stick.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function tagMatchable(): array
    {
        return [
            ['value' => 'id',    'label' => Craft::t('influx', 'ID (id)')],
            ['value' => 'title', 'label' => static::labelWithHandle(Craft::t('app', 'Title'), 'title')],
        ];
    }

    /** @return list<array{handle: string, label: string}> */
    public static function tagWritable(): array
    {
        return [
            ['handle' => 'title', 'label' => Craft::t('app', 'Title')],
        ];
    }

    /**
     * The `Name (handle)` form every match-by option uses, so an operator
     * matching on a handle can see which one they're picking.
     */
    protected static function labelWithHandle(string $label, string $handle): string
    {
        return $label . ' (' . $handle . ')';
    }

    /**
     * Whether the username is a key of its own — see {@see userMatchable()}.
     *
     * Null-safe on the app because these lists are also built in the pure unit
     * suite, which boots no Craft: no config means no such setting, so the
     * username stands. Late static binding, so a spec can subclass and answer
     * for both branches without a booted app.
     */
    protected static function usesSeparateUsername(): bool
    {
        return ! (Craft::$app?->getConfig()->getGeneral()->useEmailAsUsername ?? false);
    }

    /**
     * Entry-type gates: an attribute is offered when ANY of the allowed types
     * shows it, since a relation field's sources are a union and a row that
     * doesn't apply to one type is inert for that type rather than wrong.
     *
     * @param list<EntryType> $entryTypes
     */
    protected static function anyEntryTypeShowsTitle(array $entryTypes): bool
    {
        return $entryTypes === [] || static::anyEntryType($entryTypes, [Compat::class, 'entryTypeShowsTitleField']);
    }

    /** @param list<EntryType> $entryTypes */
    protected static function anyEntryTypeShowsSlug(array $entryTypes): bool
    {
        return $entryTypes === [] || static::anyEntryType($entryTypes, [Compat::class, 'entryTypeShowsSlugField']);
    }

    /** @param list<EntryType> $entryTypes */
    protected static function anyEntryType(array $entryTypes, callable $shows): bool
    {
        foreach ($entryTypes as $entryType) {
            if ($shows($entryType)) {
                return true;
            }
        }

        return false;
    }
}
