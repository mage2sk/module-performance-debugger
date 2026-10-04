<?php
declare(strict_types=1);

namespace Panth\PerformanceDebugger\Plugin;

use Magento\Framework\View\Element\AbstractBlock;
use Panth\PerformanceDebugger\Helper\Config;
use Panth\PerformanceDebugger\Service\Profiler;

class BlockPlugin
{
    private array $timers = [];

    public function __construct(
        private readonly Profiler $profiler,
        private readonly Config $config
    ) {
    }

    public function beforeToHtml(AbstractBlock $subject): void
    {
        if (!$this->profiler->isActive()) {
            return;
        }
        $this->timers[] = microtime(true);
    }

    public function afterToHtml(AbstractBlock $subject, $result)
    {
        if (!$this->profiler->isActive() || $this->timers === []) {
            return $result;
        }
        $start = (float) array_pop($this->timers);
        if (!$this->config->trackBlocks()) {
            return $result;
        }
        $class = get_class($subject);
        if (str_contains($class, 'Panth\\PerformanceDebugger')) {
            return $result;
        }
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
