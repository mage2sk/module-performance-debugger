<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */

declare(strict_types=1);

namespace Panth\PerformanceDebugger\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Run extends AbstractDb
{
    protected function _construct(): void
    {
        $this->_init('panth_perf_run', 'run_id');
    }
}
