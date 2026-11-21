<?php
declare(strict_types=1);

namespace OwnPay\Service\System;

use OwnPay\Support\DateHelper;

/**
 * Service facilitating safe filesystem access with validation filters.
 *
 * Implements security checks to prevent path traversal exploits and validates
 * uploaded file extensions against a strict whitelist aligned with OWASP recommendations.
 */
final class FilesystemService
{
    /**
     * Directory path serving as the base root for all PRIVATE file operations (not web-accessible).
     *
     * @var string
     */
    private string $baseDir;

    /**
     * Directory path serving as the base root for PUBLIC uploads (web-accessible via /assets/uploads/...).
     *
     * @var string
     */
    private string $publicUploadsDir;

    /**
     * Whitelist of permitted upload file extensions.
     *
     * @var string[]
     */
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'pdf', 'zip']; // Keep it minimum as possible for security.

    /**
     * Extensions accepted for branding images (logos, favicons). These are served from the
     * payment origin, so no SVG (script risk), PDF or ZIP. Must stay a subset of
     * ALLOWED_EXTENSIONS; the MIME signature check of moveValidatedUpload() still applies.
     *
     * @var string[]
     */
    public const BRANDING_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'ico'];

    /**
     * Largest accepted branding image, in bytes (2 MiB).
     */
    public const BRANDING_MAX_BYTES = 2 * 1024 * 1024;

    /**
     * Initialises the filesystem service.
     *
     * @param string|null $baseDir          Private base folder absolute path. Defaults to the system storage directory.
     * @param string|null $publicUploadsDir Public uploads base folder absolute path. Defaults to `public/assets/uploads`.
     */
    public function __construct(?string $baseDir = null, ?string $publicUploadsDir = null)
    {
        $this->baseDir = $baseDir ?? dirname(__DIR__, 3) . '/storage';
        $this->publicUploadsDir = $publicUploadsDir ?? dirname(__DIR__, 3) . '/public/assets/uploads';
    }

    /**
     * Stores an uploaded file securely within the storage hierarchy.
     *
     * Validates file upload errors, cross-checks file extensions against the whitelist,
     * matches the file's binary signature (MIME type), and moves it to a structured target path.
     *
     * @param array{error: int, name: string, tmp_name: string} $file Associative array structure containing PHP upload metadata.
     * @param string $subDir Sub-directory path where the file should be deposited.
     * @return string Relative path referencing the newly stored file from the storage root.
     * @throws \RuntimeException If the upload contains errors, validation fails, or file transfer fails.
     */
    public function storeUpload(array $file, string $subDir = 'uploads'): string
    {
        $relative = $this->moveValidatedUpload($file, $this->baseDir, $subDir);
        return $relative;
    }

    /**
     * Stores an uploaded file securely under the PUBLIC web root, for assets that must be
     * directly reachable via `<img src>`/`<link href>` (gateway logos/QRs, brand logos,
     * favicons, staff avatars). Do NOT use this for sensitive uploads (e.g. dispute evidence) -
     * use {@see storeUpload()} for those so they stay outside the web root.
     *
     * Applies the exact same validation as {@see storeUpload()} (extension whitelist, MIME
     * signature check, SVG XSS/XXE screening).
     *
     * @param array{error: int, name: string, tmp_name: string} $file Associative array structure containing PHP upload metadata.
     * @param string $subDir Sub-directory path where the file should be deposited.
     * @return string Web-root-relative URL (leading slash) referencing the newly stored file, e.g. `/assets/uploads/gateways/2026/07/<hash>.png`.
     * @throws \RuntimeException If the upload contains errors, validation fails, or file transfer fails.
     */
    public function storePublicUpload(array $file, string $subDir = 'uploads'): string
    {
        $relative = $this->moveValidatedUpload($file, $this->publicUploadsDir, $subDir);
        return '/assets/uploads/' . $relative;
    }

    /**
     * Stores a branding image (brand logo, favicon, light/dark application logo) under the
     * PUBLIC web root. Stricter than {@see storePublicUpload()}: only png/jpg/jpeg/webp/ico and
     * at most BRANDING_MAX_BYTES; then the same extension, MIME signature and move logic.
     *
     * @param array{error: int, name: string, tmp_name: string} $file Associative array structure containing PHP upload metadata.
     * @param string $subDir Sub-directory path where the file should be deposited.
     * @return string Web-root-relative URL (leading slash) of the stored file.
     * @throws \RuntimeException If the type or size is not allowed, or the upload fails validation.
     */
    public function storeBrandingUpload(array $file, string $subDir = 'branding'): string
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Upload error: ' . $file['error']);
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::BRANDING_EXTENSIONS, true)) {
            throw new \RuntimeException('Branding image must be PNG, JPG, WebP or ICO');
        }

        // Size of the file actually received, not the client-reported size.
        clearstatcache(true, $file['tmp_name']);
        $size = @filesize($file['tmp_name']);
        if ($size === false) {
            throw new \RuntimeException('Failed to read uploaded file size');
        }
        if ($size > self::BRANDING_MAX_BYTES) {
            throw new \RuntimeException('Branding image is larger than 2 MB');
        }

        return $this->storePublicUpload($file, $subDir);
    }

    /**
     * Validates and moves an uploaded file into the given base directory, shared by both the
     * private ({@see storeUpload()}) and public ({@see storePublicUpload()}) storage paths.
     *
     * @param array{error: int, name: string, tmp_name: string} $file Associative array structure containing PHP upload metadata.
     * @param string $targetBaseDir Absolute path of the directory tree to store the file under.
     * @param string $subDir Sub-directory path where the file should be deposited.
     * @return string Path of the stored file, relative to $targetBaseDir.
     * @throws \RuntimeException If the upload contains errors, validation fails, or file transfer fails.
     */
    private function moveValidatedUpload(array $file, string $targetBaseDir, string $subDir): string
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Upload error: ' . $file['error']);
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            throw new \RuntimeException("File type not allowed: .{$ext}");
        }

        // Verify MIME type matches extension to detect spoofing attempts
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        if (!is_string($mimeType)) {
            throw new \RuntimeException('Failed to determine file MIME type');
        }
        if (!$this->isMimeAllowed($mimeType, $ext)) {
            throw new \RuntimeException('File MIME type mismatch');
        }

        // Add SVG safety check to mitigate Stored XSS and XML attacks
        if ($ext === 'svg') {
            $svgContent = file_get_contents($file['tmp_name']);
            if ($svgContent !== false) {
                if ($this->isSvgMalicious($svgContent)) {
                    throw new \RuntimeException('Malicious SVG content detected');
                }
            }
        }

        $relativeDir = $subDir . '/' . DateHelper::yearMonthPath();
        $targetDir = $targetBaseDir . '/' . $relativeDir;
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0755, true);
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $targetPath = $targetDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new \RuntimeException('Failed to move uploaded file');
        }

        return $relativeDir . '/' . $filename;
    }

    /**
     * Reads the complete contents of a file safely.
     *
     * Prevents path traversal vulnerabilities by enforcing canonical path checks.
     *
     * @param string $relativePath Path to target file relative to the storage directory.
     * @return string The raw contents of the target file.
     * @throws \RuntimeException If the file is missing or validation fails.
     */
    public function read(string $relativePath): string
    {
        $fullPath = $this->resolveSafe($relativePath);
        if (!file_exists($fullPath)) {
            throw new \RuntimeException('File not found');
        }
        return file_get_contents($fullPath) ?: '';
    }

    /**
     * Deletes a file safely from the filesystem.
     *
     * @param string $relativePath Path to target file relative to the storage directory.
     * @return bool True if deletion succeeded, false if target was missing or deletion failed.
     * @throws \RuntimeException If directory traversal attempts are detected.
     */
    public function delete(string $relativePath): bool
    {
        $fullPath = $this->resolveSafe($relativePath);
        if (file_exists($fullPath)) {
            return @unlink($fullPath);
        }
        return false;
    }

    /**
     * Validates whether a file exists at the specified path relative to the storage base.
     *
     * @param string $relativePath Path to target file relative to the storage directory.
     * @return bool True if file exists and traversal checks pass, false otherwise.
     */
    public function exists(string $relativePath): bool
    {
        try {
            return file_exists($this->resolveSafe($relativePath));
        } catch (\RuntimeException) {
            return false;
        }
    }

    /**
     * Resolves a relative path to its absolute location while blocking traversal attempts.
     *
     * Asserts that the target absolute path resides strictly within the storage base directory.
     *
     * @param string $relativePath Relative path candidate.
     * @return string The validated absolute filesystem path.
     * @throws \RuntimeException If path validation fails or traversal is detected.
     */
    private function resolveSafe(string $relativePath): string
    {
        $fullPath = realpath($this->baseDir . '/' . $relativePath);
        if ($fullPath === false) {
            $fullPath = $this->baseDir . '/' . $relativePath;
            $normalized = str_replace(['../', '..\\'], '', $fullPath);
            if ($normalized !== $fullPath) {
                throw new \RuntimeException('Path traversal detected');
            }
            return $normalized;
        }

        $baseReal = realpath($this->baseDir);
        if ($baseReal === false || !str_starts_with($fullPath, $baseReal)) {
            throw new \RuntimeException('Path traversal detected');
        }

        return $fullPath;
    }

    /**
     * Cross-checks a resolved MIME type signature against an extension.
     *
     * @param string $mime Resolved MIME type of the file content.
     * @param string $ext Extension string to verify.
     * @return bool True if MIME matches the extension structure, false otherwise.
     */
    private function isMimeAllowed(string $mime, string $ext): bool
    {
        $allowed = [
            'jpg'  => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png'  => ['image/png'],
            'gif'  => ['image/gif'],
            'webp' => ['image/webp'],
            'svg'  => ['image/svg+xml'],
            'ico'  => ['image/x-icon', 'image/vnd.microsoft.icon', 'image/x-ico', 'image/icon'],
            'pdf'  => ['application/pdf'],
            'zip'  => ['application/zip', 'application/x-zip-compressed'],
        ];

        return in_array($mime, $allowed[$ext] ?? [], true);
    }

    /**
     * Inspects SVG XML payload for XSS scripts, handlers, javascript URIs, or XXE entities.
     *
     * @param string $content Raw SVG content.
     * @return bool True if content is deemed malicious.
     */
    private function isSvgMalicious(string $content): bool
    {
        $lower = strtolower($content);

        // Block script tag
        if (str_contains($lower, '<script')) {
            return true;
        }

        // Block XML entity definitions/DTDs (XXE mitigation)
        if (str_contains($lower, '<!entity') || str_contains($lower, '<!doctype')) {
            return true;
        }

        // Block javascript: URIs
        if (str_contains($lower, 'javascript:')) {
            return true;
        }

        // Block inline event handlers (e.g. onload, onclick)
        if (preg_match('/on[a-z]+\s*=/i', $content)) {
            return true;
        }

        return false;
    } // Add more if there any.
}
