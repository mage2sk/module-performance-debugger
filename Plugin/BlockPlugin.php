<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */

declare(strict_types=1);

namespace Panth\PerformanceDebugger\Plugin;

use Magento\Framework\View\Element\AbstractBlock;
use Panth\PerformanceDebugger\Helper\Config;
use Panth\PerformanceDebugger\Service\Profiler;

/**
 * Times each block render (toHtml).
 *
 * Records block name, template path, class, and produced HTML byte length.
 * Excludes our own toolbar block to avoid recursion noise.
 */
class BlockPlugin
{
    public function __construct(
        private readonly Profiler $profiler,
        private readonly Config $config
    ) {
    }

    public function aroundToHtml(AbstractBlock $subject, callable $proceed)
    {
        if (!$this->profiler->isActive() || !$this->config->trackBlocks()) {
            return $proceed();
        }
        $class = get_class($subject);
        if (str_contains($class, 'Panth\\PerformanceDebugger')) {
            return $proceed();
        }
        $start = microtime(true);
        $result = $proceed();
        $duration = (microtime(true) - $start) * 1000.0;

        $name = (string) $subject->getNameInLayout();
        $template = method_exists($subject, 'getTemplate') ? (string) $subject->getTemplate() : '';
        $bytes = is_string($result) ? strlen($result) : 0;

        $this->profiler->record(
            'block',
            $name !== '' ? $name : $class,
            $duration,
            ['template' => $template, 'class' => $class, 'bytes' => $bytes],
            $template !== '' ? $template : $class
        );
        return $result;
    }
}
