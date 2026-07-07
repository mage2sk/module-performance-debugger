<?php
declare(strict_types=1);

namespace Panth\PerformanceDebugger\Plugin;

use Magento\Framework\App\ResponseInterface;
use Panth\PerformanceDebugger\Helper\Config;
use Panth\PerformanceDebugger\Model\RunPersister;
use Panth\PerformanceDebugger\Service\Profiler;

class ResponseFinalizePlugin
{
    public function __construct(
        private readonly Profiler $profiler,
        private readonly Config $config,
        private readonly RunPersister $persister
    ) {
    }

    public function afterSendResponse(ResponseInterface $subject, $result): void
    {
        if (!$this->profiler->isActive() || !$this->config->persistRuns()) {
            return;
        }
        try {
            $this->persister->persist($this->profiler);
        } catch (\Throwable $e) {
        }
        $this->profiler->reset();
    }
}
