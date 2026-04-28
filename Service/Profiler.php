<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */

declare(strict_types=1);

namespace Panth\PerformanceDebugger\Service;

use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Math\Random;
use Magento\Store\Model\StoreManagerInterface;
use Panth\PerformanceDebugger\Helper\Config;

/**
 * Singleton profiler that holds the live in-memory recording for the current request.
 *
 * Collectors push events here via record(); the toolbar/persistence layer reads
 * the resulting buffer via getEvents()/getSummary().
 *
 * Ms are wall-clock milliseconds derived from microtime(true).
 */
class Profiler
{
    private bool $started = false;
    private float $startTime = 0.0;
    private string $token = '';
    private array $events = [];
    private array $aggregates = [
        'block' => ['time' => 0.0, 'count' => 0],
        'observer' => ['time' => 0.0, 'count' => 0],
        'plugin' => ['time' => 0.0, 'count' => 0],
        'query' => ['time' => 0.0, 'count' => 0, 'slow' => 0],
        'layout' => ['time' => 0.0, 'count' => 0],
        'di' => ['time' => 0.0, 'count' => 0],
        'controller' => ['time' => 0.0, 'count' => 0],
    ];
    private array $queryFingerprints = [];

    public function __construct(
        private readonly Config $config,
        private readonly Random $random,
        private readonly HttpRequest $request,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    public function start(): void
    {
        if ($this->started || !$this->config->isEnabled()) {
            return;
        }
        $this->started = true;
        $this->startTime = microtime(true);
        $this->token = $this->random->getRandomString(16);
    }

    public function isActive(): bool
    {
        return $this->started;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function record(string $kind, string $label, float $duration, array $meta = [], ?string $source = null): void
    {
        if (!$this->started) {
            return;
        }
        if (count($this->events) >= $this->config->maxEventsPerRun()) {
            return;
        }
        $event = [
            'kind' => $kind,
            'label' => $label,
            'source' => $source,
            'duration' => round($duration, 3),
            'meta' => $meta,
            'memory' => $this->config->trackMemory() ? memory_get_usage(true) : 0,
            'invocations' => 1,
            'severity' => null,
        ];

        if ($kind === 'query' && isset($meta['fingerprint'])) {
            $fp = (string) $meta['fingerprint'];
            $this->queryFingerprints[$fp] = ($this->queryFingerprints[$fp] ?? 0) + 1;
        }

        $this->events[] = $event;

        if (isset($this->aggregates[$kind])) {
            $this->aggregates[$kind]['time'] += $duration;
            $this->aggregates[$kind]['count']++;
            if ($kind === 'query' && $duration >= $this->config->slowQueryMs()) {
                $this->aggregates['query']['slow']++;
            }
        }
    }

    public function getEvents(): array
    {
        return $this->events;
    }

    public function getAggregates(): array
    {
        return $this->aggregates;
    }

    public function getDuplicateQueries(): array
    {
        $threshold = $this->config->duplicateQueryThreshold();
        $dupes = [];
        foreach ($this->queryFingerprints as $fp => $count) {
            if ($count >= $threshold) {
                $dupes[$fp] = $count;
            }
        }
        arsort($dupes);
        return $dupes;
    }

    public function totalElapsedMs(): float
    {
        if (!$this->started) {
            return 0.0;
        }
        return (microtime(true) - $this->startTime) * 1000.0;
    }

    public function getRequestContext(): array
    {
        try {
            $storeId = (int) $this->storeManager->getStore()->getId();
        } catch (\Throwable) {
            $storeId = 0;
        }
        return [
            'url' => (string) $this->request->getUriString(),
            'method' => (string) $this->request->getMethod(),
            'route' => trim(sprintf(
                '%s/%s/%s',
                (string) $this->request->getRouteName(),
                (string) $this->request->getControllerName(),
                (string) $this->request->getActionName()
            ), '/'),
            'store_id' => $storeId,
            'memory_peak' => memory_get_peak_usage(true),
            'token' => $this->token,
        ];
    }

    public function reset(): void
    {
        $this->started = false;
        $this->events = [];
        $this->queryFingerprints = [];
        foreach ($this->aggregates as $k => $_) {
            $this->aggregates[$k] = ['time' => 0.0, 'count' => 0];
        }
        $this->aggregates['query']['slow'] = 0;
    }
}
