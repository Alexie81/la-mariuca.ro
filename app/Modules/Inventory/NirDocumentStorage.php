<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use Psr\Http\Message\UploadedFileInterface;

/** Private original documents. Access is always mediated by the authorized NIR controller. */
final class NirDocumentStorage
{
    public const MAX_BYTES = 15 * 1024 * 1024;
    private const MIME_TYPES = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'],
        'png' => ['image/png'], 'webp' => ['image/webp'],
        'xlsx' => ['application/zip', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'xml' => ['text/xml', 'application/xml', 'text/plain'],
    ];

    public function __construct(private readonly string $root)
    {
    }

    /** @return array<string, mixed> */
    public function store(UploadedFileInterface $file): array
    {
        if ($file->getError() !== UPLOAD_ERR_OK || ($file->getSize() ?? 0) <= 0 || $file->getSize() > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Alege un document de maximum 15 MB. Încărcarea trebuie să fie completă.');
        }
        $name = self::safeName($file->getClientFilename() ?? 'document');
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!isset(self::MIME_TYPES[$extension])) {
            throw new \InvalidArgumentException('Sunt acceptate PDF, JPG, PNG, WebP, XLSX și XML.');
        }
        $stream = $file->getStream();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }
        $bytes = '';
        while (!$stream->eof() && strlen($bytes) <= self::MAX_BYTES) {
            $part = $stream->read(min(65536, self::MAX_BYTES + 1 - strlen($bytes)));
            if ($part === '') {
                break;
            }
            $bytes .= $part;
        }
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('Documentul este gol sau depășește limita de 15 MB.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!in_array($mime, self::MIME_TYPES[$extension], true)) {
            throw new \InvalidArgumentException('Conținutul fișierului nu corespunde extensiei. Alege documentul original.');
        }
        if (str_starts_with((string) $mime, 'image/')) {
            $dimensions = @getimagesizefromstring($bytes);
            if ($dimensions === false || $dimensions[0] * $dimensions[1] > 40_000_000 || $dimensions[0] < 1 || $dimensions[1] < 1) {
                throw new \InvalidArgumentException('Imaginea este invalidă sau depășește 40 megapixeli.');
            }
        }
        if ($extension === 'xml') {
            self::validateXml($bytes);
            $mime = 'application/xml';
        }
        if (!is_dir($this->directory()) && !mkdir($this->directory(), 0770, true) && !is_dir($this->directory())) {
            throw new \RuntimeException('Documentele nu pot fi salvate momentan.');
        }
        $storedName = bin2hex(random_bytes(20)) . '.' . $extension;
        $path = $this->directory() . '/' . $storedName;
        if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) {
            @unlink($path);
            throw new \RuntimeException('Documentul nu a putut fi salvat integral.');
        }
        @chmod($path, 0660);
        try {
            if ($extension === 'xlsx') {
                self::validateWorkbook($path);
                $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            }
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        }
        return [
            'stored_name' => $storedName, 'original_name' => $name, 'mime_type' => $mime,
            'extension' => $extension, 'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
            'extraction_status' => in_array($extension, ['xlsx', 'xml'], true) ? 'pending' : 'manual',
        ];
    }

    public function path(?string $name): ?string
    {
        if ($name === null || preg_match('/^[a-f0-9]{40}\.(pdf|jpe?g|png|webp|xlsx|xml)$/D', $name) !== 1) {
            return null;
        }
        $path = $this->directory() . '/' . $name;
        return is_file($path) && !is_link($path) ? $path : null;
    }

    public function remove(?string $name): void
    {
        $path = $this->path($name);
        if ($path !== null) {
            @unlink($path);
        }
    }

    public static function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1f\x7f<>:"|?*]/u', '_', $name) ?? 'document';
        $name = trim($name, " .\t\n\r\0\x0B");
        if ($name === '') { return 'document'; }
        if (mb_strlen($name) <= 220) { return $name; }
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $suffix = $extension !== '' && mb_strlen($extension) <= 10 ? '.' . $extension : '';
        return mb_substr($name, 0, 220 - mb_strlen($suffix)) . $suffix;
    }

    public static function validateXml(string $bytes): \DOMDocument
    {
        if (preg_match('/<!\s*(DOCTYPE|ENTITY)\b/i', $bytes)) {
            throw new \InvalidArgumentException('XML-ul nu poate conține definiții DTD sau entități externe.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = new \DOMDocument();
            $document->resolveExternals = false;
            $document->substituteEntities = false;
            if (!$document->loadXML($bytes, LIBXML_NONET | LIBXML_NOBLANKS) || $document->doctype !== null) {
                throw new \InvalidArgumentException('Fișierul XML nu este valid.');
            }
            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public static function validateWorkbook(string $path): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \InvalidArgumentException('Fișierul XLSX nu poate fi deschis.');
        }
        try {
            if ($zip->numFiles > 6000 || $zip->locateName('[Content_Types].xml') === false || $zip->locateName('xl/workbook.xml') === false) {
                throw new \InvalidArgumentException('Documentul nu este un registru XLSX valid.');
            }
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $entry = $zip->statIndex($i);
                if ($entry === false) {
                    throw new \InvalidArgumentException('Registrul XLSX este deteriorat.');
                }
                $total += $entry['size'];
                $entryName = str_replace('\\', '/', $entry['name']);
                if ($total > 80 * 1024 * 1024 || $entry['size'] > 30 * 1024 * 1024 || str_contains(strtolower($entryName), 'vbaproject') || preg_match('~(^/|^[A-Za-z]:|(^|/)\.\.(/|$))~D', $entryName)) {
                    throw new \InvalidArgumentException('Registrul XLSX conține date excesive sau conținut nepermis.');
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function directory(): string
    {
        return $this->root . '/storage/private/nir-documents';
    }
}
