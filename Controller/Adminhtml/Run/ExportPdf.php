<?php
/**
 * Copyright © Panth Infotech. All rights reserved.
 */

declare(strict_types=1);

namespace Panth\PerformanceDebugger\Controller\Adminhtml\Run;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Panth\PerformanceDebugger\Helper\Config;
use Panth\PerformanceDebugger\Model\RunRepository;

/**
 * Generates a print-ready HTML report.
 *
 * The page ships with print CSS and an inline auto-print script so the
 * browser's native "Save as PDF" produces a clean PDF without requiring a
 * server-side PDF library. Honest tradeoff: no PHP PDF dependency to
 * maintain, supports any future Magento PHP version.
 */
class ExportPdf extends Action
{
    public const ADMIN_RESOURCE = 'Panth_PerformanceDebugger::export';

    public function __construct(
        Context $context,
        private readonly RunRepository $repository,
        private readonly RawFactory $rawFactory,
        private readonly Config $config
    ) {
        parent::__construct($context);
    }

    public function execute(): ResponseInterface|ResultInterface
    {
        $runId = (int) $this->getRequest()->getParam('id');
        $run = $this->repository->getById($runId);
        if (!$run || !$this->config->enablePdf()) {
            $this->messageManager->addErrorMessage(__('Run not found or PDF export disabled.'));
            return $this->resultRedirectFactory->create()->setPath('*/*/index');
        }

        $events = $this->repository->getEvents($runId);
        $summary = $run['summary_decoded'] ?? [];
        $allFindings = $summary['findings'] ?? [];
        // Userland findings drive the report; core findings are appended at the
        // end of the bottlenecks section as informational-only.
        $findings = array_values(array_filter($allFindings, fn($f) => empty($f['is_core'])));
        $coreFindings = array_values(array_filter($allFindings, fn($f) => !empty($f['is_core'])));
        $aggregates = $summary['aggregates'] ?? [];
        $modules = $this->modulesBreakdown($events, (float) $run['total_time']);

        $html = $this->renderHtml($run, $events, $findings, $coreFindings, $aggregates, $modules);
        $result = $this->rawFactory->create();
        $result->setHeader('Content-Type', 'text/html', true);
        $result->setContents($html);
        return $result;
    }

    private function modulesBreakdown(array $events, float $totalMs): array
    {
        $bins = [];
        foreach ($events as $e) {
            $module = $this->moduleFromEvent($e);
            if ($module === null) {
                continue;
            }
            if (!isset($bins[$module])) {
                $bins[$module] = ['module' => $module, 'time' => 0.0, 'blocks' => 0, 'queries' => 0, 'observers' => 0, 'other' => 0];
            }
            $bins[$module]['time'] += (float) $e['duration'];
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
            $r['pct'] = $totalMs > 0 ? round($r['time'] * 100 / $totalMs, 1) : 0;
        }
        return array_slice($rows, 0, 15);
    }

    private function moduleFromEvent(array $e): ?string
    {
        $candidate = $e['source'] ?? null;
        if (!$candidate) {
            $meta = !empty($e['meta_decoded']) ? $e['meta_decoded'] : [];
            $candidate = $meta['class'] ?? ($meta['callsite']['summary'] ?? null);
        }
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

    private function fixSnippet(string $kind): string
    {
        return match ($kind) {
            'duplicate_query' => "// Cache the resolved set in a request-scoped service\nprivate ?array \$cache = null;\npublic function getOptions(int \$id): array {\n    \$this->cache ??= \$this->repo->getAll();\n    return \$this->cache[\$id] ?? [];\n}",
            'slow_query'      => "// 1) EXPLAIN the query in MySQL\n// 2) Add a composite index on the WHERE/JOIN columns\nALTER TABLE my_table ADD INDEX (col_a, col_b);",
            'slow_block'      => "<!-- layout XML: enable block_html cache -->\n<block class=\"…\" name=\"my.block\" cacheable=\"true\">\n  <arguments>\n    <argument name=\"cache_lifetime\" xsi:type=\"number\">3600</argument>\n  </arguments>\n</block>",
            'slow_observer'   => "// Move the heavy work off the synchronous request:\n// 1) Add a queue topic in etc/queue.xml\n// 2) Publish from the observer\n\$this->publisher->publish('my.topic', \$payload);",
            'heavy_module'    => "// Audit:\n//  - blocks → enable cache or lazy-render via AJAX\n//  - observers → move heavy work to async queue\n//  - repositories → batch IDs to avoid N+1",
            default           => '',
        };
    }

    private function renderHtml(array $run, array $events, array $findings, array $coreFindings, array $aggregates, array $modules): string
    {
        $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $totalSavings = array_sum(array_column($findings, 'estimated_savings_ms'));
        $estimatedAfter = max(0.0, ((float) $run['total_time']) - $totalSavings);
        $totalMs = (float) $run['total_time'];
        $findingsCount = count($findings);

        // ---------- Findings: rich card for each ----------
        $findingsHtml = '';
        if (empty($findings)) {
            $findingsHtml = '<div class="empty">✓ No bottlenecks detected for this run.</div>';
        } else {
            foreach ($findings as $f) {
                $invocations = (int) ($f['invocations'] ?? 1);
                $timing = $f['timing'] ?? null;
                $callsites = $f['callsites'] ?? [];
                $binds = $f['binds'] ?? [];
                $module = $f['module'] ?? null;
                $snippet = $this->fixSnippet((string) $f['kind']);

                $stats = '<span class="stat measured">' . number_format((float) $f['measured_ms'], 2) . ' ms</span>';
                if ($invocations > 1) {
                    $stats .= '<span class="stat">×' . $invocations . ' calls</span>';
                }
                if ($timing) {
                    $stats .= '<span class="stat">min ' . number_format((float) $timing['min'], 2) . ' ms</span>';
                    $stats .= '<span class="stat">avg ' . number_format((float) $timing['avg'], 2) . ' ms</span>';
                    $stats .= '<span class="stat">max ' . number_format((float) $timing['max'], 2) . ' ms</span>';
                }
                $stats .= '<span class="stat saving">−' . number_format((float) $f['estimated_savings_ms'], 2) . ' ms</span>';

                $callsitesHtml = '';
                if (!empty($callsites)) {
                    $rows = '';
                    foreach ($callsites as $cs) {
                        $count = (int) ($cs['count'] ?? 0);
                        $badge = $count > 1 ? ' <span class="cs-count">×' . $count . '</span>' : '';
                        $rows .= '<div class="cs-line"><span class="cs-where">' . $h($cs['summary']) . '</span>' . $badge . '</div>';
                        if (!empty($cs['trail']) && count($cs['trail']) > 1) {
                            $rows .= '<div class="cs-trail">';
                            foreach ($cs['trail'] as $i => $frame) {
                                $rows .= '<div class="cs-trail-line">'
                                    . '<span class="tnum">' . ($i + 1) . '.</span> '
                                    . $h($frame['callable'] ?? '') . ' '
                                    . '<span class="tfile">(' . $h($frame['file'] ?? '') . (!empty($frame['line']) ? ':' . (int) $frame['line'] : '') . ')</span>'
                                    . '</div>';
                            }
                            $rows .= '</div>';
                        }
                    }
                    $callsitesHtml = '<div class="callsites"><div class="cs-title">Called from ' . count($callsites) . ' location' . (count($callsites) > 1 ? 's' : '') . '</div>' . $rows . '</div>';
                }

                $bindsHtml = '';
                if (!empty($binds)) {
                    $rows = '';
                    foreach ($binds as $b) {
                        $more = $b['distinct'] > count($b['sample']) ? ' (+' . ($b['distinct'] - count($b['sample'])) . ' more)' : '';
                        $rows .= '<div class="bind-row"><span class="bname">' . $h($b['name']) . '</span> <span class="bvals">'
                            . (int) $b['distinct'] . ' distinct values: ' . $h(implode(', ', $b['sample'])) . $more . '</span></div>';
                    }
                    $bindsHtml = '<div class="binds"><div class="b-title">Distinct bind values</div>' . $rows . '</div>';
                }

                $modBadge = $module ? '<span class="mod">' . $h($module) . '</span>' : '';
                $sqlBlock = !empty($f['source']) ? '<pre class="sql">' . $h($f['source']) . '</pre>' : '';
                $snipBlock = $snippet !== '' ? '<div class="snippet"><div class="snippet-title">Suggested fix snippet</div><pre>' . $h($snippet) . '</pre></div>' : '';

                $findingsHtml .= '<div class="issue sev-' . $h($f['severity']) . '">'
                    . '<div class="i-head"><div class="i-title"><span class="sev sev-' . $h($f['severity']) . '">' . $h($f['severity']) . '</span> ' . $h($f['title']) . ' ' . $modBadge . '</div>'
                    . '<div class="i-stats">' . $stats . '</div></div>'
                    . '<div class="i-why">' . $h($f['why'] ?? '') . '</div>'
                    . '<div class="i-fix"><strong>Fix:</strong> ' . $h($f['suggestion'] ?? '') . '</div>'
                    . $sqlBlock
                    . $callsitesHtml
                    . $bindsHtml
                    . $snipBlock
                    . '</div>';
            }
        }

        // ---------- Heaviest modules table — split userland vs core ----------
        $userlandMods = array_values(array_filter($modules, fn($m) => !str_starts_with((string) $m['module'], 'Magento_')));
        $coreMods     = array_values(array_filter($modules, fn($m) => str_starts_with((string) $m['module'], 'Magento_')));
        $renderModTable = function (array $rows) use ($h): string {
            if (empty($rows)) {
                return '';
            }
            $body = '';
            foreach ($rows as $m) {
                $body .= '<tr>'
                    . '<td><span class="mod">' . $h($m['module']) . '</span></td>'
                    . '<td>' . (int) $m['blocks'] . '</td>'
                    . '<td>' . (int) $m['queries'] . '</td>'
                    . '<td>' . (int) $m['observers'] . '</td>'
                    . '<td>' . (int) $m['other'] . '</td>'
                    . '<td>' . number_format($m['time'], 2) . ' ms</td>'
                    . '<td>' . $m['pct'] . '%</td></tr>';
            }
            return '<table><thead><tr><th>Module</th><th>Blocks</th><th>Queries</th><th>Observers</th><th>Other</th><th>Time</th><th>% of total</th></tr></thead><tbody>' . $body . '</tbody></table>';
        };
        $modulesHtml = '';
        if (!empty($userlandMods)) {
            $modulesHtml .= '<h3 style="font-size:12px;color:#0f172a;margin:8px 0 6px">Your modules — actionable</h3>' . $renderModTable($userlandMods);
        } else {
            $modulesHtml .= '<div class="empty" style="padding:14px">No third-party / theme modules contributing measurable time.</div>';
        }
        if (!empty($coreMods)) {
            $modulesHtml .= '<h3 style="font-size:12px;color:#64748b;margin:14px 0 6px">Magento core — informational</h3>' . $renderModTable($coreMods);
        }

        // ---------- Core findings (collapsed at end of report) ----------
        $coreHtml = '';
        if (!empty($coreFindings)) {
            $rows = '';
            foreach ($coreFindings as $f) {
                $rows .= '<tr>'
                    . '<td><span class="sev sev-' . $h($f['severity']) . '">' . $h($f['severity']) . '</span></td>'
                    . '<td>' . $h($f['title']) . ($f['module'] ? ' <span class="mod">' . $h($f['module']) . '</span>' : '') . '</td>'
                    . '<td>' . number_format((float) $f['measured_ms'], 2) . ' ms</td></tr>';
            }
            $coreHtml = '<h2>Magento core findings (' . count($coreFindings) . ') — informational</h2>'
                . '<div style="background:#f1f5f9;border:1px solid #cbd5e1;border-radius:6px;padding:10px 12px;margin-bottom:10px;font-size:11px;color:#475569">'
                . 'These originate inside <code>vendor/magento/*</code>. They cost real time but you usually cannot fix them without forking framework code.'
                . '</div>'
                . '<table><thead><tr><th style="width:80px">Severity</th><th>Issue</th><th style="width:90px">Measured</th></tr></thead><tbody>' . $rows . '</tbody></table>';
        }

        // ---------- Top events ----------
        $top = array_slice($events, 0, 200);
        $eventRows = '';
        foreach ($top as $e) {
            $module = $this->moduleFromEvent($e) ?? '';
            $modCol = $module !== '' ? '<span class="mod">' . $h($module) . '</span>' : '';
            $origin = $e['source'] ?? '';
            $eventRows .= '<tr><td><span class="kind kind-' . $h($e['kind']) . '">' . $h($e['kind']) . '</span></td>'
                . '<td><div>' . $h($e['label']) . '</div>' . ($origin ? '<div class="ev-origin">' . $h($origin) . '</div>' : '') . '</td>'
                . '<td>' . $modCol . '</td>'
                . '<td>' . number_format((float) $e['duration'], 2) . '</td></tr>';
        }

        return <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Profiler Run #{$h($run['run_id'])}</title>
<style>
  *{box-sizing:border-box}
  body{font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:24px;color:#1f2937;font-size:13px}
  h1{font-size:24px;margin:0 0 4px;color:#0f172a}
  h2{font-size:15px;margin:24px 0 10px;color:#0f172a;text-transform:uppercase;letter-spacing:.04em;border-bottom:2px solid #0f172a;padding-bottom:6px}
  .url{color:#64748b;word-break:break-all;margin-bottom:6px}
  .badges{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:18px}
  table{width:100%;border-collapse:collapse;margin-bottom:14px;font-size:12px;border:1px solid #e5e7eb;border-radius:6px;overflow:hidden}
  th,td{border-bottom:1px solid #f1f5f9;padding:8px 10px;text-align:left;vertical-align:top}
  th{background:#f8fafc;font-weight:600;font-size:11px;text-transform:uppercase;color:#64748b;letter-spacing:.04em}
  tr:last-child td{border-bottom:0}
  .summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px;margin-bottom:8px}
  .card{border:1px solid #e5e7eb;border-radius:10px;padding:12px;background:linear-gradient(180deg,#f8fafc,#fff)}
  .card .num{font-size:18px;font-weight:700;color:#0f172a}
  .card .lbl{font-size:10px;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;margin-top:4px;font-weight:600}
  .card.savings{background:linear-gradient(135deg,#dcfce7,#fff);border-color:#86efac}
  .card.savings .num{color:#15803d}
  .card.bad{background:linear-gradient(135deg,#fef2f2,#fff);border-color:#fecaca}
  .card.bad .num{color:#dc2626}

  /* Issue cards */
  .issue{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px;margin-bottom:12px;page-break-inside:avoid}
  .issue.sev-critical{border-left:4px solid #dc2626}
  .issue.sev-high{border-left:4px solid #ea580c}
  .issue.sev-medium{border-left:4px solid #facc15}
  .issue.sev-low{border-left:4px solid #16a34a}
  .i-head{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:8px;align-items:flex-start}
  .i-title{font-weight:700;font-size:13px;flex:1;min-width:0}
  .i-stats{display:flex;gap:6px;flex-wrap:wrap}
  .stat{display:inline-block;font-size:10px;padding:2px 7px;border-radius:5px;background:#f1f5f9;color:#475569;font-weight:600}
  .stat.measured{background:#fee2e2;color:#991b1b}
  .stat.saving{background:#dcfce7;color:#15803d}
  .i-why{font-size:11px;color:#475569;line-height:1.5;margin-bottom:6px}
  .i-fix{background:#dcfce7;border:1px solid #86efac;border-radius:6px;padding:8px 10px;font-size:11px;color:#14532d;margin-bottom:8px}
  .i-fix strong{color:#14532d}
  .sev{display:inline-block;font-size:10px;padding:2px 7px;border-radius:4px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;margin-right:6px;color:#fff}
  .sev.sev-critical{background:#dc2626}
  .sev.sev-high{background:#ea580c}
  .sev.sev-medium{background:#facc15;color:#1f2937}
  .sev.sev-low{background:#a7f3d0;color:#065f46}
  .mod{display:inline-block;font-size:10px;padding:2px 7px;border-radius:9999px;font-weight:600;background:#eef2ff;color:#4338ca;border:1px solid #c7d2fe;margin-left:4px}

  /* Code & callsites */
  .sql{font-family:ui-monospace,Consolas,monospace;font-size:10px;color:#334155;background:#f1f5f9;padding:8px 10px;border-radius:6px;white-space:pre-wrap;word-break:break-all;line-height:1.5;margin:0 0 8px;border-left:3px solid #cbd5e1}
  .callsites{margin-bottom:8px;padding:8px 10px;background:#fffbeb;border:1px solid #fde68a;border-radius:6px}
  .cs-title{font-size:10px;text-transform:uppercase;color:#92400e;font-weight:700;letter-spacing:.04em;margin-bottom:4px}
  .cs-line{font-family:ui-monospace,Consolas,monospace;font-size:10px;color:#1f2937;padding:3px 0;border-top:1px dashed #fde68a;display:flex;justify-content:space-between;gap:6px}
  .cs-line:first-of-type{border-top:0}
  .cs-where{flex:1;word-break:break-all}
  .cs-count{background:#92400e;color:#fff;font-size:9px;padding:1px 6px;border-radius:9999px;font-weight:700;flex-shrink:0;align-self:center}
  .cs-trail{font-family:ui-monospace,Consolas,monospace;font-size:9px;color:#475569;padding:6px 8px;background:#fff;border:1px dashed #fde68a;border-radius:6px;margin-top:4px;margin-bottom:4px}
  .cs-trail-line{padding:2px 0}
  .tnum{color:#94a3b8;display:inline-block;width:18px}
  .tfile{color:#94a3b8}
  .binds{margin-bottom:8px;padding:8px 10px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px}
  .b-title{font-size:10px;text-transform:uppercase;color:#1e40af;font-weight:700;letter-spacing:.04em;margin-bottom:4px}
  .bind-row{font-family:ui-monospace,Consolas,monospace;font-size:10px;color:#1f2937;padding:3px 0;border-top:1px dashed #bfdbfe}
  .bind-row:first-of-type{border-top:0}
  .bname{color:#1e40af;font-weight:700;margin-right:8px}
  .bvals{color:#475569;word-break:break-all}
  .snippet{margin-top:8px}
  .snippet-title{font-size:10px;text-transform:uppercase;color:#16a34a;font-weight:700;letter-spacing:.04em;margin-bottom:4px}
  .snippet pre{font-family:ui-monospace,Consolas,monospace;font-size:10px;background:#f0fdf4;border:1px solid #86efac;border-radius:6px;padding:8px 10px;white-space:pre-wrap;word-break:break-word;line-height:1.5;margin:0;color:#14532d}

  /* Top events */
  .kind{display:inline-block;font-size:9px;padding:2px 6px;border-radius:4px;font-weight:700;text-transform:uppercase;background:#f1f5f9;color:#475569}
  .kind-block{background:#e0f2fe;color:#0369a1}
  .kind-observer{background:#fef3c7;color:#92400e}
  .kind-query{background:#fce7f3;color:#9d174d}
  .kind-layout{background:#ede9fe;color:#5b21b6}
  .kind-controller{background:#dcfce7;color:#166534}
  .ev-origin{font-family:ui-monospace,Consolas,monospace;font-size:10px;color:#94a3b8;margin-top:2px;word-break:break-all}

  .empty{padding:32px;text-align:center;color:#94a3b8;background:#f8fafc;border-radius:8px;border:1px dashed #e2e8f0}
  .toolbar{margin-top:8px;margin-bottom:14px}
  .toolbar button{padding:8px 16px;background:#0f172a;color:#fff;border:0;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600}
  @media print{.toolbar{display:none}.issue{page-break-inside:avoid}}
</style>
</head>
<body>
  <div class="toolbar"><button onclick="window.print()">Print / Save as PDF</button></div>
  <h1>Performance Report — Run #{$h($run['run_id'])}</h1>
  <div class="url"><strong>{$h($run['route'])}</strong> — {$h($run['url'])}</div>
  <div class="url" style="font-size:11px">Captured at {$h($run['created_at'])}</div>

  <h2>Summary</h2>
  <div class="summary">
HTML
            . $this->card(number_format($totalMs, 1) . ' ms', 'Total time', $totalMs > 1000 ? 'bad' : '')
            . $this->card((string) (int) $run['db_queries'], 'DB queries')
            . $this->card(number_format((float) $run['db_time'], 1) . ' ms', 'DB time')
            . $this->card((string) (int) $run['db_slow'], 'Slow queries')
            . $this->card((string) (int) $run['db_duplicates'], 'Duplicates')
            . $this->card((string) (int) $run['block_count'], 'Blocks')
            . $this->card(number_format((float) $run['block_time'], 1) . ' ms', 'Block render')
            . $this->card(number_format((int) $run['memory_peak'] / 1024 / 1024, 1) . ' MB', 'Peak memory')
            . $this->card('−' . number_format($totalSavings, 1) . ' ms', 'Estimated savings', 'savings')
            . $this->card(number_format($estimatedAfter, 1) . ' ms', 'Time after fixes', 'savings')
            . <<<HTML
  </div>

  <h2>Your bottlenecks ({$findingsCount})</h2>
  {$findingsHtml}

  {$coreHtml}

  <h2>Heaviest modules</h2>
  {$modulesHtml}

  <h2>Top events (first 200)</h2>
  <table>
    <thead><tr><th style="width:80px">Kind</th><th>Label & origin</th><th style="width:200px">Module</th><th style="width:80px">Duration (ms)</th></tr></thead>
    <tbody>{$eventRows}</tbody>
  </table>

  <script>setTimeout(()=>window.print(),350);</script>
</body>
</html>
HTML;
    }

    private function card(string $num, string $lbl, string $cls = ''): string
    {
        $h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $cls = $cls !== '' ? ' ' . $cls : '';
        return '<div class="card' . $cls . '"><div class="num">' . $h($num) . '</div><div class="lbl">' . $h($lbl) . '</div></div>';
    }
}
