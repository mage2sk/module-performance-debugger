<?php
declare(strict_types=1);

namespace Panth\PerformanceDebugger\Service;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\State;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Panth\PerformanceDebugger\Helper\Config;

class CaptureGate
{
    public const PARAM = 'panth_perf';
    public const COOKIE = 'panth_perf';
    private const SIGNATURE_CONTEXT = 'panth_perf_session|';
    private const TOKEN_PATTERN = '/^(\d{9,11})\.([a-f0-9]{64})$/';

    private ?bool $session = null;

    public function __construct(
        private readonly Config $config,
        private readonly HttpRequest $request,
        private readonly RemoteAddress $remoteAddress,
        private readonly State $appState,
        private readonly DeploymentConfig $deploymentConfig,
        private readonly CookieManagerInterface $cookieManager,
        private readonly CookieMetadataFactory $cookieMetadataFactory
    ) {
    }

    public function shouldCapture(): bool
    {
        return $this->hasSession() || $this->isAllowedIp();
    }

    public function canViewToolbar(): bool
    {
        if ($this->hasSession()) {
            return true;
        }

        return $this->config->toolbarForAllowedIps()
            && $this->config->isClientAllowed((string) $this->remoteAddress->getRemoteAddress(), $this->getMode());
    }

    public function hasSession(): bool
    {
        if ($this->session !== null) {
            return $this->session;
        }
        $this->session = false;

        if ($this->hasControlParam()) {
            $query = (string) $this->request->getQuery(self::PARAM);
            if ($this->isValidToken($query)) {
                $this->storeCookie($query);
                $this->session = true;
            } else {
                $this->clearCookie();
            }

            return $this->session;
        }

        $cookie = $this->request->getCookie(self::COOKIE, null);
        $this->session = is_string($cookie) && $this->isValidToken($cookie);

        return $this->session;
    }

    public function hasControlParam(): bool
    {
        $query = $this->request->getQuery(self::PARAM);

        return is_string($query) && $query !== '';
    }

    public function createToken(int $lifetimeSeconds): string
    {
        $expires = (string) (time() + max(60, $lifetimeSeconds));

        return $expires . '.' . $this->sign($expires);
    }

    public function isValidToken(string $token): bool
    {
        if (!preg_match(self::TOKEN_PATTERN, $token, $matches) || (int) $matches[1] < time()) {
            return false;
        }
        $signature = $this->sign($matches[1]);

        return $signature !== '' && hash_equals($signature, $matches[2]);
    }

    public function getTokenExpiry(string $token): int
    {
        return preg_match(self::TOKEN_PATTERN, $token, $matches) ? (int) $matches[1] : 0;
    }

    private function sign(string $expires): string
    {
        $key = $this->getKey();

        return $key === '' ? '' : hash_hmac('sha256', self::SIGNATURE_CONTEXT . $expires, $key);
    }

    private function getKey(): string
    {
        $keys = preg_split('/\s+/', trim((string) $this->deploymentConfig->get('crypt/key')));

        return is_array($keys) ? (string) end($keys) : '';
    }

    private function isAllowedIp(): bool
    {
        $allowed = $this->config->allowedIps();
        if ($allowed === []) {
            return false;
        }
        $ip = (string) $this->remoteAddress->getRemoteAddress();
        if ($ip !== '' && in_array($ip, $allowed, true)) {
            return true;
        }

        return in_array('*', $allowed, true) && $this->getMode() !== State::MODE_PRODUCTION;
    }

    private function getMode(): string
    {
        try {
            return (string) $this->appState->getMode();
        } catch (\Throwable) {
            return State::MODE_PRODUCTION;
        }
    }

    private function storeCookie(string $token): void
    {
        try {
            $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata()
                ->setPath('/')
                ->setHttpOnly(true)
                ->setSecure($this->request->isSecure())
                ->setSameSite('Lax')
                ->setDuration(max(60, $this->getTokenExpiry($token) - time()));
            $this->cookieManager->setPublicCookie(self::COOKIE, $token, $metadata);
        } catch (\Throwable) {
            return;
        }
    }

    private function clearCookie(): void
    {
        if ($this->request->getCookie(self::COOKIE, null) === null) {
            return;
        }
        try {
            $metadata = $this->cookieMetadataFactory->createCookieMetadata()->setPath('/');
            $this->cookieManager->deleteCookie(self::COOKIE, $metadata);
        } catch (\Throwable) {
            return;
        }
    }
}
