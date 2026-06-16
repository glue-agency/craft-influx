<?php

namespace GlueAgency\Influx\services;

use Craft;
use craft\base\Component;
use Throwable;

/**
 * Server-Sent Events transport. Both the link debug view and the log view
 * stream live updates to the CP; this owns the identical plumbing they share —
 * stripping Yii's output buffers, sending the SSE headers, the proxy-priming
 * padding, the `event:`/`data:` frame + flush, turning a thrown exception into
 * an `error` event, and ending the Craft request.
 *
 * Callers supply a producer that emits the actual events via {@see send()};
 * the producer owns its own `done` sentinel since the payload differs per
 * stream (the debug stream sends `{}`, the log stream sends `{status}`).
 *
 *   Influx::getInstance()->eventStream->run(function (EventStreamService $stream) {
 *       $stream->send('item', [...]);
 *       if ($stream->aborted()) {
 *           return;
 *       }
 *       $stream->send('done', []);
 *   });
 */
class EventStreamService extends Component
{
    /**
     * Take exclusive control of the response, run the producer, and end the
     * request. Any exception thrown by the producer becomes a final `error`
     * event so the client always learns the stream stopped.
     */
    public function run(callable $producer): void
    {
        $this->open();

        try {
            $producer($this);
        } catch (Throwable $e) {
            $this->send('error', ['message' => $e->getMessage()]);
        }

        Craft::$app->end();
    }

    /**
     * Emit one SSE frame and flush it immediately so the client sees each
     * event as it happens rather than at the end of the response.
     */
    public function send(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
        @flush();
    }

    /**
     * Whether the client has disconnected. Producers should check this inside
     * long-running loops and stop when it returns true.
     */
    public function aborted(): bool
    {
        return connection_aborted() === 1;
    }

    /**
     * Strip any buffers Yii / PHP stacked, disable the time limit, keep
     * running past a client disconnect, and send the SSE headers + a padding
     * comment so buffering proxies start forwarding straight away.
     */
    protected function open(): void
    {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        @set_time_limit(0);
        ignore_user_abort(true);

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');

        echo ': ' . str_repeat(' ', 2048) . "\n\n";
        @flush();
    }
}
