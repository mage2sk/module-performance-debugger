<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */

declare(strict_types=1);

namespace Panth\PerformanceDebugger\Model;

use Magento\Framework\Model\AbstractModel;

class Run extends AbstractModel
{
    protected function _construct(): void
    {
        $this->_init(\Panth\PerformanceDebugger\Model\ResourceModel\Run::class);
    }
}
