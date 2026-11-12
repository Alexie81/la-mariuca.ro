<?php

declare(strict_types=1);

namespace App\Modules\Inventory;

use Psr\Http\Message\UploadedFileInterface;

/** Private original documents. Access is always mediated by the authorized NIR controller. */
final class NirDocumentStorage
{
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
        if (in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new \InvalidArgumentException('Serverul a oprit încărcarea fișierului. Verifică limita de încărcare din configurația găzduirii.');
        }
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('Documentul nu a fost încărcat complet. Alege fișierul și încearcă din nou.');
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
        if (!is_dir($this->directory()) && !mkdir($this->directory(), 0770, true) && !is_dir($this->directory())) {
            throw new \RuntimeException('Documentele nu pot fi salvate momentan.');
        }
        $storedName = bin2hex(random_bytes(20)) . '.' . $extension;
        $path = $this->directory() . '/' . $storedName;
        $output = @fopen($path, 'xb');
        if ($output === false) {
            throw new \RuntimeException('Documentul nu poate fi salvat momentan.');
        }
        $size = 0;
        $hash = hash_init('sha256');
        try {
            // Copy to private storage in chunks, without loading large originals into memory.
            try {
                while (true) {
                    $part = $stream->read(65536);
                    if ($part === '') {
                        if (!$stream->eof()) {
                            throw new \RuntimeException('Documentul nu a putut fi citit integral.');
                        }
                        break;
                    }
                    if (fwrite($output, $part) !== strlen($part)) {
                        throw new \RuntimeException('Documentul nu a putut fi salvat integral.');
                    }
                    $size += strlen($part);
                    hash_update($hash, $part);
                }
            } finally {
                fclose($output);
            }
            if ($size === 0 || ($file->getSize() !== null && $size !== $file->getSize())) {
                throw new \InvalidArgumentException('Documentul este gol sau încărcarea este incompletă. Încearcă din nou.');
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
            if (!in_array($mime, self::MIME_TYPES[$extension], true)) {
                throw new \InvalidArgumentException('Conținutul fișierului nu corespunde extensiei. Alege documentul original.');
            }
            if (str_starts_with((string) $mime, 'image/')) {
                $dimensions = @getimagesize($path);
                if ($dimensions === false || $dimensions[0] * $dimensions[1] > 40_000_000 || $dimensions[0] < 1 || $dimensions[1] < 1) {
                    throw new \InvalidArgumentException('Imaginea este invalidă sau depășește 40 megapixeli.');
                }
            }
            if ($extension === 'xml') {
                self::validateXmlFile($path);
                $mime = 'application/xml';
            }
            if ($extension === 'xlsx') {
                self::validateWorkbook($path, false);
                $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
            }
            @chmod($path, 0660);
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        }
        return [
            'stored_name' => $storedName, 'original_name' => $name, 'mime_type' => $mime,
            'extension' => $extension, 'size_bytes' => $size, 'sha256' => hash_final($hash),
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

    private static function validateXmlFile(string $path): void
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = new \XMLReader();
        try {
            if (!$reader->open($path, null, LIBXML_NONET)) {
                throw new \InvalidArgumentException('Fișierul XML nu este valid.');
            }
            $reader->setParserProperty(\XMLReader::LOADDTD, false);
            $reader->setParserProperty(\XMLReader::SUBST_ENTITIES, false);
            while ($reader->read()) {
                if (in_array($reader->nodeType, [\XMLReader::DOC_TYPE, \XMLReader::ENTITY, \XMLReader::ENTITY_REF], true)) {
                    throw new \InvalidArgumentException('XML-ul nu poate conține definiții DTD sau entități externe.');
                }
            }
            if (libxml_get_errors() !== []) {
                throw new \InvalidArgumentException('Fișierul XML nu este valid.');
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    public static function validateWorkbook(string $path, bool $forImport = true): void
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
                // Expansion limits apply to parsing cells, not storing the original attachment.
                if (($forImport && ($total > 80 * 1024 * 1024 || $entry['size'] > 30 * 1024 * 1024)) || str_contains(strtolower($entryName), 'vbaproject') || preg_match('~(^/|^[A-Za-z]:|(^|/)\.\.(/|$))~D', $entryName)) {
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
