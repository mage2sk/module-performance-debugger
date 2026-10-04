<?php
declare(strict_types=1);

namespace Panth\PerformanceDebugger\Test\Unit\Model\Config\Backend;

use Magento\Framework\Exception\LocalizedException;
use Panth\PerformanceDebugger\Model\Config\Backend\AllowedIps;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AllowedIpsTest extends TestCase
{
    private function model(): AllowedIps
    {
        $reflection = new \ReflectionClass(AllowedIps::class);

        return $reflection->newInstanceWithoutConstructor();
    }

    public static function validProvider(): array
    {
        return [
            'empty' => ['', ''],
            'single ipv4' => ['127.0.0.1', '127.0.0.1'],
            'spaces and blanks' => [' 10.0.0.1 ,, 192.168.1.9 ', '10.0.0.1, 192.168.1.9'],
            'ipv6 and wildcard' => ['::1,*', '::1, *'],
            'duplicates removed' => ['1.2.3.4,1.2.3.4', '1.2.3.4'],
        ];
    }

    #[DataProvider('validProvider')]
    public function testNormalizeAcceptsValidEntries(string $raw, string $expected): void
    {
        $this->assertSame($expected, $this->model()->normalize($raw));
    }

    public static function invalidProvider(): array
    {
        return [
            'text' => ['abc', 'abc'],
            'short ipv4' => ['127.0.0.1, 1.2.3', '1.2.3'],
            'cidr' => ['10.0.0.0/8', '10.0.0.0/8'],
            'out of range' => ['256.1.1.1', '256.1.1.1'],
        ];
    }

    #[DataProvider('invalidProvider')]
    public function testNormalizeRejectsInvalidEntries(string $raw, string $bad): void
    {
        try {
            $this->model()->normalize($raw);
            $this->fail('Invalid entry should be rejected');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString($bad, $e->getMessage());
        }
    }
}
