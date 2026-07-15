<?php

namespace GlueAgency\Influx\services;

use Craft;
use craft\base\Component;
use GlueAgency\Influx\exceptions\InfluxException;
use GlueAgency\Influx\models\Link;
use Throwable;

/**
 * Per-link pre-run DB backup. Delegates to Craft's own backup machinery.
 */
class BackupService extends Component
{
    /**
     * Take this link's pre-run backup, or null when the link doesn't want one.
     *
     * @throws InfluxException when a requested backup fails — the run MUST NOT
     * proceed, or a delete/disable sweep would run with no safety net.
     */
    public function backupForLink(Link $link): ?string
    {
        return $link->backup ? $this->backup() : null;
    }

    /**
     * Take ONE backup covering a whole set of links, when ANY of them wants one
     * — so a bulk run (a multi-site fan-out, `influx/sync --all`) dumps the DB
     * once rather than once per link/site. Null when none require a backup.
     *
     * @param Link[] $links
     * @throws InfluxException when the backup fails.
     */
    public function backupForLinks(array $links): ?string
    {
        foreach ($links as $link) {
            if ($link->backup) {
                return $this->backup();
            }
        }

        return null;
    }

    /**
     * Run Craft's DB backup, turning a failure into an {@see InfluxException} so
     * the caller aborts rather than run an unprotected destructive sweep.
     *
     * @throws InfluxException
     */
    protected function backup(): ?string
    {
        try {
            return Craft::$app->getDb()->backup();
        } catch (Throwable $e) {
            Craft::error('Influx: pre-sync backup failed: ' . $e->getMessage(), __METHOD__);

            throw new InfluxException(
                'Aborting sync: the pre-run backup failed, so a destructive sweep would run unprotected — ' . $this->failureReason($e),
                previous: $e,
            );
        }
    }

    /**
     * A concise reason from a backup failure. `Db::backup()` surfaces the ENTIRE
     * mysqldump / pg_dump shell command in its exception message; strip the
     * command itself (it's in the Craft log above via `Craft::error`) so the run
     * log stays readable, keeping the exit code + stderr — then hard-cap the
     * length as a backstop for any other shape of failure.
     */
    protected function failureReason(Throwable $e): string
    {
        $reason = preg_replace('/^The shell command ".*?" failed/s', 'The backup command failed', $e->getMessage()) ?? $e->getMessage();

        return mb_strlen($reason) > 300 ? mb_substr($reason, 0, 300) . '…' : $reason;
    }
}
