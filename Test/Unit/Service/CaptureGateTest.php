<?php
declare(strict_types=1);

namespace Panth\PerformanceDebugger\Test\Unit\Service;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\State;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Stdlib\Cookie\CookieMetadata;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\Cookie\PublicCookieMetadata;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Panth\PerformanceDebugger\Helper\Config;
use Panth\PerformanceDebugger\Service\CaptureGate;
use PHPUnit\Framework\TestCase;

class CaptureGateTest extends TestCase
{
    private const KEY = 'oldkey0000000000 newkey1111111111';

    private ?CookieManagerInterface $cookieManager = null;

    private function gate(
        array $query = [],
        array $cookies = [],
        array $allowedIps = [],
        string $ip = '8.8.8.8',
        string|\Throwable $mode = State::MODE_DEFAULT,
        string $cryptKey = self::KEY,
        ?Config $config = null
    ): CaptureGate {
        $request = $this->createStub(HttpRequest::class);
        $request->method('getQuery')->willReturnCallback(static fn($name = null) => $query[$name] ?? null);
        $request->method('getCookie')->willReturnCallback(
            static fn($name = null, $default = null) => $cookies[$name] ?? $default
        );
        $request->method('isSecure')->willReturn(true);

        $remote = $this->createStub(RemoteAddress::class);
        $remote->method('getRemoteAddress')->willReturn($ip);

        $state = $this->createStub(State::class);
        if ($mode instanceof \Throwable) {
            $state->method('getMode')->willThrowException($mode);
        } else {
            $state->method('getMode')->willReturn($mode);
        }

        $deployment = $this->createStub(DeploymentConfig::class);
        $deployment->method('get')->willReturnCallback(
            static fn($path) => $path === 'crypt/key' ? $cryptKey : null
        );

        if ($config === null) {
            $config = $this->createStub(Config::class);
            $config->method('allowedIps')->willReturn($allowedIps);
        }

        $metadataFactory = $this->createStub(CookieMetadataFactory::class);
        $metadataFactory->method('createPublicCookieMetadata')->willReturnCallback(
            static fn() => new PublicCookieMetadata()
        );
        $metadataFactory->method('createCookieMetadata')->willReturnCallback(static fn() => new CookieMetadata());

        return new CaptureGate(
            $config,
            $request,
            $remote,
            $state,
            $deployment,
            $this->cookieManager ?? $this->createStub(CookieManagerInterface::class),
            $metadataFactory
        );
    }

    public function testCreatedTokenIsValidAndCarriesExpiry(): void
    {
        $gate = $this->gate();
        $before = time();
        $token = $gate->createToken(3600);

        $this->assertMatchesRegularExpression('/^\d{10}\.[a-f0-9]{64}$/', $token);
        $this->assertTrue($gate->isValidToken($token));
        $expiry = $gate->getTokenExpiry($token);
        $this->assertGreaterThanOrEqual($before + 3600, $expiry);
        $this->assertLessThanOrEqual(time() + 3600, $expiry);
    }

    public function testTokenLifetimeHasSixtySecondFloor(): void
    {
        $gate = $this->gate();
        $expiry = $gate->getTokenExpiry($gate->createToken(1));
        $this->assertGreaterThanOrEqual(time() + 59, $expiry);
    }

    public function testTokenIsSignedWithTheLatestCryptKey(): void
    {
        $token = $this->gate()->createToken(600);
        [$expires, $signature] = explode('.', $token);

        $this->assertSame(hash_hmac('sha256', 'panth_perf_session|' . $expires, 'newkey1111111111'), $signature);
        $this->assertTrue($this->gate([], [], [], '1.1.1.1', State::MODE_DEFAULT, 'newkey1111111111')->isValidToken($token));
        $this->assertFalse($this->gate([], [], [], '1.1.1.1', State::MODE_DEFAULT, 'otherkey')->isValidToken($token));
    }

    public function testTamperedExpiredAndMalformedTokensAreRejected(): void
    {
        $gate = $this->gate();
        $token = $gate->createToken(600);
        [$expires, $signature] = explode('.', $token);

        $this->assertFalse($gate->isValidToken(($expires + 100) . '.' . $signature));
        $this->assertFalse($gate->isValidToken($expires . '.' . str_repeat('0', 64)));

        $past = (string) (time() - 10);
        $this->assertFalse($gate->isValidToken($past . '.' . hash_hmac('sha256', 'panth_perf_session|' . $past, 'newkey1111111111')));

        $this->assertFalse($gate->isValidToken('0'));
        $this->assertFalse($gate->isValidToken(''));
        $this->assertFalse($gate->isValidToken($token . 'x'));
        $this->assertSame(0, $gate->getTokenExpiry('garbage'));
    }

    public function testNoCryptKeyMeansNoValidTokens(): void
    {
        $gate = $this->gate([], [], [], '8.8.8.8', State::MODE_DEFAULT, '');
        $this->assertFalse($gate->isValidToken($gate->createToken(600)));
    }

    public function testHasControlParam(): void
    {
        $this->assertFalse($this->gate()->hasControlParam());
        $this->assertFalse($this->gate(['panth_perf' => ''])->hasControlParam());
        $this->assertTrue($this->gate(['panth_perf' => '0'])->hasControlParam());
    }

    public function testValidQueryTokenStartsSessionAndStoresCookie(): void
    {
        $token = $this->gate()->createToken(600);

        $this->cookieManager = $this->createMock(CookieManagerInterface::class);
        $this->cookieManager->expects($this->once())->method('setPublicCookie')->with(
            'panth_perf',
            $token,
            $this->callback(static function (PublicCookieMetadata $meta): bool {
                return $meta->getPath() === '/'
                    && $meta->getHttpOnly() === true
                    && $meta->getSecure() === true
                    && $meta->getSameSite() === 'Lax'
                    && $meta->getDuration() >= 60;
            })
        );
        $this->cookieManager->expects($this->never())->method('deleteCookie');

        $gate = $this->gate(['panth_perf' => $token]);
        $this->assertTrue($gate->hasSession());
        $this->assertTrue($gate->shouldCapture());
        $this->assertTrue($gate->canViewToolbar());
    }

    public function testInvalidQueryTokenClearsExistingCookie(): void
    {
        $this->cookieManager = $this->createMock(CookieManagerInterface::class);
        $this->cookieManager->expects($this->never())->method('setPublicCookie');
        $this->cookieManager->expects($this->once())->method('deleteCookie')->with(
            'panth_perf',
            $this->isInstanceOf(CookieMetadata::class)
        );

        $gate = $this->gate(['panth_perf' => '0'], ['panth_perf' => 'old']);
        $this->assertFalse($gate->hasSession());
    }

    public function testInvalidQueryTokenWithoutCookieDoesNotTouchCookies(): void
    {
        $this->cookieManager = $this->createMock(CookieManagerInterface::class);
        $this->cookieManager->expects($this->never())->method('deleteCookie');
        $this->cookieManager->expects($this->never())->method('setPublicCookie');

        $this->assertFalse($this->gate(['panth_perf' => 'bogus'])->hasSession());
    }

    public function testCookieFailuresAreSwallowed(): void
    {
        $token = $this->gate()->createToken(600);
        $this->cookieManager = $this->createStub(CookieManagerInterface::class);
        $this->cookieManager->method('setPublicCookie')->willThrowException(new \RuntimeException('headers sent'));
        $this->cookieManager->method('deleteCookie')->willThrowException(new \RuntimeException('headers sent'));

        $this->assertTrue($this->gate(['panth_perf' => $token])->hasSession());
        $this->assertFalse($this->gate(['panth_perf' => '0'], ['panth_perf' => 'x'])->hasSession());
    }

    public function testCookieSessionIsRecognisedAndCached(): void
    {
        $token = $this->gate()->createToken(600);
        $this->assertTrue($this->gate([], ['panth_perf' => $token])->hasSession());
        $this->assertFalse($this->gate([], ['panth_perf' => 'nope'])->hasSession());

        $config = $this->createMock(Config::class);
        $config->expects($this->never())->method('allowedIps');
        $gate = $this->gate([], ['panth_perf' => $token], [], '8.8.8.8', State::MODE_DEFAULT, self::KEY, $config);
        $this->assertTrue($gate->shouldCapture());
        $this->assertTrue($gate->hasSession());
    }

    public function testShouldCaptureByAllowedIp(): void
    {
        $this->assertFalse($this->gate([], [], [], '1.2.3.4')->shouldCapture());
        $this->assertTrue($this->gate([], [], ['1.2.3.4'], '1.2.3.4', State::MODE_PRODUCTION)->shouldCapture());
        $this->assertFalse($this->gate([], [], ['5.5.5.5'], '1.2.3.4')->shouldCapture());
        $this->assertTrue($this->gate([], [], ['*'], '1.2.3.4', State::MODE_DEVELOPER)->shouldCapture());
        $this->assertFalse($this->gate([], [], ['*'], '1.2.3.4', State::MODE_PRODUCTION)->shouldCapture());
    }

    public function testUnknownModeIsTreatedAsProduction(): void
    {
        $gate = $this->gate([], [], ['*'], '1.2.3.4', new \LogicException('no state'));
        $this->assertFalse($gate->shouldCapture());
    }

    public function testCanViewToolbarFallsBackToIpAccess(): void
    {
        $config = $this->createStub(Config::class);
        $config->method('toolbarForAllowedIps')->willReturn(true);
        $config->method('isClientAllowed')->willReturnCallback(
            static fn(string $ip, string $mode) => $ip === '1.2.3.4' && $mode === State::MODE_DEVELOPER
        );
        $this->assertTrue(
            $this->gate([], [], [], '1.2.3.4', State::MODE_DEVELOPER, self::KEY, $config)->canViewToolbar()
        );
        $this->assertFalse(
            $this->gate([], [], [], '1.2.3.4', State::MODE_PRODUCTION, self::KEY, $config)->canViewToolbar()
        );

        $disabled = $this->createStub(Config::class);
        $disabled->method('toolbarForAllowedIps')->willReturn(false);
        $disabled->method('isClientAllowed')->willReturn(true);
        $this->assertFalse(
            $this->gate([], [], [], '1.2.3.4', State::MODE_DEVELOPER, self::KEY, $disabled)->canViewToolbar()
        );
    }
}
