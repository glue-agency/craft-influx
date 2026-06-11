<?php

namespace GlueAgency\Influx\console;

use yii\helpers\Console;

/**
 * success()/failure()/tip()/warning() console output helpers exist on
 * craft\console\Controller since Craft 4.4 (via craft\console\ControllerTrait).
 * This trait keeps call sites identical across Craft 4.0–5.x: when the parent
 * controller provides the helper it wins (emoji + Markdown rendering),
 * otherwise a plain stdout fallback is used.
 *
 * Trait methods take precedence over inherited ones, so on 4.4+ these
 * delegate straight back to the core implementations.
 */
trait ConsoleOutputCompatTrait
{
    public function success(string $message): void
    {
        if (method_exists(parent::class, 'success')) {
            parent::success($message);
            return;
        }
        $this->stdout("✅ {$message}\n", Console::FG_GREEN);
    }

    public function failure(string $message): void
    {
        if (method_exists(parent::class, 'failure')) {
            parent::failure($message);
            return;
        }
        $this->stdout("❌ {$message}\n", Console::FG_RED);
    }

    public function tip(string $message): void
    {
        if (method_exists(parent::class, 'tip')) {
            parent::tip($message);
            return;
        }
        $this->stdout("💡 {$message}\n", Console::FG_YELLOW);
    }

    public function warning(string $message): void
    {
        if (method_exists(parent::class, 'warning')) {
            parent::warning($message);
            return;
        }
        $this->stdout("⚠️ {$message}\n", Console::FG_YELLOW);
    }
}
