<?php
declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\Core\Database;
use OwnPay\Event\EventManager;
use OwnPay\Repository\DomainRepository;
use OwnPay\Service\Domain\DnsVerifier;
use OwnPay\Service\Domain\DomainService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the DNS hint helpers on DomainService: the APP_SERVER_IP
 * override (DOM-5) and the Cloudflare edge-IP detection that powers the
 * "IP may be a proxy" warning on the custom-domains page.
 */
#[AllowMockObjectsWithoutExpectations]
final class DomainServiceServerIpTest extends TestCase
{
    private function service(): DomainService
    {
        $db = $this->createMock(Database::class);
        return new DomainService(new DomainRepository($db), new DnsVerifier(), EventManager::getInstance());
    }

    private function clearEnv(): void
    {
        unset($_ENV['APP_DOMAIN'], $_ENV['APP_URL'], $_ENV['APP_SERVER_IP']);
        putenv('APP_DOMAIN');
        putenv('APP_URL');
        putenv('APP_SERVER_IP');
    }

    protected function setUp(): void
    {
        $this->clearEnv();
    }

    protected function tearDown(): void
    {
        $this->clearEnv();
        EventManager::resetInstance();
        parent::tearDown();
    }

    public function testServerIpUsesExplicitOverride(): void
    {
        $_ENV['APP_DOMAIN'] = 'pay.ownpay.org';
        $_ENV['APP_SERVER_IP'] = '203.0.113.10';

        $this->assertSame('203.0.113.10', $this->service()->serverIp());
    }

    public function testServerIpIgnoresInvalidOverride(): void
    {
        $_ENV['APP_DOMAIN'] = '127.0.0.1';
        $_ENV['APP_SERVER_IP'] = 'not-an-ip';

        $this->assertSame('127.0.0.1', $this->service()->serverIp());
    }

    public function testServerIpFallsBackToResolvedHost(): void
    {
        $_ENV['APP_DOMAIN'] = '127.0.0.1';

        $this->assertSame('127.0.0.1', $this->service()->serverIp());
    }

    public function testServerIpFallsBackToLoopbackWhenUnconfigured(): void
    {
        $this->assertSame('127.0.0.1', $this->service()->serverIp());
    }

    public function testServerIpFallsBackToLoopbackWhenHostUnresolvable(): void
    {
        $_ENV['APP_DOMAIN'] = 'oracle-of-nonsense.invalid';

        $this->assertSame('127.0.0.1', $this->service()->serverIp());
    }

    public function testCnameTargetReadsConfiguredAppDomain(): void
    {
        $_ENV['APP_DOMAIN'] = 'pay.ownpay.org';

        $this->assertSame('pay.ownpay.org', $this->service()->cnameTarget());
    }

    public function testCnameTargetFallsBackToAppUrlHost(): void
    {
        $_ENV['APP_URL'] = 'https://pay.ownpay.org/admin';

        $this->assertSame('pay.ownpay.org', $this->service()->cnameTarget());
    }

    public function testIsCloudflareIpRecognizesEdgeRanges(): void
    {
        $this->assertTrue(DomainService::isCloudflareIp('104.16.0.1'));
        $this->assertTrue(DomainService::isCloudflareIp('172.64.99.99'));
        $this->assertTrue(DomainService::isCloudflareIp('198.41.128.255'));
        $this->assertTrue(DomainService::isCloudflareIp('103.22.200.0'));
        $this->assertTrue(DomainService::isCloudflareIp('190.93.245.7'));
        $this->assertTrue(DomainService::isCloudflareIp('190.93.255.255'));
    }

    public function testIsCloudflareIpRejectsOtherAddresses(): void
    {
        $this->assertFalse(DomainService::isCloudflareIp('8.8.8.8'));
        $this->assertFalse(DomainService::isCloudflareIp('127.0.0.1'));
        $this->assertFalse(DomainService::isCloudflareIp('203.0.113.10'));
        $this->assertFalse(DomainService::isCloudflareIp('obviously-not-an-ip'));
    }
}