<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */

declare(strict_types=1);

namespace Panth\PerformanceDebugger\Plugin;

use Magento\Framework\App\ResponseInterface;
use Panth\PerformanceDebugger\Helper\Config;
use Panth\PerformanceDebugger\Model\RunPersister;
use Panth\PerformanceDebugger\Service\Profiler;

/**
 * After the response is sent, persist the captured run to the database
 * (asynchronous-style — outside the rendered HTML).
 *
 * Persistence runs only when storage/persist_runs is on and the request
 * is a frontend HTML response.
 */
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
            // Profiler must never break the response.
        }
        $this->profiler->reset();
    }
}
