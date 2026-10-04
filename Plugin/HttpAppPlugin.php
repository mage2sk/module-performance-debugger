<?php
declare(strict_types=1);

namespace Panth\PerformanceDebugger\Plugin;

use Magento\Framework\App\Http;
use Panth\PerformanceDebugger\Service\Profiler;

class HttpAppPlugin
{
    public function __construct(
        private readonly Profiler $profiler
    ) {
    }

    public function beforeLaunch(Http $subject): void
    {
        $this->profiler->start();
    }
}
