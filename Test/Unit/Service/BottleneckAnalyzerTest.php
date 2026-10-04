<?php
declare(strict_types=1);

namespace Panth\PerformanceDebugger\Test\Unit\Service;

use Panth\PerformanceDebugger\Helper\Config;
use Panth\PerformanceDebugger\Service\BottleneckAnalyzer;
use Panth\PerformanceDebugger\Service\Profiler;
use PHPUnit\Framework\TestCase;

class BottleneckAnalyzerTest extends TestCase
{
    private const N1_SQL = 'SELECT `e`.* FROM `catalog_product_entity` AS `e` WHERE e.entity_id = ?';
    private const REPO_SUMMARY = 'Vendor\Mod\Model\Repo->get (app/code/Vendor/Mod/Model/Repo.php:10)';

    private function analyzer(): BottleneckAnalyzer
    {
        $config = $this->createStub(Config::class);
        $config->method('duplicateQueryThreshold')->willReturn(3);
        $config->method('slowQueryMs')->willReturn(50.0);
        $config->method('slowBlockMs')->willReturn(50.0);
        $config->method('slowObserverMs')->willReturn(30.0);

        return new BottleneckAnalyzer($config);
    }

    private function profilerWith(array $events): Profiler
    {
        $profiler = $this->createStub(Profiler::class);
        $profiler->method('getEvents')->willReturn($events);

        return $profiler;
    }

    private function event(string $kind, string $label, float $duration, array $meta = [], ?string $source = null): array
    {
        return [
            'kind' => $kind,
            'label' => $label,
            'source' => $source,
            'duration' => $duration,
            'meta' => $meta,
            'memory' => 0,
            'invocations' => 1,
            'severity' => null,
        ];
    }

    private function query(string $sql, float $duration, array $extraMeta = [], ?string $fingerprint = null): array
    {
        return $this->event('query', $sql, $duration, ['fingerprint' => $fingerprint ?? $sql] + $extraMeta);
    }

    public function testNoEventsProduceNoFindings(): void
    {
        $this->assertSame([], $this->analyzer()->analyze($this->profilerWith([])));
    }

    public function testRepeatedPointLookupIsReportedAsCriticalN1(): void
    {
        $callsite = [
            'summary' => self::REPO_SUMMARY,
            'trail' => [
                ['callable' => 'Magento\Framework\Foo->bar', 'file' => 'vendor/magento/framework/Foo.php', 'line' => 3],
                ['callable' => 'Vendor\Mod\Model\Repo->get', 'file' => 'app/code/Vendor/Mod/Model/Repo.php', 'line' => 10],
            ],
        ];
        $events = [];
        foreach ([2.0, 3.0, 4.0, 1.0] as $i => $ms) {
            $events[] = $this->query(self::N1_SQL, $ms, ['callsite' => $callsite, 'bind' => [':id' => (string) ($i + 1)]]);
        }

        $findings = $this->analyzer()->analyze($this->profilerWith($events));

        $this->assertCount(1, $findings);
        $f = $findings[0];
        $this->assertSame('duplicate_query', $f['kind']);
        $this->assertSame('critical', $f['severity']);
        $this->assertStringStartsWith('N+1 query repeated 4', $f['title']);
        $this->assertSame(10.0, $f['measured_ms']);
        $this->assertSame(8.5, $f['estimated_savings_ms']);
        $this->assertSame(4, $f['invocations']);
        $this->assertSame('Vendor_Mod', $f['module']);
        $this->assertSame(['min' => 1.0, 'avg' => 2.5, 'max' => 4.0], $f['timing']);
        $this->assertSame([['summary' => self::REPO_SUMMARY, 'count' => 4, 'trail' => $callsite['trail']]], $f['callsites']);
        $this->assertSame(
            [['name' => ':id', 'distinct' => 4, 'sample' => ['1', '2', '3', '4'], 'total' => 4]],
            $f['binds']
        );
        $this->assertFalse($f['is_core']);
        $this->assertSame('app/code/Vendor/Mod/Model/Repo.php', $f['userland_frame']['file']);
    }

    public function testDuplicateSeverityScalesWithCountWhenNotN1(): void
    {
        $sql = 'SELECT * FROM t WHERE id IN (?)';
        $medium = $this->analyzer()->analyze($this->profilerWith(array_fill(0, 3, $this->query($sql, 1.0))));
        $this->assertSame('medium', $medium[0]['severity']);
        $this->assertStringStartsWith('Duplicate query repeated 3', $medium[0]['title']);
        $this->assertNull($medium[0]['module']);
        $this->assertTrue($medium[0]['is_core']);
        $this->assertArrayNotHasKey('userland_frame', $medium[0]);

        $high = $this->analyzer()->analyze($this->profilerWith(array_fill(0, 9, $this->query($sql, 1.0))));
        $this->assertSame('high', $high[0]['severity']);
    }

    public function testNonSelectStatementsAreNeverN1(): void
    {
        $sql = 'UPDATE t SET a = 1 WHERE id = ?';
        $findings = $this->analyzer()->analyze($this->profilerWith(array_fill(0, 3, $this->query($sql, 1.0))));
        $this->assertSame('medium', $findings[0]['severity']);
    }

    public function testBelowThresholdIsNotADuplicate(): void
    {
        $findings = $this->analyzer()->analyze($this->profilerWith([
            $this->query(self::N1_SQL, 1.0),
            $this->query(self::N1_SQL, 1.0),
        ]));
        $this->assertSame([], $findings);
    }

    public function testBindSamplesSkipConstantAndComplexValues(): void
    {
        $events = [];
        foreach (range(1, 3) as $i) {
            $events[] = $this->query(self::N1_SQL, 1.0, ['bind' => ['store' => '1', 'id' => (string) $i, 'arr' => [1]]]);
        }
        $binds = $this->analyzer()->analyze($this->profilerWith($events))[0]['binds'];
        $this->assertSame([['name' => 'id', 'distinct' => 3, 'sample' => ['1', '2', '3'], 'total' => 3]], $binds);
    }

    public function testSlowQuerySeverityBands(): void
    {
        $cases = [60.0 => 'low', 100.0 => 'medium', 200.0 => 'high', 400.0 => 'critical'];
        foreach ($cases as $ms => $expected) {
            $findings = $this->analyzer()->analyze($this->profilerWith([$this->query('SELECT big', (float) $ms)]));
            $this->assertCount(1, $findings, (string) $ms);
            $this->assertSame('slow_query', $findings[0]['kind']);
            $this->assertSame($expected, $findings[0]['severity'], (string) $ms);
            $this->assertSame(round($ms * 0.85, 2), $findings[0]['estimated_savings_ms']);
            $this->assertSame([], $findings[0]['callsites']);
        }
        $this->assertSame([], $this->analyzer()->analyze($this->profilerWith([$this->query('SELECT fast', 49.9)])));
    }

    public function testSlowQueryKeepsItsCallsite(): void
    {
        $callsite = ['summary' => self::REPO_SUMMARY, 'trail' => [['file' => 'app/code/Vendor/Mod/Model/Repo.php']]];
        $findings = $this->analyzer()->analyze(
            $this->profilerWith([$this->query('SELECT big', 75.0, ['callsite' => $callsite])])
        );
        $this->assertSame('Vendor_Mod', $findings[0]['module']);
        $this->assertSame(1, $findings[0]['callsites'][0]['count']);
        $this->assertFalse($findings[0]['is_core']);
    }

    public function testSlowBlocksAreClassifiedCoreOrUserland(): void
    {
        $findings = $this->analyzer()->analyze($this->profilerWith([
            $this->event('block', 'product.info', 60.0, [], 'Magento_Catalog::product/view.phtml'),
            $this->event('block', 'theme.header', 60.0, [], 'app/design/frontend/Acme/theme/header.phtml'),
            $this->event('block', 'vendor.widget', 60.0, [], 'Vendor_Mod::widget.phtml'),
            $this->event('block', 'core.class', 60.0, [], 'Magento\Cms\Block\Page'),
            $this->event('block', 'fast', 10.0, [], 'Vendor_Mod::fast.phtml'),
        ]));

        $byLabel = [];
        foreach ($findings as $f) {
            $this->assertSame('slow_block', $f['kind']);
            $this->assertSame('low', $f['severity']);
            $this->assertSame(42.0, $f['estimated_savings_ms']);
            $byLabel[substr($f['title'], strrpos($f['title'], ' ') + 1)] = $f;
        }
        $this->assertCount(4, $findings);
        $this->assertTrue($byLabel['product.info']['is_core']);
        $this->assertSame('Magento_Catalog', $byLabel['product.info']['module']);
        $this->assertFalse($byLabel['theme.header']['is_core']);
        $this->assertFalse($byLabel['vendor.widget']['is_core']);
        $this->assertTrue($byLabel['core.class']['is_core']);

        $this->assertFalse($findings[0]['is_core']);
        $this->assertFalse($findings[1]['is_core']);
        $this->assertTrue($findings[2]['is_core']);
        $this->assertTrue($findings[3]['is_core']);
    }

    public function testBlockSourceFallsBackToTemplateMeta(): void
    {
        $findings = $this->analyzer()->analyze($this->profilerWith([
            $this->event('block', 'b', 55.0, ['template' => 'Vendor_Mod::t.phtml']),
        ]));
        $this->assertSame('Vendor_Mod::t.phtml', $findings[0]['source']);
    }

    public function testSlowObserverIsNeverCore(): void
    {
        $findings = $this->analyzer()->analyze($this->profilerWith([
            $this->event('observer', 'catalog_product_load_after', 250.0),
            $this->event('observer', 'quick_event', 5.0),
        ]));
        $this->assertCount(1, $findings);
        $this->assertSame('slow_observer', $findings[0]['kind']);
        $this->assertSame('critical', $findings[0]['severity']);
        $this->assertSame('catalog_product_load_after', $findings[0]['source']);
        $this->assertSame(150.0, $findings[0]['estimated_savings_ms']);
        $this->assertFalse($findings[0]['is_core']);
    }

    public function testHeavyModulesAreAggregatedAcrossEvents(): void
    {
        $events = [];
        foreach (range(1, 5) as $i) {
            $events[] = $this->event('controller', 'x' . $i, 30.0, [], 'Vendor\Heavy\Controller\Index');
        }
        $events[] = $this->event('controller', 'core', 450.0, [], 'Magento\Framework\App\Action');
        $events[] = $this->event('controller', 'light', 20.0, [], 'Vendor\Light\Thing');

        $findings = $this->analyzer()->analyze($this->profilerWith($events));
        $heavy = array_values(array_filter($findings, static fn($f) => $f['kind'] === 'heavy_module'));

        $this->assertCount(2, $heavy);
        $this->assertSame('Vendor_Heavy', $heavy[0]['module']);
        $this->assertSame('medium', $heavy[0]['severity']);
        $this->assertSame(150.0, $heavy[0]['measured_ms']);
        $this->assertSame(60.0, $heavy[0]['estimated_savings_ms']);
        $this->assertFalse($heavy[0]['is_core']);
        $this->assertSame('Magento_Framework', $heavy[1]['module']);
        $this->assertSame('high', $heavy[1]['severity']);
        $this->assertTrue($heavy[1]['is_core']);
    }

    public function testFindingsSortedBySeverityThenSavings(): void
    {
        $findings = $this->analyzer()->analyze($this->profilerWith([
            $this->event('observer', 'low_obs', 31.0),
            $this->event('observer', 'crit_obs', 300.0),
            $this->event('observer', 'medium_small', 61.0),
            $this->event('observer', 'medium_big', 110.0),
        ]));
        $this->assertSame(
            ['crit_obs', 'medium_big', 'medium_small', 'low_obs'],
            array_column($findings, 'source')
        );
        $ids = array_column($findings, 'id');
        sort($ids);
        $this->assertSame([1, 2, 3, 4], $ids);
    }

    public function testSeverityWeight(): void
    {
        $analyzer = $this->analyzer();
        $this->assertSame(4, $analyzer->severityWeight('critical'));
        $this->assertSame(3, $analyzer->severityWeight('high'));
        $this->assertSame(2, $analyzer->severityWeight('medium'));
        $this->assertSame(1, $analyzer->severityWeight('low'));
        $this->assertSame(0, $analyzer->severityWeight('bogus'));
    }

    public function testTotalEstimatedSavings(): void
    {
        $analyzer = $this->analyzer();
        $this->assertSame(0.0, $analyzer->totalEstimatedSavings([]));
        $this->assertSame(
            12.5,
            $analyzer->totalEstimatedSavings([['estimated_savings_ms' => 10], ['estimated_savings_ms' => 2.5]])
        );
    }

    public function testUserlandFrameSkipsMagentoVendorFrames(): void
    {
        $analyzer = $this->analyzer();
        $this->assertNull($analyzer->userlandFrame([]));
        $this->assertNull($analyzer->userlandFrame([
            ['trail' => [['file' => 'vendor/magento/module-catalog/X.php'], ['file' => ''], ['file' => 'lib/x.php']]],
        ]));
        $this->assertSame(
            ['file' => 'vendor/acme/module-x/Y.php', 'line' => 4],
            $analyzer->userlandFrame([
                ['trail' => [['file' => 'vendor/magento/framework/A.php']]],
                ['trail' => [['file' => 'vendor/acme/module-x/Y.php', 'line' => 4]]],
            ])
        );
        $this->assertSame(
            ['file' => 'app/design/frontend/A/b/x.phtml'],
            $analyzer->userlandFrame([['trail' => [['file' => 'app/design/frontend/A/b/x.phtml']]]])
        );
    }

    public function testIsCoreFindingForOtherKinds(): void
    {
        $analyzer = $this->analyzer();
        $this->assertTrue($analyzer->isCoreFinding(['kind' => 'heavy_module', 'module' => 'Magento_Sales']));
        $this->assertFalse($analyzer->isCoreFinding(['kind' => 'heavy_module', 'module' => 'Vendor_Mod']));
        $this->assertFalse($analyzer->isCoreFinding(['kind' => 'heavy_module']));
        $this->assertTrue($analyzer->isCoreFinding(['kind' => 'slow_query', 'callsites' => []]));
        $this->assertFalse($analyzer->isCoreFinding(
            ['kind' => 'duplicate_query', 'callsites' => [['trail' => [['file' => 'app/code/A/B/C.php']]]]]
        ));
    }

    public function testModuleIsDerivedFromVendorTrailWhenSummaryIsNotAClass(): void
    {
        $callsite = [
            'summary' => 'include (vendor/acme/module-foo-bar/view.php:1)',
            'trail' => [['file' => 'vendor/acme/module-foo-bar/view.php']],
        ];
        $events = array_fill(0, 3, $this->query('SELECT x FROM y', 1.0, ['callsite' => $callsite]));
        $this->assertSame('Foo Bar', $this->analyzer()->analyze($this->profilerWith($events))[0]['module']);

        $callsite = ['summary' => 'include (x)', 'trail' => [['file' => 'app/code/Acme/Shop/x.php']]];
        $events = array_fill(0, 3, $this->query('SELECT x FROM y', 1.0, ['callsite' => $callsite]));
        $this->assertSame('Acme_Shop', $this->analyzer()->analyze($this->profilerWith($events))[0]['module']);
    }
}
