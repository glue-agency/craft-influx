<?php

namespace GlueAgency\Influx\schema;

use Craft;
use craft\elements\User;
use craft\models\EntryType;
use craft\models\FieldLayout;
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
 * A `…Matchable()` without a `…Writable()` twin means the type has identifiers
 * but nothing this apparatus writes: a global set is matched by its handle, which
 * is project config and never a mapping's business.
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

        $rows[] = ['handle' => 'email', 'label' => Craft::t('app', 'Email')];

        return array_merge($rows, static::userNameWritable());
    }

    /**
     * The name attributes a user layout actually exposes.
     *
     * Craft renders its Full Name layout element as EITHER one full-name input OR
     * a First/Last pair, on `showFirstAndLastNameFields`, and branches its own
     * validation on the same setting ({@see \craft\elements\User::defineRules()}) —
     * so offering all three would put rows in the card for a shape the CP never
     * shows. The element is also removable, in which case none of the three is
     * editable anywhere and the mapping shouldn't pretend otherwise; that's the
     * same `isFieldIncluded()` probe an asset's alt and title go through
     * ({@see \GlueAgency\Influx\fields\Assets::nativeSubFields()}).
     *
     * @return list<array{handle: string, label: string}>
     */
    protected static function userNameWritable(): array
    {
        if (! static::userLayoutShowsNames()) {
            return [];
        }

        if (Craft::$app?->getConfig()->getGeneral()->showFirstAndLastNameFields ?? false) {
            return [
                ['handle' => 'firstName', 'label' => Craft::t('app', 'First Name')],
                ['handle' => 'lastName',  'label' => Craft::t('app', 'Last Name')],
            ];
        }

        return [
            ['handle' => 'fullName', 'label' => Craft::t('app', 'Full Name')],
        ];
    }

    /**
     * Whether the (single, global) user field layout includes the name element.
     * Craft marks it mandatory, so it's there unless a module removed it through
     * `FieldLayout::EVENT_DEFINE_NATIVE_FIELDS` — and with no booted app there's
     * no layout to ask, so the names are offered rather than silently dropped.
     */
    protected static function userLayoutShowsNames(): bool
    {
        $layout = Craft::$app?->getFields()->getLayoutByType(User::class);

        return $layout === null || $layout->isFieldIncluded('fullName');
    }

    /**
     * Asset identifiers. `filename` is the natural key a file feed carries; the
     * title is offered only where a volume layout includes it, since Craft treats
     * an asset's Title as an optional native layout element and a volume can drop
     * it — the same `isFieldIncluded()` probe {@see assetWritable()} applies to
     * both of its optional rows.
     *
     * No `url`: it isn't a queryable column ({@see \GlueAgency\Influx\fields\Assets}
     * matches one by basename and then compares `getUrl()`), so an option here
     * would promise a lookup no query can perform.
     *
     * @param FieldLayout|null $layout The volume's layout, or null when it can't be
     * resolved — which gates nothing, since hiding a row on a failed lookup would
     * look like Craft doesn't support it.
     * @return list<array{value: string, label: string}>
     */
    public static function assetMatchable(?FieldLayout $layout = null): array
    {
        $options = [
            ['value' => 'id',       'label' => Craft::t('influx', 'ID (id)')],
            ['value' => 'filename', 'label' => static::labelWithHandle(Craft::t('app', 'Filename'), 'filename')],
        ];

        if (static::layoutIncludes($layout, 'title')) {
            $options[] = ['value' => 'title', 'label' => static::labelWithHandle(Craft::t('app', 'Title'), 'title')];
        }

        return $options;
    }

    /**
     * Asset attributes a mapping can write. `filename` renames the file on save;
     * `title` and `alt` are each offered only where a volume layout includes them
     * — `craft\fieldlayoutelements\assets\AssetTitleField` and `AltField` are
     * optional native layout elements in both Craft 4 and 5, so the probe needs no
     * version seam and doubles as the reason a Craft 4.0–4.3 install (no `alt` at
     * all) never sees that row.
     *
     * @param FieldLayout|null $layout
     * @return list<array{handle: string, label: string}>
     */
    public static function assetWritable(?FieldLayout $layout = null): array
    {
        $rows = [['handle' => 'filename', 'label' => Craft::t('app', 'Filename')]];

        if (static::layoutIncludes($layout, 'title')) {
            $rows[] = ['handle' => 'title', 'label' => Craft::t('app', 'Title')];
        }

        if (static::layoutIncludes($layout, 'alt')) {
            $rows[] = ['handle' => 'alt', 'label' => Craft::t('app', 'Alternative Text')];
        }

        return $rows;
    }

    /**
     * Whether a layout exposes an optional native attribute. A null layout means
     * "couldn't resolve", which offers the attribute rather than dropping it — the
     * same lenience the entry-type gates take on an empty type list.
     */
    protected static function layoutIncludes(?FieldLayout $layout, string $attribute): bool
    {
        return $layout === null || $layout->isFieldIncluded($attribute);
    }

    /**
     * Global set identifiers. A global set has no title and no slug — it's named by
     * its handle, which is also the only thing a feed can plausibly carry to say
     * WHICH set an item is for.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function globalSetMatchable(): array
    {
        return [
            ['value' => 'id',     'label' => Craft::t('influx', 'ID (id)')],
            ['value' => 'handle', 'label' => static::labelWithHandle(Craft::t('app', 'Handle'), 'handle')],
        ];
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
