<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */

declare(strict_types=1);

namespace Panth\PerformanceDebugger\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Read-only repository for persisted runs and their events.
 *
 * Used by the admin grid + run-detail view + export controllers. Returns
 * plain arrays — there is no need for the full AbstractModel lifecycle here.
 */
class RunRepository
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly SerializerInterface $serializer
    ) {
    }

    public function getRecent(int $limit = 100): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName('panth_perf_run'))
            ->order('created_at DESC')
            ->limit($limit);
        return $connection->fetchAll($select);
    }

    /**
     * Server-side paged + sorted + filtered runs query.
     *
     * Filters supported (all optional, all combined with AND):
     *   - q: substring match across url + route
     *   - severity: minimum severity_max (1=low … 4=critical)
     *
     * Sort columns are validated against an allow-list to keep the SQL safe.
     * Returns ['rows' => array, 'total' => int].
     */
    public function getPaged(
        int $page = 1,
        int $pageSize = 50,
        string $sort = 'created_at',
        string $dir = 'DESC',
        array $filters = []
    ): array {
        $allowedSorts = [
            'run_id', 'created_at', 'route', 'url', 'total_time',
            'db_queries', 'db_slow', 'db_duplicates', 'bottleneck_count', 'severity_max',
        ];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'created_at';
        }
        $dir = strtoupper($dir) === 'ASC' ? 'ASC' : 'DESC';
        $page = max(1, $page);
        $pageSize = max(10, min(500, $pageSize));

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_perf_run');

        $base = $connection->select()->from($table);
        if (!empty($filters['q'])) {
            $like = '%' . $filters['q'] . '%';
            $base->where('url LIKE ?', $like)
                ->orWhere('route LIKE ?', $like);
        }
        if (!empty($filters['severity'])) {
            $base->where('severity_max >= ?', (int) $filters['severity']);
        }

        $count = (int) $connection->fetchOne(
            (clone $base)->reset(\Magento\Framework\DB\Select::COLUMNS)->columns('COUNT(*)')
        );

        $rows = $connection->fetchAll(
            $base->order($sort . ' ' . $dir)->limitPage($page, $pageSize)
        );

        return ['rows' => $rows, 'total' => $count];
    }

    public function getById(int $runId): ?array
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName('panth_perf_run'))
                ->where('run_id = ?', $runId)
        );
        if (!$row) {
            return null;
        }
        if (!empty($row['summary'])) {
            try {
                $row['summary_decoded'] = $this->serializer->unserialize($row['summary']);
            } catch (\Throwable) {
                $row['summary_decoded'] = [];
            }
        }
        return $row;
    }

    public function getByToken(string $token): ?array
    {
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName('panth_perf_run'))
                ->where('token = ?', $token)
        );
        return $row ?: null;
    }

    public function getEvents(int $runId): array
    {
        $connection = $this->resource->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName('panth_perf_run_event'))
                ->where('run_id = ?', $runId)
                ->order('event_id ASC')
        );
        foreach ($rows as &$r) {
            if (!empty($r['meta'])) {
                try {
                    $r['meta_decoded'] = $this->serializer->unserialize($r['meta']);
                } catch (\Throwable) {
                    $r['meta_decoded'] = [];
                }
            }
        }
        return $rows;
    }
}
