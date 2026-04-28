<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */

declare(strict_types=1);

namespace Panth\PerformanceDebugger\Model\ResourceModel\Run;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Panth\PerformanceDebugger\Model\ResourceModel\Run as RunResource;
use Panth\PerformanceDebugger\Model\Run;

class Collection extends AbstractCollection
{
    protected $_idFieldName = 'run_id';

    protected function _construct(): void
    {
        $this->_init(Run::class, RunResource::class);
    }
}
