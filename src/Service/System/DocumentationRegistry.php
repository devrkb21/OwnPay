<?php
declare(strict_types=1);

namespace OwnPay\Service\System;

use OwnPay\Cache\CacheInterface;

/**
 * Resolves contextual admin documentation links from the deployed manifest.
 */
final class DocumentationRegistry
{
    private const CACHE_KEY_PREFIX = 'documentation.manifest.';

    public function __construct(
        private string $manifestPath,
        private string $baseUrl,
        private CacheInterface $cache,
        private string $manifestUrl = '',
        private int $timeout = 3,
        private int $cacheTtl = 86400
    ) {
        $this->baseUrl = rtrim($this->baseUrl, '/');
        $this->manifestUrl = trim($this->manifestUrl);
        $this->timeout = max(1, $this->timeout);
        $this->cacheTtl = max(60, $this->cacheTtl);
    }

    /**
     * Resolve a documentation URL for an admin page and view context.
     *
     * @param string $pageKey Stable application page key.
     * @param bool $isGlobalView Whether the admin page is in global view.
     * @return string Empty when no documentation mapping exists.
     */
    public function urlFor(string $pageKey, bool $isGlobalView): string
    {
        if ($pageKey === '') {
            return '';
        }

        $manifest = $this->manifest();
        $scope = $isGlobalView ? 'global' : 'brand';
        $scopeData = $manifest[$scope] ?? null;
        if (!is_array($scopeData)) {
            return '';
        }
        $scopedPages = $scopeData['pages'] ?? null;
        if (!is_array($scopedPages)) {
            return '';
        }

        $path = $scopedPages[$pageKey] ?? ($scopedPages['*'] ?? null);
        if (!is_string($path) || $path === '') {
            return '';
        }

        if (filter_var($path, FILTER_VALIDATE_URL) !== false) {
            return $path;
        }

        if (!str_starts_with($path, '/')) {
            return '';
        }

        return $this->baseUrl . $path;
    }

    /**
     * Load and cache the manifest. The cache naturally refreshes after 24 hours.
     *
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        $manifestPath = $this->resolveManifestPath();
        $cacheKey = self::CACHE_KEY_PREFIX . sha1($this->manifestUrl . '|' . $manifestPath);
        $manifest = $this->cache->remember($cacheKey, function () use ($manifestPath): array {
            $remoteManifest = $this->loadRemoteManifest();
            if ($remoteManifest !== []) {
                return $remoteManifest;
            }

            return $this->loadLocalManifest($manifestPath);
        }, $this->cacheTtl);

        return is_array($manifest) ? $manifest : [];
    }

    /** @return array<string, mixed> */
    private function loadRemoteManifest(): array
    {
        if ($this->manifestUrl === '' || filter_var($this->manifestUrl, FILTER_VALIDATE_URL) === false) {
            return [];
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => $this->timeout,
                'ignore_errors' => true,
                'header' => "Accept: application/json\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $contents = @file_get_contents($this->manifestUrl, false, $context);
        if ($contents === false) {
            return [];
        }

        return $this->decodeManifest($contents);
    }

    private function resolveManifestPath(): string
    {
        return $this->manifestPath;
    }

    /** @return array<string, mixed> */
    private function loadLocalManifest(string $manifestPath): array
    {
        if (!is_file($manifestPath) || !is_readable($manifestPath)) {
            return [];
        }

        $contents = @file_get_contents($manifestPath);
        return $contents === false ? [] : $this->decodeManifest($contents);
    }

    /** @return array<string, mixed> */
    private function decodeManifest(string $contents): array
    {
        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */

        $global = $decoded['global'] ?? null;
        $brand = $decoded['brand'] ?? null;
        if (!is_array($global) || !is_array($global['pages'] ?? null)
            || !is_array($brand) || !is_array($brand['pages'] ?? null)
        ) {
            return [];
        }

        return $decoded;
    }
}
