<?php
declare(strict_types=1);

namespace Panth\PerformanceDebugger\Test\Unit\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Serialize\Serializer\Json;
use Panth\PerformanceDebugger\Model\RunRepository;
use PHPUnit\Framework\TestCase;

class RunRepositoryTest extends TestCase
{
    private array $calls = [];

    private function select(): Select
    {
        $select = $this->createStub(Select::class);
        foreach (['from', 'order', 'limit', 'where', 'limitPage', 'reset', 'columns'] as $method) {
            $select->method($method)->willReturnCallback(function (...$args) use ($method, $select) {
                while ($args !== [] && end($args) === null) {
                    array_pop($args);
                }
                $this->calls[] = [$method, $args];
                return $select;
            });
        }

        return $select;
    }

    private function repository(AdapterInterface $connection): RunRepository
    {
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new RunRepository($resource, new Json());
    }

    private function connection(array $returns = []): AdapterInterface
    {
        $connection = $this->createStub(AdapterInterface::class);
        $connection->method('select')->willReturnCallback(fn() => $this->select());
        $connection->method('quoteInto')->willReturnCallback(
            static fn($text, $value) => str_replace('?', "'" . $value . "'", $text)
        );
        $connection->method('fetchAll')->willReturn($returns['all'] ?? []);
        $connection->method('fetchRow')->willReturn($returns['row'] ?? false);
        $connection->method('fetchOne')->willReturn($returns['one'] ?? '0');

        return $connection;
    }

    private function callsOf(string $method): array
    {
        return array_values(array_map(
            static fn($c) => $c[1],
            array_filter($this->calls, static fn($c) => $c[0] === $method)
        ));
    }

    public function testGetRecentOrdersNewestFirstWithLimit(): void
    {
        $rows = [['run_id' => 1]];
        $this->assertSame($rows, $this->repository($this->connection(['all' => $rows]))->getRecent(5));
        $this->assertSame([['created_at DESC']], $this->callsOf('order'));
        $this->assertSame([[5]], $this->callsOf('limit'));
    }

    public function testGetPagedSanitizesSortDirectionAndPaging(): void
    {
        $result = $this->repository($this->connection(['all' => [['run_id' => 3]], 'one' => '42']))
            ->getPaged(0, 5000, 'token; DROP TABLE', 'sideways');

        $this->assertSame(['rows' => [['run_id' => 3]], 'total' => 42], $result);
        $this->assertSame([['created_at DESC']], $this->callsOf('order'));
        $this->assertSame([[1, 500]], $this->callsOf('limitPage'));
        $this->assertSame([], $this->callsOf('where'));
        $this->assertSame([['COUNT(*)']], $this->callsOf('columns'));
    }

    public function testGetPagedHonoursAllowedSortAndMinimumPageSize(): void
    {
        $this->repository($this->connection())->getPaged(3, 1, 'total_time', 'asc');
        $this->assertSame([['total_time ASC']], $this->callsOf('order'));
        $this->assertSame([[3, 10]], $this->callsOf('limitPage'));
    }

    public function testGetPagedAppliesSearchAndSeverityFilters(): void
    {
        $this->repository($this->connection())->getPaged(1, 50, 'run_id', 'DESC', ['q' => 'checkout', 'severity' => '3']);

        $this->assertSame(
            [
                ["url LIKE '%checkout%' OR route LIKE '%checkout%'"],
                ['severity_max >= ?', 3],
            ],
            $this->callsOf('where')
        );
    }

    public function testGetByIdReturnsNullWhenMissing(): void
    {
        $this->assertNull($this->repository($this->connection())->getById(9));
    }

    public function testGetByIdDecodesSummary(): void
    {
        $row = ['run_id' => 9, 'summary' => '{"findings":[1]}'];
        $result = $this->repository($this->connection(['row' => $row]))->getById(9);
        $this->assertSame(['findings' => [1]], $result['summary_decoded']);
        $this->assertSame([['run_id = ?', 9]], $this->callsOf('where'));
    }

    public function testGetByIdToleratesCorruptSummary(): void
    {
        $result = $this->repository($this->connection(['row' => ['run_id' => 9, 'summary' => '{bad']]))->getById(9);
        $this->assertSame([], $result['summary_decoded']);

        $empty = $this->repository($this->connection(['row' => ['run_id' => 9, 'summary' => '']]))->getById(9);
        $this->assertArrayNotHasKey('summary_decoded', $empty);
    }

    public function testGetByToken(): void
    {
        $this->assertNull($this->repository($this->connection())->getByToken('abc'));
        $this->assertSame(
            ['run_id' => 2],
            $this->repository($this->connection(['row' => ['run_id' => 2]]))->getByToken('abc')
        );
        $this->assertSame([['token = ?', 'abc'], ['token = ?', 'abc']], $this->callsOf('where'));
    }

    public function testGetEventsDecodesMetaPerRow(): void
    {
        $rows = [
            ['event_id' => 1, 'meta' => '{"a":1}'],
            ['event_id' => 2, 'meta' => 'not json'],
            ['event_id' => 3, 'meta' => null],
        ];
        $events = $this->repository($this->connection(['all' => $rows]))->getEvents(4);

        $this->assertSame(['a' => 1], $events[0]['meta_decoded']);
        $this->assertSame([], $events[1]['meta_decoded']);
        $this->assertArrayNotHasKey('meta_decoded', $events[2]);
        $this->assertSame([['event_id ASC']], $this->callsOf('order'));
    }
}
