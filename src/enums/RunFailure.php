<?php

namespace GlueAgency\Influx\enums;

/**
 * What {@see \GlueAgency\Influx\sync\run\RunLifecycle::run()} does with a throw that
 * escapes a run's body, once the log has been failed. An explicit choice rather
 * than a per-caller copy of the try/catch, because the two policies are not
 * interchangeable and picking the wrong one is how a run ends up either silently
 * swallowed or aborted halfway through a fan-out.
 */
enum RunFailure
{
    /**
     * Re-throw after failing the log — the caller (or the CP request) needs to
     * see the failure. Used by the single-element sync, whose caller turns it
     * into an error message for the editor.
     */
    case RETHROW;

    /**
     * Swallow after failing the log — the failure is fully described by the
     * closed log and the run must go on. Used for per-site isolation (one site's
     * failure must not stop the next site) and for the queued path's closing
     * sweep, where a throw must still leave the log failed rather than stuck on
     * 'running' forever.
     */
    case FAIL_AND_CONTINUE;
}
