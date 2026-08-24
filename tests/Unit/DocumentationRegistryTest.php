<?php
declare(strict_types=1);

namespace Tests\Unit;

use OwnPay\Cache\CacheInterface;
use OwnPay\Service\System\DocumentationRegistry;
use PHPUnit\Framework\TestCase;

final class DocumentationRegistryTest extends TestCase
{
    public function testItResolvesSeparateGlobalAndBrandMappings(): void
    {
        $cache = new InMemoryDocumentationCache();
        $manifestPath = tempnam(sys_get_temp_dir(), 'ownpay-docs-');
        self::assertIsString($manifestPath);
        file_put_contents($manifestPath, json_encode([
            'global' => ['pages' => ['reports' => '/global/reports']],
            'brand' => ['pages' => ['reports' => '/brand/reports']],
        ], JSON_THROW_ON_ERROR));

        try {
            $registry = new DocumentationRegistry($manifestPath, 'https://ownpay.org/docs', $cache);

            self::assertSame('https://ownpay.org/docs/global/reports', $registry->urlFor('reports', true));
            self::assertSame('https://ownpay.org/docs/brand/reports', $registry->urlFor('reports', false));
            self::assertSame('', $registry->urlFor('missing', false));
            self::assertSame(86400, $cache->lastTtl);
        } finally {
            unlink($manifestPath);
        }
    }

    public function testCachedManifestIsUsedUntilExpiry(): void
    {
        $cache = new InMemoryDocumentationCache();
        $manifestPath = tempnam(sys_get_temp_dir(), 'ownpay-docs-');
        self::assertIsString($manifestPath);
        file_put_contents($manifestPath, json_encode([
            'global' => ['pages' => []],
            'brand' => ['pages' => ['dashboard' => '/dashboard']],
        ], JSON_THROW_ON_ERROR));

        try {
            $registry = new DocumentationRegistry($manifestPath, 'https://docs.example.test', $cache);
            self::assertSame('https://docs.example.test/dashboard', $registry->urlFor('dashboard', false));

            file_put_contents($manifestPath, json_encode([
                'global' => ['pages' => []],
                'brand' => ['pages' => ['dashboard' => '/changed']],
            ], JSON_THROW_ON_ERROR));

            self::assertSame('https://docs.example.test/dashboard', $registry->urlFor('dashboard', false));
        } finally {
            unlink($manifestPath);
        }
    }

    public function testItLoadsTheCanonicalManifestFilename(): void
    {
        $cache = new InMemoryDocumentationCache();
        $dir = sys_get_temp_dir() . '/ownpay-docs-' . uniqid('', true);
        self::assertTrue(mkdir($dir));

        $manifestPath = $dir . '/docs-manifest.json';
        file_put_contents($manifestPath, json_encode([
            'global' => ['pages' => []],
            'brand' => ['pages' => ['settings' => '/settings']],
        ], JSON_THROW_ON_ERROR));

        try {
            $registry = new DocumentationRegistry($manifestPath, 'https://docs.example.test', $cache);
            self::assertSame('https://docs.example.test/settings', $registry->urlFor('settings', false));
            self::assertSame(86400, $cache->lastTtl);
        } finally {
            unlink($manifestPath);
            rmdir($dir);
        }
    }

    public function testItPrefersTheRemoteManifestAndWorksWithoutALocalFile(): void
    {
        $cache = new InMemoryDocumentationCache();
        $remotePath = tempnam(sys_get_temp_dir(), 'ownpay-docs-');
        self::assertIsString($remotePath);
        file_put_contents($remotePath, json_encode([
            'global' => ['pages' => ['dashboard' => '/remote-dashboard']],
            'brand' => ['pages' => []],
        ], JSON_THROW_ON_ERROR));

        try {
            $registry = new DocumentationRegistry('', 'https://docs.example.test', $cache, 'file://' . $remotePath);

            self::assertSame(
                'https://docs.example.test/remote-dashboard',
                $registry->urlFor('dashboard', true)
            );
        } finally {
            unlink($remotePath);
        }
    }
}

final class InMemoryDocumentationCache implements CacheInterface
{
    /** @var array<string, mixed> */
    private array $values = [];
    public int $lastTtl = 0;

    public function get(string $key): mixed { return $this->values[$key] ?? null; }
    public function set(string $key, mixed $value, int $ttl = 3600): void { $this->values[$key] = $value; $this->lastTtl = $ttl; }
    public function add(string $key, mixed $value, int $ttl = 3600): bool
    {
        if (array_key_exists($key, $this->values)) { return false; }
        $this->set($key, $value, $ttl);
        return true;
    }
    public function has(string $key): bool { return array_key_exists($key, $this->values); }
    public function delete(string $key): void { unset($this->values[$key]); }
    public function flush(): void { $this->values = []; }
    public function remember(string $key, callable $callback, int $ttl = 3600): mixed
    {
        if (array_key_exists($key, $this->values)) { return $this->values[$key]; }
        $value = $callback();
        $this->set($key, $value, $ttl);
        return $value;
    }
}
