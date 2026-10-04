<?php
declare(strict_types=1);

namespace Panth\PerformanceDebugger\Test\Unit\Service;

use Panth\PerformanceDebugger\Service\Redactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RedactorTest extends TestCase
{
    public static function urlProvider(): array
    {
        return [
            'no query' => ['https://shop.test/catalog/product', 'https://shop.test/catalog/product'],
            'safe params kept' => ['https://shop.test/?p=2&dir=asc', 'https://shop.test/?p=2&dir=asc'],
            'token masked' => ['https://shop.test/?p=2&token=abc', 'https://shop.test/?p=2&token=***'],
            'panth_perf masked' => ['https://shop.test/?panth_perf=123.abc', 'https://shop.test/?panth_perf=***'],
            'email masked case insensitive' => ['https://shop.test/?Email=a%40b.c', 'https://shop.test/?Email=***'],
            'encoded param name' => ['https://shop.test/?pass%77ord=x', 'https://shop.test/?pass%77ord=***'],
            'flag without value kept' => ['https://shop.test/?debug&secret=1', 'https://shop.test/?debug&secret=***'],
            'admin key path masked' => [
                'https://shop.test/admin/sales/order/key/0123abcd/',
                'https://shop.test/admin/sales/order/key/***/',
            ],
            'reset token path masked' => [
                'https://shop.test/customer/account/token/xyz?x=1',
                'https://shop.test/customer/account/token/***?x=1',
            ],
        ];
    }

    #[DataProvider('urlProvider')]
    public function testSanitizeUrl(string $in, string $expected): void
    {
        $this->assertSame($expected, (new Redactor())->sanitizeUrl($in));
    }

    public function testSanitizeUrlStopsQueryAtFragment(): void
    {
        $out = (new Redactor())->sanitizeUrl('https://shop.test/?a=1&key=2#section');
        $this->assertStringStartsWith('https://shop.test/?a=1&key=***', $out);
        $this->assertStringNotContainsString('key=2', $out);
    }

    public function testRedactSqlReplacesStringLiterals(): void
    {
        $redactor = new Redactor();
        $this->assertSame(
            "SELECT * FROM customer WHERE email = '?' AND name = \"?\" AND id = 5",
            $redactor->redactSql("SELECT * FROM customer WHERE email = 'a@b.c' AND name = \"Bob\" AND id = 5")
        );
        $this->assertSame("UPDATE t SET v = '?'", $redactor->redactSql("UPDATE t SET v = 'it''s'"));
        $this->assertSame("UPDATE t SET v = '?'", $redactor->redactSql("UPDATE t SET v = 'it\\'s'"));
        $this->assertSame('SELECT 1', $redactor->redactSql('SELECT 1'));
    }

    public function testSafeBindKeepsScalarsAndMasksStrings(): void
    {
        $out = (new Redactor())->safeBind([
            0 => 15,
            'price' => 9.5,
            'flag' => true,
            'nil' => null,
            'id' => '42',
            'neg' => '-7',
            'big' => '12345678901',
            'email' => 'bob@example.com',
            'arr' => [1, 2],
            'placeholder' => '[string:5]',
        ]);

        $this->assertSame([
            '0' => 15,
            'price' => 9.5,
            'flag' => true,
            'nil' => null,
            'id' => '42',
            'neg' => '-7',
            'big' => '[string:11]',
            'email' => '[string:15]',
            'arr' => '[array]',
            'placeholder' => '[string:5]',
        ], $out);
    }

    public function testRedactValuesRecursesIntoArrays(): void
    {
        $redactor = new Redactor();
        $out = $redactor->redactValues(['a' => ['b' => 'secret', 'c' => 3], 'd' => new \stdClass()]);
        $this->assertSame(['a' => ['b' => '[string:6]', 'c' => 3], 'd' => '[object]'], $out);
        $this->assertSame('[string:3]', $redactor->redactValues('abc'));
    }

    public function testFragmentIsKeptAfterQueryString(): void
    {
        $this->assertSame(
            'https://shop.test/p?token=***&page=2#reviews',
            (new \Panth\PerformanceDebugger\Service\Redactor())->sanitizeUrl('https://shop.test/p?token=abc&page=2#reviews')
        );
    }
}
