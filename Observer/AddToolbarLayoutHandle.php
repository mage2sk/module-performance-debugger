<?php
declare(strict_types=1);

namespace Panth\PerformanceDebugger\Observer;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Panth\PerformanceDebugger\Helper\Config;
use Panth\PerformanceDebugger\Service\Profiler;

class AddToolbarLayoutHandle implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Profiler $profiler,
        private readonly AppState $appState,
        private readonly RemoteAddress $remoteAddress,
        private readonly HttpRequest $request
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->shouldShowToolbar()) {
            return;
        }

        $layout = $observer->getEvent()->getData('layout');
        if ($layout === null || !method_exists($layout, 'getUpdate')) {
            return;
        }

        try {
            $layout->getUpdate()->addHandle('panth_performance_debugger_toolbar');
        } catch (\Throwable) {
        }
    }

    private function shouldShowToolbar(): bool
    {
        try {
            if (!$this->config->showToolbar()) {
                return false;
            }
            if (!$this->profiler->isActive()) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }

        $allowed = (array) $this->config->allowedIps();
        if (in_array('*', $allowed, true)) {
            return true;
        }

        try {
            if ($this->appState->getMode() === AppState::MODE_DEVELOPER) {
                return true;
            }
        } catch (\Throwable) {
        }

        $remote = (string) $this->remoteAddress->getRemoteAddress();
        return $remote !== '' && in_array($remote, $allowed, true);
    }
}
