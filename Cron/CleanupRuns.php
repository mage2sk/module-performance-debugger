<?php
declare(strict_types=1);

namespace Panth\PerformanceDebugger\Cron;

use Magento\Framework\App\ResourceConnection;
use Panth\PerformanceDebugger\Helper\Config;

class CleanupRuns
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Config $config
    ) {
    }

    public function execute(): void
    {
        if (!$this->config->persistRuns()) {
            return;
        }
        $hours = max(1, $this->config->retentionHours());
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName('panth_perf_run');
        $cutoff = (new \DateTimeImmutable('-' . $hours . ' hours'))->format('Y-m-d H:i:s');
        $connection->delete($table, ['created_at < ?' => $cutoff]);
    }
}
