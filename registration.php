<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 * Performance Debugger Module
 *
 * Frontend performance debugger and profiler for Magento 2.
 */

declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'Panth_PerformanceDebugger',
    __DIR__
);
