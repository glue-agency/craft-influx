<?php

namespace GlueAgency\Influx\sync\run;

use GlueAgency\Influx\enums\SyncTrigger;

/**
 * What started a run and, when a person asked for it, who. MUTABLE only in the
 * sense that its properties are public; treat as read-only.
 *
 * TRIGGER AND USER ARE SEPARATE DIMENSIONS, which is why there is no
 * `SyncTrigger::USER` case: "a person pressed a button" doesn't replace "it came
 * from the CP" or "it came from an element's edit screen" — it composes with
 * them. A CP run and an element run are both user-attributed; a console or cron
 * run is the same mechanism as a CP run minus the person. Folding the two into
 * one enum would force a choice between the mechanism and the attribution and
 * lose whichever lost.
 *
 * It REPLACES the {@see SyncTrigger} parameter everywhere a run is started
 * rather than sitting beside it: a hop that forgets to thread the origin through
 * is then a TypeError at the call site, not a run that quietly records the
 * trigger and drops the user.
 *
 * THE USER IS CAPTURED AT TRIGGER TIME, in the controller, and carried verbatim
 * from there — never re-read inside a queue job. Inside a job the web user is
 * whoever happens to be draining the queue (usually nobody), so a job that asked
 * Craft for "the current user" would attribute the run to the wrong person, or
 * to no-one.
 *
 * {@see payload()} / {@see fromPayload()} are the ONLY places the queue payload's
 * key names are spelled out — the same contract {@see BatchState} holds for the
 * carried run state, for the same reason: a name echoed in the push, the job's
 * properties and the re-push is a name any one of the three can silently drop.
 */
class RunOrigin
{
    /** The mechanism that kicked the run off. */
    public SyncTrigger $trigger;

    /**
     * The Craft user who asked for the run, or null when nobody did (console,
     * cron, a programmatic call).
     */
    public ?int $userId = null;

    public function __construct(SyncTrigger $trigger, ?int $userId = null)
    {
        $this->trigger = $trigger;
        $this->userId = $userId;
    }

    /**
     * A run triggered from the plugin's own CP screens (the Sync button).
     */
    public static function cp(?int $userId): self
    {
        return new self(SyncTrigger::CP, $userId);
    }

    /**
     * A run triggered from one element's edit screen ("Sync from remote").
     */
    public static function element(?int $userId): self
    {
        return new self(SyncTrigger::ELEMENT, $userId);
    }

    /**
     * A console / cron / programmatic run. Takes no user by construction: there
     * is no CP identity behind it, and a `./craft` shell account is not one.
     */
    public static function console(): self
    {
        return new self(SyncTrigger::CONSOLE);
    }

    /**
     * This origin as the keys a queue payload carries it under.
     *
     * @return array{trigger: string, userId: ?int}
     */
    public function payload(): array
    {
        return [
            'trigger' => $this->trigger->value,
            'userId'  => $this->userId,
        ];
    }

    /**
     * Read an origin back off a queue payload. An unresolvable trigger (a
     * payload written by an older release, or a hand-edited job row) degrades to
     * `$fallback` instead of throwing — the run is worth more than the label —
     * while the user id, which no fallback could reconstruct, rides through as-is.
     */
    public static function fromPayload(?string $trigger, ?int $userId, SyncTrigger $fallback): self
    {
        return new self(SyncTrigger::tryFrom((string) $trigger) ?? $fallback, $userId);
    }
}
