<?php

namespace TDM\Influx\migrations;

use Craft;
use craft\db\Migration;
use craft\helpers\ProjectConfig;
use TDM\Influx\services\LinksService;

/**
 * Migrate the Project Config mappings shape to its recursive form.
 *
 * Before:  mappings[handle].options.subFields[sub] = { node, default }
 * After:   mappings[handle].nativeFields[sub]      = { node, default }
 *
 * Each link's mappings tree is walked, sub-fields rewritten, and the result
 * round-tripped through ProjectConfig::set so YAML on disk gets the new
 * shape on `project-config/touch` / `apply` after this migration runs.
 *
 * Also clears the legacy `mappings[handle].type` key — it's ignored at
 * runtime now (dispatch happens by Craft field FQCN), but old YAML still
 * carries it. Cleaning it keeps `project.yaml` tidy.
 */
class m250603_180000_mapping_recursive_shape extends Migration
{
    public function safeUp(): bool
    {
        $pc = Craft::$app->getProjectConfig();
        $links = $pc->get(LinksService::CONFIG_LINKS_KEY) ?? [];

        if (!is_array($links)) {
            return true;
        }

        foreach ($links as $uid => $linkConfig) {
            $mappings = $linkConfig['mappings'] ?? null;
            if (!is_array($mappings) || empty($mappings)) {
                continue;
            }

            $newMappings = [];
            foreach ($mappings as $handle => $row) {
                $newMappings[$handle] = $this->migrateRow(is_array($row) ? $row : []);
            }

            $pc->set(
                LinksService::CONFIG_LINKS_KEY . ".{$uid}.mappings",
                $newMappings,
                'Influx: migrate mappings to recursive shape',
            );
        }

        return true;
    }

    public function safeDown(): bool
    {
        // No down migration — the rewrite is loss-free but we don't need to
        // reverse it for the foreseeable future.
        return true;
    }

    protected function migrateRow(array $row): array
    {
        // Lift options.subFields → nativeFields, recursively. Sub-rows in
        // either branch get the same treatment.
        $options = $row['options'] ?? [];
        if (is_array($options) && isset($options['subFields']) && is_array($options['subFields'])) {
            $row['nativeFields'] = $options['subFields'];
            unset($options['subFields']);
            $row['options'] = $options;
            if (empty($row['options'])) {
                unset($row['options']);
            }
        }

        foreach (['fields', 'nativeFields'] as $branch) {
            if (!isset($row[$branch]) || !is_array($row[$branch])) {
                continue;
            }
            foreach ($row[$branch] as $subHandle => $subRow) {
                if (is_array($subRow)) {
                    $row[$branch][$subHandle] = $this->migrateRow($subRow);
                }
            }
        }

        // Drop the legacy dispatch key.
        unset($row['type']);

        return ProjectConfig::cleanupConfig($row);
    }
}
