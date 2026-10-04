<?php
declare(strict_types=1);

namespace Panth\PerformanceDebugger\Setup\Patch\Data;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Panth\PerformanceDebugger\Service\Redactor;

class RedactStoredRuns implements DataPatchInterface
{
    private const BATCH_SIZE = 500;
    private const SQL_KEYS = ['source', 'sample', 'label', 'sql', 'fingerprint'];

    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly Redactor $redactor
    ) {
    }

    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $runTable = $this->moduleDataSetup->getTable('panth_perf_run');
        $eventTable = $this->moduleDataSetup->getTable('panth_perf_run_event');

        if ($connection->isTableExists($runTable)) {
            $this->redactRuns($connection, $runTable);
        }
        if ($connection->isTableExists($eventTable)) {
            $this->redactEvents($connection, $eventTable);
        }

        return $this;
    }

    private function redactRuns(AdapterInterface $connection, string $table): void
    {
        $lastId = 0;
        do {
            $rows = $connection->fetchAll(
                $connection->select()
                    ->from($table, ['run_id', 'url', 'summary'])
                    ->where('run_id > ?', $lastId)
                    ->order('run_id ASC')
                    ->limit(self::BATCH_SIZE)
            );
            foreach ($rows as $row) {
                $lastId = (int) $row['run_id'];
                $update = [];
                $url = $this->redactor->sanitizeUrl((string) $row['url']);
                if ($url !== (string) $row['url']) {
                    $update['url'] = $url;
                }
                if ($row['summary'] !== null && $row['summary'] !== '') {
                    $summary = $this->redactSummary((string) $row['summary']);
                    if ($summary !== $row['summary']) {
                        $update['summary'] = $summary;
                    }
                }
                if ($update !== []) {
                    $connection->update($table, $update, ['run_id = ?' => $lastId]);
                }
            }
        } while (count($rows) === self::BATCH_SIZE);
    }

    private function redactEvents(AdapterInterface $connection, string $table): void
    {
        $lastId = 0;
        do {
            $rows = $connection->fetchAll(
                $connection->select()
                    ->from($table, ['event_id', 'label', 'meta'])
                    ->where('kind = ?', 'query')
                    ->where('event_id > ?', $lastId)
                    ->order('event_id ASC')
                    ->limit(self::BATCH_SIZE)
            );
            foreach ($rows as $row) {
                $lastId = (int) $row['event_id'];
                $update = [];
                $label = $this->redactor->redactSql((string) $row['label']);
                if ($label !== (string) $row['label']) {
                    $update['label'] = $label;
                }
                if ($row['meta'] !== null && $row['meta'] !== '') {
                    $meta = $this->redactMeta((string) $row['meta']);
                    if ($meta !== $row['meta']) {
                        $update['meta'] = $meta;
                    }
                }
                if ($update !== []) {
                    $connection->update($table, $update, ['event_id = ?' => $lastId]);
                }
            }
        } while (count($rows) === self::BATCH_SIZE);
    }

    private function redactMeta(string $json): string
    {
        $meta = json_decode($json, true);
        if (!is_array($meta)) {
            return $json;
        }
        if (isset($meta['bind']) && is_array($meta['bind'])) {
            $meta['bind'] = $this->redactor->safeBind($meta['bind']);
        }
        if (isset($meta['fingerprint']) && is_string($meta['fingerprint'])) {
            $meta['fingerprint'] = $this->redactor->redactSql($meta['fingerprint']);
        }

        return $this->encode($meta) ?? $json;
    }

    private function redactSummary(string $json): string
    {
        $summary = json_decode($json, true);
        if (!is_array($summary)) {
            return $json;
        }
        if (isset($summary['duplicates']) && is_array($summary['duplicates'])) {
            $duplicates = [];
            foreach ($summary['duplicates'] as $fingerprint => $count) {
                $key = $this->redactor->redactSql((string) $fingerprint);
                $duplicates[$key] = ($duplicates[$key] ?? 0) + (int) $count;
            }
            $summary['duplicates'] = $duplicates;
        }
        if (isset($summary['findings']) && is_array($summary['findings'])) {
            foreach ($summary['findings'] as $i => $finding) {
                if (!is_array($finding) || !in_array($finding['kind'] ?? '', ['duplicate_query', 'slow_query'], true)) {
                    continue;
                }
                foreach (self::SQL_KEYS as $key) {
                    if (isset($finding[$key]) && is_string($finding[$key])) {
                        $finding[$key] = $this->redactor->redactSql($finding[$key]);
                    }
                }
                if (isset($finding['binds'])) {
                    $finding['binds'] = $this->redactBinds($finding['binds']);
                }
                $summary['findings'][$i] = $finding;
            }
        }

        return $this->encode($summary) ?? $json;
    }

    private function redactBinds(mixed $binds): mixed
    {
        if (!is_array($binds)) {
            return $this->redactor->redactValues($binds);
        }
        foreach ($binds as $i => $bind) {
            if (is_array($bind) && array_key_exists('sample', $bind)) {
                $bind['sample'] = $this->redactor->redactValues($bind['sample']);
                $binds[$i] = $bind;
            } else {
                $binds[$i] = $this->redactor->redactValues($bind);
            }
        }

        return $binds;
    }

    private function encode(array $data): ?string
    {
        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return is_string($encoded) ? $encoded : null;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
