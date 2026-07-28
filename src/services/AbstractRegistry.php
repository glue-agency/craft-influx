<?php

namespace GlueAgency\Influx\services;

use Craft;
use craft\base\Component;
use GlueAgency\Influx\events\RegisterEvent;
use GlueAgency\Influx\exceptions\InfluxException;

/**
 * Shared base for the plugin's three extension registries — field strategies
 * ({@see FieldsService}), element targets ({@see TargetsService}) and auth
 * strategies ({@see AuthService}). ONE pattern for a third-party developer to
 * learn: write a class, hand it to the registry (event listener or
 * {@see register()}), and the registry files a prototype of it under the key
 * the class itself declares.
 *
 * A registry resolves lazily: the first lookup seeds the built-ins INTO the
 * registration event's payload and then triggers it, so a listener can append
 * to the list, replace a built-in (re-register under the same key) or drop one
 * (filter the array). Because resolution is lazy and happens once, a plugin can
 * attach its listener from its own `init()` and still be heard.
 *
 * A subclass fills in five one-liners — {@see defaults()}, {@see itemType()},
 * {@see keyFor()}, {@see eventName()}, {@see eventClass()} — and adds its own
 * domain lookup on top of {@see item()} (`forCraftField()`, `forLink()`,
 * `fromConfig()`).
 */
abstract class AbstractRegistry extends Component
{
    /**
     * Prototype instances of the registered classes, keyed by {@see keyFor()},
     * in registration order. One shared prototype serves every call: per-call
     * state travels in arguments, never on the instance.
     *
     * @var array<string, object>
     */
    protected array $items = [];

    protected bool $initialized = false;

    /**
     * Built-ins shipped with the plugin. Exposed as a method so tests and
     * subclasses can override the default set.
     *
     * @return list<class-string>
     */
    abstract protected function defaults(): array;

    /** Interface or base class every registered class must satisfy. */
    abstract protected function itemType(): string;

    /** The key $item files itself under — the discriminator it declares. */
    abstract protected function keyFor(object $item): string;

    /** Name of the registration event, i.e. the registry's own EVENT_REGISTER_* const. */
    abstract protected function eventName(): string;

    /** @return class-string<RegisterEvent> The event that carries the class list. */
    abstract protected function eventClass(): string;

    /**
     * Every registered prototype, keyed by {@see keyFor()}.
     *
     * @return array<string, object>
     */
    public function all(): array
    {
        $this->ensureLoaded();

        return $this->items;
    }

    /**
     * Imperative registration, for code that would rather not wire up a
     * listener. Forces the registration event to fire first (seeding the
     * built-ins) so an explicit call always wins over the defaults, regardless
     * of timing.
     *
     * @param class-string $class
     * @throws InfluxException when $class can't be registered.
     */
    public function register(string $class): void
    {
        $this->ensureLoaded();
        $this->registerOne($class);
    }

    /** The prototype filed under $key, or null when nothing is registered for it. */
    protected function item(string $key): ?object
    {
        $this->ensureLoaded();

        return $this->items[$this->normalizeKey($key)] ?? null;
    }

    /**
     * @throws InfluxException when $class doesn't satisfy {@see itemType()}, or
     * declares no key — a registration that can't be filed would be silently
     * unreachable, which is a programming error, not something to swallow.
     */
    protected function registerOne(string $class): void
    {
        $type = $this->itemType();

        if (! is_subclass_of($class, $type)) {
            throw new InfluxException("'{$class}' must be a {$type} implementation.");
        }
        $item = Craft::createObject($class);
        $this->items[$this->normalizeKey($this->keyFor($item))] = $item;
    }

    /**
     * Two of the three registries key on FQCNs, where `\Foo\Bar` and `Foo\Bar`
     * name the same class — normalise both sides so a leading backslash can't
     * make a registration silently unreachable.
     */
    protected function normalizeKey(string $key): string
    {
        return ltrim($key, '\\');
    }

    protected function ensureLoaded(): void
    {
        if ($this->initialized) {
            return;
        }
        $this->initialized = true;

        /** @var RegisterEvent $event */
        $event = Craft::createObject($this->eventClass());
        $event->seed($this->defaults());
        $this->trigger($this->eventName(), $event);

        foreach ($event->registered() as $class) {
            $this->registerOne($class);
        }
    }
}
