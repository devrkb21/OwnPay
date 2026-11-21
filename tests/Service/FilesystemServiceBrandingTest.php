<?php

declare(strict_types=1);

// Same test-only move_uploaded_file() shadow as FilesystemServiceTest (see the comment there).
namespace OwnPay\Service\System {
    if (!function_exists(__NAMESPACE__ . '\\move_uploaded_file')) {
        function move_uploaded_file(string $from, string $to): bool
        {
            return @copy($from, $to) && @unlink($from);
        }
    }
}

namespace Tests\Service {

    use OwnPay\Service\System\FilesystemService;
    use PHPUnit\Framework\Attributes\DataProvider;
    use PHPUnit\Framework\TestCase;

    /**
     * Branding images (logos, favicons) are served from the payment origin: only
     * png/jpg/jpeg/webp/ico, at most 2 MiB.
     */
    final class FilesystemServiceBrandingTest extends TestCase
    {
        private const PNG_BYTES_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

        private string $root;
        private string $publicUploadsDir;
        private FilesystemService $fs;

        protected function setUp(): void
        {
            $this->root = sys_get_temp_dir() . '/ownpay-brand-test-' . bin2hex(random_bytes(6));
            $this->publicUploadsDir = $this->root . '/public/assets/uploads';
            mkdir($this->root . '/storage', 0755, true);
            mkdir($this->publicUploadsDir, 0755, true);
            $this->fs = new FilesystemService($this->root . '/storage', $this->publicUploadsDir);
        }

        protected function tearDown(): void
        {
            $this->removeDir($this->root);
        }

        private function removeDir(string $dir): void
        {
            if (!is_dir($dir)) {
                return;
            }
            foreach (scandir($dir) as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $path = $dir . '/' . $item;
                is_dir($path) ? $this->removeDir($path) : unlink($path);
            }
            rmdir($dir);
        }

        private static function png(): string
        {
            return base64_decode(self::PNG_BYTES_BASE64);
        }

        /** One-image ICO container around a PNG (Vista+ format), sniffed as an icon by finfo. */
        private static function ico(): string
        {
            $png = self::png();
            return pack('vvv', 0, 1, 1)
                . pack('CCCCvvVV', 1, 1, 0, 0, 1, 32, strlen($png), 22)
                . $png;
        }

        private static function gdImage(string $type): string
        {
            $im = imagecreatetruecolor(2, 2);
            ob_start();
            $type === 'jpg' ? imagejpeg($im) : imagewebp($im);
            $bytes = (string) ob_get_clean();
            imagedestroy($im);
            return $bytes;
        }

        /** @return array{error: int, name: string, tmp_name: string, size: int} */
        private function upload(string $name, string $bytes, ?int $reportedSize = null): array
        {
            $tmp = tempnam(sys_get_temp_dir(), 'op-brand-');
            file_put_contents($tmp, $bytes);
            return ['error' => UPLOAD_ERR_OK, 'name' => $name, 'tmp_name' => $tmp, 'size' => $reportedSize ?? strlen($bytes)];
        }

        private function storedFiles(): int
        {
            $n = 0;
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->publicUploadsDir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $n += $f->isFile() ? 1 : 0;
            }
            return $n;
        }

        /** @return array<string, array{string, string}> */
        public static function allowedProvider(): array
        {
            return [
                'png'        => ['logo.png', self::png()],
                'upper PNG'  => ['LOGO.PNG', self::png()],
                'jpg'        => ['logo.jpg', self::gdImage('jpg')],
                'jpeg'       => ['logo.jpeg', self::gdImage('jpg')],
                'webp'       => ['logo.webp', self::gdImage('webp')],
                'ico'        => ['favicon.ico', self::ico()],
            ];
        }

        #[DataProvider('allowedProvider')]
        public function testAcceptsAllowedImageTypes(string $name, string $bytes): void
        {
            $path = $this->fs->storeBrandingUpload($this->upload($name, $bytes), 'branding');

            $this->assertStringStartsWith('/assets/uploads/branding/', $path);
            $this->assertStringEndsWith('.' . strtolower(pathinfo($name, PATHINFO_EXTENSION)), $path);
            $this->assertFileExists($this->publicUploadsDir . substr($path, strlen('/assets/uploads')));
        }

        /** @return array<string, array{string, string}> */
        public static function rejectedProvider(): array
        {
            return [
                'benign svg' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"/>'],
                'pdf'        => ['logo.pdf', "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF"],
                'zip'        => ['logo.zip', "PK\x05\x06" . str_repeat("\0", 18)],
                'gif'        => ['logo.gif', base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7')],
                'php'        => ['logo.php', '<?php echo 1;'],
                'no ext'     => ['logo', self::png()],
                'png as svg' => ['logo.svg', self::png()],
            ];
        }

        #[DataProvider('rejectedProvider')]
        public function testRejectsOtherTypesAndStoresNothing(string $name, string $bytes): void
        {
            try {
                $this->fs->storeBrandingUpload($this->upload($name, $bytes), 'branding');
                $this->fail("{$name} must be rejected");
            } catch (\RuntimeException $e) {
                $this->assertSame('Branding image must be PNG, JPG, WebP or ICO', $e->getMessage());
            }
            $this->assertSame(0, $this->storedFiles());
        }

        public function testMimeSignatureStillChecked(): void
        {
            $this->expectExceptionMessage('File MIME type mismatch');
            $this->fs->storeBrandingUpload($this->upload('logo.png', 'not an image'), 'branding');
        }

        public function testExactlyTwoMegabytesIsAccepted(): void
        {
            $bytes = str_pad(self::png(), FilesystemService::BRANDING_MAX_BYTES, "\0");
            $this->assertSame(2 * 1024 * 1024, strlen($bytes));
            $path = $this->fs->storeBrandingUpload($this->upload('logo.png', $bytes), 'branding');
            $this->assertStringEndsWith('.png', $path);
        }

        public function testOneByteOverTwoMegabytesIsRejected(): void
        {
            $bytes = str_pad(self::png(), FilesystemService::BRANDING_MAX_BYTES + 1, "\0");
            try {
                $this->fs->storeBrandingUpload($this->upload('logo.png', $bytes), 'branding');
                $this->fail('oversize image must be rejected');
            } catch (\RuntimeException $e) {
                $this->assertSame('Branding image is larger than 2 MB', $e->getMessage());
            }
            $this->assertSame(0, $this->storedFiles());
        }

        public function testClientReportedSizeIsIgnored(): void
        {
            $bytes = str_pad(self::png(), FilesystemService::BRANDING_MAX_BYTES + 1, "\0");
            $this->expectExceptionMessage('Branding image is larger than 2 MB');
            $this->fs->storeBrandingUpload($this->upload('logo.png', $bytes, 100), 'branding');
        }

        public function testUploadErrorIsRejected(): void
        {
            $file = $this->upload('logo.png', self::png());
            $file['error'] = UPLOAD_ERR_PARTIAL;
            $this->expectExceptionMessage('Upload error: ' . UPLOAD_ERR_PARTIAL);
            $this->fs->storeBrandingUpload($file, 'branding');
        }

        public function testGeneralPublicUploadIsUnchanged(): void
        {
            // Gateways/plugins still use storePublicUpload with the wider list (out of scope here).
            $path = $this->fs->storePublicUpload($this->upload('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"/>'), 'gateways');
            $this->assertStringEndsWith('.svg', $path);
        }

        public function testBrandingListIsSubsetOfGeneralList(): void
        {
            $general = (new \ReflectionClassConstant(FilesystemService::class, 'ALLOWED_EXTENSIONS'))->getValue();
            $this->assertSame([], array_values(array_diff(FilesystemService::BRANDING_EXTENSIONS, $general)));
            $this->assertSame(['png', 'jpg', 'jpeg', 'webp', 'ico'], FilesystemService::BRANDING_EXTENSIONS);
        }

        public function testSettingsControllerUsesBrandingUploadOnly(): void
        {
            $src = (string) file_get_contents(dirname(__DIR__, 2) . '/src/Controller/Admin/SettingsController.php');
            $this->assertSame(0, substr_count($src, 'storePublicUpload('), 'every SettingsController upload is a branding image');
            $this->assertSame(3, substr_count($src, 'storeBrandingUpload('), 'brand logo, brand favicon and branding page images');
        }
    }
}
