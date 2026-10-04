<?php
declare(strict_types=1);

namespace Panth\PerformanceDebugger\Plugin;

use Magento\Framework\App\FrontControllerInterface;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Framework\App\RequestInterface;
use Panth\PerformanceDebugger\Service\Profiler;

class FrontControllerPlugin
{
    public const CONTEXT_KEY = 'panth_perf_session';

    public function __construct(
        private readonly Profiler $profiler,
        private readonly HttpContext $httpContext
    ) {
    }

    public function aroundDispatch(
        FrontControllerInterface $subject,
        callable $proceed,
        RequestInterface $request
    ) {
        if (!$this->profiler->isActive()) {
            return $proceed($request);
        }
        $this->httpContext->setValue(self::CONTEXT_KEY, 1, 0);
        $start = microtime(true);
        try {
            return $proceed($request);
        } finally {
            $duration = (microtime(true) - $start) * 1000.0;
            $label = trim(sprintf(
                '%s/%s/%s',
                (string) $request->getRouteName(),
                (string) $request->getControllerName(),
                (string) $request->getActionName()
            ), '/');
            $this->profiler->record('controller', $label, $duration);
        }
    }
}
