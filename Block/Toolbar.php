<?php
declare(strict_types=1);

namespace Panth\PerformanceDebugger\Block;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Panth\PerformanceDebugger\Helper\Config;
use Panth\PerformanceDebugger\Service\BottleneckAnalyzer;
use Panth\PerformanceDebugger\Service\Profiler;

class Toolbar extends Template
{
    protected $_template = 'Panth_PerformanceDebugger::toolbar.phtml';

    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly Profiler $profiler,
        private readonly BottleneckAnalyzer $analyzer,
        private readonly \Magento\Framework\App\State $appState,
        private readonly \Magento\Framework\HTTP\PhpEnvironment\RemoteAddress $remoteAddress,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function shouldRender(): bool
    {
        if (!$this->config->showToolbar() || !$this->profiler->isActive()) {
            return false;
        }
        $allowed = $this->config->allowedIps();
        if (in_array('*', $allowed, true)) {
            return true;
        }
        try {
            if ($this->appState->getMode() === \Magento\Framework\App\State::MODE_DEVELOPER) {
                return true;
            }
        } catch (\Throwable) {
        }
        $remote = (string) $this->remoteAddress->getRemoteAddress();
        return $remote !== '' && in_array($remote, $allowed, true);
    }

    public function getPayload(): array
    {
        $aggregates = $this->profiler->getAggregates();
        $events = $this->profiler->getEvents();
        $findings = $this->analyzer->analyze($this->profiler);
        $context = $this->profiler->getRequestContext();

        $byKind = [];
        foreach ($events as $e) {
            if (!isset($e['origin']) && !empty($e['meta']['callsite']['summary'])) {
                $e['origin'] = $e['meta']['callsite']['summary'];
            } elseif (!isset($e['origin'])) {
                $e['origin'] = $e['source'] ?? ($e['meta']['template'] ?? $e['meta']['class'] ?? '');
            }
            $e['module'] = $this->moduleFromEvent($e);
            $byKind[$e['kind']] = $byKind[$e['kind']] ?? [];
            $byKind[$e['kind']][] = $e;
        }

        $duplicates = [];
        foreach ($this->profiler->getDuplicateQueries() as $fp => $count) {
            $duplicates[] = ['fingerprint' => $fp, 'count' => $count];
        }
        usort($duplicates, fn($a, $b) => $b['count'] <=> $a['count']);

        $modulesAll = $this->modulesBreakdown($events);
        $modulesUserland = array_values(array_filter($modulesAll, fn($m) => !str_starts_with((string) $m['module'], 'Magento_')));
        $modulesCore = array_values(array_filter($modulesAll, fn($m) => str_starts_with((string) $m['module'], 'Magento_')));
        $totalMs = round($this->profiler->totalElapsedMs(), 2);

        $userlandFindings = array_values(array_filter($findings, fn($f) => empty($f['is_core'])));
        $coreFindings = array_values(array_filter($findings, fn($f) => !empty($f['is_core'])));

        return [
            'token' => $context['token'],
            'url' => $context['url'],
            'route' => $context['route'],
            'totalMs' => $totalMs,
            'memoryPeakMb' => round($context['memory_peak'] / 1024 / 1024, 2),
            'aggregates' => $aggregates,
            'eventsByKind' => $byKind,
            'findings' => $findings,
            'userlandFindings' => $userlandFindings,
            'coreFindings' => $coreFindings,
            'duplicates' => array_slice($duplicates, 0, 20),
            'modules' => $modulesUserland,
            'modulesUserland' => $modulesUserland,
            'modulesCore' => $modulesCore,
            'totalEstimatedSavings' => round($this->analyzer->totalEstimatedSavings($userlandFindings), 2),
            'totalEstimatedSavingsAll' => round($this->analyzer->totalEstimatedSavings($findings), 2),
        ];
    }

    public function getPayloadJson(): string
    {
        return (string) json_encode($this->getPayload(), JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    private function modulesBreakdown(array $events): array
    {
        $bins = [];
        foreach ($events as $e) {
            $module = $this->moduleFromEvent($e);
            if ($module === null) {
                continue;
            }
            if (!isset($bins[$module])) {
                $bins[$module] = [
                    'module' => $module,
                    'time' => 0.0,
                    'count' => 0,
                    'blocks' => 0,
                    'observers' => 0,
                    'queries' => 0,
                    'other' => 0,
                ];
            }
            $bins[$module]['time'] += $e['duration'];
            $bins[$module]['count']++;
            $kindKey = match ($e['kind']) {
                'block' => 'blocks',
                'observer' => 'observers',
                'query' => 'queries',
                default => 'other',
            };
            $bins[$module][$kindKey]++;
        }
        $rows = array_values($bins);
        usort($rows, fn($a, $b) => $b['time'] <=> $a['time']);
        foreach ($rows as &$r) {
            $r['time'] = round($r['time'], 2);
        }
        return array_slice($rows, 0, 20);
    }

    private function moduleFromEvent(array $e): ?string
    {
        $candidate = $e['source'] ?? ($e['meta']['class'] ?? $e['label'] ?? null);
        if (!$candidate) {
            return null;
        }
        $candidate = (string) $candidate;

        if (preg_match('#^([A-Z][a-zA-Z0-9]+)_([A-Z][a-zA-Z0-9]+)::#', $candidate, $m)) {
            return $m[1] . '_' . $m[2];
        }

        if (preg_match('#^([A-Z][a-zA-Z0-9]+)[\\\\/]([A-Z][a-zA-Z0-9]+)#', $candidate, $m)) {
            return $m[1] . '_' . $m[2];
        }
        return null;
    }
}
