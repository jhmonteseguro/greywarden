<?php

namespace app\commands;

use app\modules\attack\components\CatalogueImporter;
use app\modules\attack\components\StixParser;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\db\Query;
use yii\helpers\Console;

/**
 * MITRE ATT&CK catalogue maintenance.
 *
 *   php yii attack/status                      # loaded release and counts
 *   php yii attack/import                      # (re)load the bundled snapshot (data/attack/enterprise-attack.json.gz)
 *   php yii attack/import path/to/file.json    # load a snapshot or a raw STIX bundle from disk
 *   php yii attack/update                      # download the latest Enterprise ATT&CK STIX bundle from MITRE,
 *                                              # refresh the bundled snapshot and import it
 *   php yii attack/update --url=https://...    # ...from a specific STIX URL (e.g. a pinned version)
 */
class AttackController extends Controller
{
    public $defaultAction = 'status';

    /** @var string STIX bundle URL for attack/update */
    public $url = CatalogueImporter::STIX_URL;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), $actionID === 'update' ? ['url'] : []);
    }

    public function actionStatus(): int
    {
        $release = (new Query())->from('attack_release')->orderBy(['id' => SORT_DESC])->one();
        if (!$release) {
            $this->stdout("No ATT&CK catalogue loaded. Run: php yii attack/import\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }
        $this->stdout(sprintf("%s v%s (released %s), imported %s\n", $release['domain'], $release['version'], $release['released_at'] ?? '?', $release['imported_at']), Console::FG_GREEN);
        $this->stdout(sprintf("  %d tactics, %d techniques, %d mitigations\n", $release['tactic_count'], $release['technique_count'], $release['mitigation_count']));
        $links = [];
        foreach (['incident_attack_technique' => 'incident technique links', 'risk_attack_technique' => 'risk technique links', 'decision_attack_mitigation' => 'decision mitigation links'] as $table => $label) {
            $links[] = (new Query())->from($table)->count() . ' ' . $label;
        }
        $this->stdout('  ' . implode(', ', $links) . "\n");
        return ExitCode::OK;
    }

    public function actionImport(?string $path = null): int
    {
        try {
            $catalogue = $path !== null && !$this->isSnapshot($path)
                ? $this->parseStix((string) file_get_contents($path), $path)
                : CatalogueImporter::readSnapshot($path);
        } catch (\Throwable $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }
        return $this->load($catalogue);
    }

    public function actionUpdate(): int
    {
        $this->stdout("Downloading {$this->url} ...\n");
        $context = stream_context_create(['http' => ['timeout' => 120, 'user_agent' => 'Greywarden ATT&CK importer']]);
        $raw = @file_get_contents($this->url, false, $context);
        if ($raw === false) {
            $this->stderr("Download failed. Check network access from the PHP container, or download the file and run attack/import <file>.\n", Console::FG_RED);
            return ExitCode::UNAVAILABLE;
        }
        try {
            $catalogue = $this->parseStix($raw, $this->url);
        } catch (\Throwable $e) {
            $this->stderr($e->getMessage() . "\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }
        $file = CatalogueImporter::writeSnapshot($catalogue);
        $this->stdout("Snapshot written to {$file}\n");
        return $this->load($catalogue);
    }

    private function parseStix(string $raw, string $source): array
    {
        ini_set('memory_limit', '2G'); // the full Enterprise bundle is ~55 MB of JSON
        $bundle = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        unset($raw);
        return StixParser::parse($bundle, $source);
    }

    private function isSnapshot(string $path): bool
    {
        if (str_ends_with($path, '.gz')) {
            return true;
        }
        $head = (string) file_get_contents($path, false, null, 0, 512);
        return !str_contains($head, '"type"') || str_contains($head, '"tactics"');
    }

    private function load(array $catalogue): int
    {
        $before = (new Query())->from('attack_release')->orderBy(['id' => SORT_DESC])->select('version')->scalar();
        $stats = (new CatalogueImporter())->import($catalogue);
        $this->stdout(sprintf("Imported ATT&CK %s v%s%s: %d tactics, %d techniques, %d mitigations, %d mitigation mappings",
            $catalogue['domain'] ?? '', $catalogue['version'] ?? '?', $before && $before !== ($catalogue['version'] ?? '') ? " (was v{$before})" : '',
            $stats['tactics'], $stats['techniques'], $stats['mitigations'], $stats['mitigates']), Console::FG_GREEN);
        $this->stdout($stats['deprecated'] ? sprintf("; %d entries no longer in the release were flagged deprecated.\n", $stats['deprecated']) : ".\n");
        Yii::$app->cache?->flush();
        return ExitCode::OK;
    }
}
