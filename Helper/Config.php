<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */

declare(strict_types=1);

namespace Panth\PerformanceDebugger\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Config accessor — single source of truth for performance_debugger/* settings.
 */
class Config
{
    public const XML_PATH_PREFIX = 'performance_debugger/';

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) $this->flag('general/enabled');
    }

    public function showToolbar(): bool
    {
        return $this->isEnabled() && (bool) $this->flag('general/show_toolbar');
    }

    public function safeMode(): bool
    {
        return (bool) $this->flag('general/safe_mode');
    }

    public function allowedIps(): array
    {
        $raw = (string) $this->value('general/allowed_ips');
        if ($raw === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    public function trackBlocks(): bool
    {
        return (bool) $this->flag('collectors/track_blocks');
    }

    public function trackObservers(): bool
    {
        return (bool) $this->flag('collectors/track_observers');
    }

    public function trackPlugins(): bool
    {
        return !$this->safeMode() && (bool) $this->flag('collectors/track_plugins');
    }

    public function trackDb(): bool
    {
        return (bool) $this->flag('collectors/track_db');
    }

    public function trackLayout(): bool
    {
        return (bool) $this->flag('collectors/track_layout');
    }

    public function trackDi(): bool
    {
        return !$this->safeMode() && (bool) $this->flag('collectors/track_di');
    }

    public function trackMemory(): bool
    {
        return (bool) $this->flag('collectors/track_memory');
    }

    public function slowQueryMs(): float
    {
        return (float) ($this->value('thresholds/slow_query_ms') ?: 50);
    }

    public function slowBlockMs(): float
    {
        return (float) ($this->value('thresholds/slow_block_ms') ?: 50);
    }

    public function slowObserverMs(): float
    {
        return (float) ($this->value('thresholds/slow_observer_ms') ?: 30);
    }

    public function slowPluginMs(): float
    {
        return (float) ($this->value('thresholds/slow_plugin_ms') ?: 20);
    }

    public function duplicateQueryThreshold(): int
    {
        return (int) ($this->value('thresholds/duplicate_query_threshold') ?: 3);
    }

    public function persistRuns(): bool
    {
        return (bool) $this->flag('storage/persist_runs');
    }

    public function retentionHours(): int
    {
        return (int) ($this->value('storage/retention_hours') ?: 24);
    }

    public function maxEventsPerRun(): int
    {
        return (int) ($this->value('storage/max_events_per_run') ?: 5000);
    }

    public function enableXls(): bool
    {
        return (bool) $this->flag('export/enable_xls');
    }

    public function enablePdf(): bool
    {
        return (bool) $this->flag('export/enable_pdf');
    }

    private function value(string $path): mixed
    {
        return $this->scopeConfig->getValue(self::XML_PATH_PREFIX . $path, ScopeInterface::SCOPE_STORE);
    }

    private function flag(string $path): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_PREFIX . $path, ScopeInterface::SCOPE_STORE);
    }
}
