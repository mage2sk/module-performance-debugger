<?php
declare(strict_types=1);

namespace Panth\PerformanceDebugger\Test\Unit\Controller\Adminhtml\Run;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\Response\Http\FileFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Convert\Excel;
use Magento\Framework\Convert\ExcelFactory;
use Panth\PerformanceDebugger\Controller\Adminhtml\Run\ExportPdf;
use Panth\PerformanceDebugger\Controller\Adminhtml\Run\ExportXls;
use Panth\PerformanceDebugger\Helper\Config;
use Panth\PerformanceDebugger\Model\RunRepository;

class ExportTest extends ControllerTestCase
{
    private string $html = '';
    private array $headers = [];

    private function repository(?array $run, array $events = []): RunRepository
    {
        $repository = $this->createStub(RunRepository::class);
        $repository->method('getById')->willReturn($run);
        $repository->method('getEvents')->willReturn($events);

        return $repository;
    }

    private function config(bool $xls, bool $pdf): Config
    {
        $config = $this->createStub(Config::class);
        $config->method('enableXls')->willReturn($xls);
        $config->method('enablePdf')->willReturn($pdf);

        return $config;
    }

    private function runRow(array $summary = [], array $overrides = []): array
    {
        return $overrides + [
            'run_id' => 9,
            'route' => 'cms/index/index',
            'url' => 'https://shop.test/?q=<script>',
            'created_at' => '2026-01-01 10:00:00',
            'total_time' => '200',
            'db_queries' => '12',
            'db_time' => '40.5',
            'db_slow' => '1',
            'db_duplicates' => '2',
            'block_count' => '30',
            'block_time' => '80',
            'memory_peak' => (string) (64 * 1024 * 1024),
            'summary_decoded' => $summary,
        ];
    }

    public function testXlsExportRedirectsWhenRunMissingOrDisabled(): void
    {
        $fileFactory = $this->createMock(FileFactory::class);
        $fileFactory->expects($this->never())->method('create');

        $controller = new ExportXls(
            $this->context(['id' => '9']),
            $this->repository(null),
            $this->createStub(ExcelFactory::class),
            $fileFactory,
            $this->config(true, true)
        );
        $this->assertSame($this->redirectResult, $controller->execute());
        $this->assertSame(['path' => '*/*/index'], $this->redirect);
        $this->assertSame(['Run not found or XLS export disabled.'], $this->errors);

        $controller = new ExportXls(
            $this->context(['id' => '9']),
            $this->repository($this->runRow()),
            $this->createStub(ExcelFactory::class),
            $fileFactory,
            $this->config(false, true)
        );
        $controller->execute();
        $this->assertSame(['Run not found or XLS export disabled.'], $this->errors);
    }

    public function testXlsExportBuildsRowsAndDownloadsFile(): void
    {
        $captured = null;
        $excel = $this->createMock(Excel::class);
        $excel->expects($this->once())->method('convert')->with('panth_perf_run_9')->willReturn('<xml/>');
        $excelFactory = $this->createStub(ExcelFactory::class);
        $excelFactory->method('create')->willReturnCallback(function (array $args) use (&$captured, $excel) {
            $captured = iterator_to_array($args['iterator']);
            return $excel;
        });

        $response = $this->createStub(ResponseInterface::class);
        $fileFactory = $this->createMock(FileFactory::class);
        $fileFactory->expects($this->once())->method('create')
            ->with('panth_perf_run_9.xls', '<xml/>', DirectoryList::VAR_DIR, 'application/vnd.ms-excel')
            ->willReturn($response);

        $events = [
            ['kind' => 'block', 'label' => 'header', 'source' => 'a.phtml', 'duration' => '1.5', 'invocations' => '1'],
            ['kind' => 'query', 'label' => 'SELECT', 'source' => null, 'duration' => '2', 'invocations' => '3'],
        ];
        $controller = new ExportXls(
            $this->context(['id' => '9']),
            $this->repository($this->runRow(), $events),
            $excelFactory,
            $fileFactory,
            $this->config(true, false)
        );

        $this->assertSame($response, $controller->execute());
        $this->assertSame([
            ['Kind', 'Label', 'Source', 'Duration (ms)', 'Invocations'],
            ['block', 'header', 'a.phtml', 1.5, 1],
            ['query', 'SELECT', '', 2.0, 3],
        ], $captured);
    }

    private function pdf(?array $run, array $events = [], bool $enabled = true): ExportPdf
    {
        $raw = $this->createStub(Raw::class);
        $raw->method('setContents')->willReturnCallback(function ($html) use (&$raw) {
            $this->html = $html;
            return $raw;
        });
        $raw->method('setHeader')->willReturnCallback(function ($name, $value) use (&$raw) {
            $this->headers[$name] = $value;
            return $raw;
        });
        $rawFactory = $this->createStub(RawFactory::class);
        $rawFactory->method('create')->willReturn($raw);

        return new ExportPdf(
            $this->context(['id' => '9']),
            $this->repository($run, $events),
            $rawFactory,
            $this->config(false, $enabled)
        );
    }

    public function testPdfExportRedirectsWhenDisabled(): void
    {
        $this->pdf($this->runRow(), [], false)->execute();
        $this->assertSame(['path' => '*/*/index'], $this->redirect);
        $this->assertSame(['Run not found or PDF export disabled.'], $this->errors);
        $this->assertSame('', $this->html);
    }

    public function testPdfExportWithoutFindingsShowsEmptyStateAndEscapesInput(): void
    {
        $this->pdf($this->runRow())->execute();

        $this->assertSame('text/html', $this->headers['Content-Type']);
        $this->assertStringContainsString('<title>Profiler Run #9</title>', $this->html);
        $this->assertStringContainsString('Your bottlenecks (0)', $this->html);
        $this->assertStringContainsString('No bottlenecks detected for this run.', $this->html);
        $this->assertStringContainsString('https://shop.test/?q=&lt;script&gt;', $this->html);
        $this->assertStringNotContainsString('?q=<script>', $this->html);
        $this->assertStringContainsString('No third-party / theme modules contributing measurable time.', $this->html);
        $this->assertStringNotContainsString('Magento core findings', $this->html);
        $this->assertStringContainsString('<div class="num">64.0 MB</div>', $this->html);
        $this->assertStringContainsString('<div class="num">200.0 ms</div><div class="lbl">Time after fixes</div>', $this->html);
    }

    public function testPdfExportRendersUserlandAndCoreFindingsSeparately(): void
    {
        $summary = ['findings' => [
            [
                'kind' => 'duplicate_query', 'severity' => 'critical', 'title' => 'N+1 <query>',
                'measured_ms' => 50, 'estimated_savings_ms' => 42.5, 'module' => 'Vendor_Mod',
                'why' => 'Because', 'suggestion' => 'Batch it', 'source' => 'SELECT * FROM t WHERE id = ?',
                'invocations' => 10, 'timing' => ['min' => 1, 'avg' => 5, 'max' => 9],
                'callsites' => [[
                    'summary' => 'Vendor\Mod\Repo->get (app/code/Vendor/Mod/Repo.php:3)', 'count' => 10,
                    'trail' => [
                        ['callable' => 'A->b', 'file' => 'app/code/A.php', 'line' => 1],
                        ['callable' => 'C->d', 'file' => 'app/code/C.php', 'line' => 2],
                    ],
                ]],
                'binds' => [['name' => ':id', 'distinct' => 10, 'sample' => ['1', '2'], 'total' => 10]],
                'is_core' => false,
            ],
            [
                'kind' => 'slow_block', 'severity' => 'low', 'title' => 'Core block slow',
                'measured_ms' => 60, 'estimated_savings_ms' => 42, 'module' => 'Magento_Catalog',
                'is_core' => true,
            ],
        ]];

        $this->pdf($this->runRow($summary))->execute();

        $this->assertStringContainsString('Your bottlenecks (1)', $this->html);
        $this->assertStringContainsString('N+1 &lt;query&gt;', $this->html);
        $this->assertStringContainsString('<span class="mod">Vendor_Mod</span>', $this->html);
        $this->assertStringContainsString('min 1.00 ms', $this->html);
        $this->assertStringContainsString('Called from 1 location<', $this->html);
        $this->assertStringContainsString('A-&gt;b', $this->html);
        $this->assertStringContainsString('(app/code/C.php:2)', $this->html);
        $this->assertStringContainsString('10 distinct values: 1, 2 (+8 more)', $this->html);
        $this->assertStringContainsString('Cache the resolved set in a request-scoped service', $this->html);
        $this->assertStringContainsString('Magento core findings (1) - informational', $this->html);
        $this->assertStringContainsString('Core block slow', $this->html);
        $this->assertStringContainsString('<div class="num">157.5 ms</div><div class="lbl">Time after fixes</div>', $this->html);
    }

    public function testPdfExportBuildsModuleBreakdownAndEventTable(): void
    {
        $events = [
            ['kind' => 'block', 'label' => 'b1', 'source' => 'Vendor_Mod::a.phtml', 'duration' => '50'],
            ['kind' => 'query', 'label' => 'q1', 'source' => null, 'duration' => '30',
                'meta_decoded' => ['callsite' => ['summary' => 'Vendor\Mod\Repo->get (x)']]],
            ['kind' => 'observer', 'label' => 'o1', 'source' => 'Magento\Sales\Observer\X', 'duration' => '20'],
            ['kind' => 'layout', 'label' => 'generateXml', 'source' => null, 'duration' => '5'],
        ];
        $this->pdf($this->runRow(), $events)->execute();

        $this->assertStringContainsString('Your modules - actionable', $this->html);
        $this->assertStringContainsString(
            '<td><span class="mod">Vendor_Mod</span></td><td>1</td><td>1</td><td>0</td><td>0</td><td>80.00 ms</td><td>40%</td>',
            $this->html
        );
        $this->assertStringContainsString('Magento core - informational', $this->html);
        $this->assertStringContainsString('<td>10%</td>', $this->html);
        $this->assertStringContainsString('<span class="kind kind-layout">layout</span>', $this->html);
        $this->assertSame(4, substr_count($this->html, '<span class="kind kind-'));
    }

    public function testPdfEventTableIsCappedAt200Rows(): void
    {
        $events = array_fill(0, 250, ['kind' => 'block', 'label' => 'x', 'source' => null, 'duration' => '1']);
        $this->pdf($this->runRow(), $events)->execute();
        $this->assertSame(200, substr_count($this->html, '<span class="kind kind-block">'));
    }
}
