<?php

namespace App\Domain\Attachments;

use App\Domain\CustomFields\CustomizedContext;
use App\Domain\DomainException;
use App\Models\Attachment;
use App\Models\Journal;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\FilesystemAdapter;
use Symfony\Component\Mime\MimeTypes;

/**
 * Writes an `attachments` row and its bytes on the local `attachments` disk.
 *
 * `disk_directory` is `YYYY/MM`. `digest` is SHA-256 hex. `disk_filename` is a
 * timestamp plus a safe token. An unbound row can later be bound to the
 * customized record that stores its id.
 */
final class AttachmentService
{
    public const DISK = 'attachments';

    public function __construct(
        private readonly FilesystemFactory $filesystems,
        private readonly CustomizedContext $context,
        private readonly AttachmentRules $rules,
    ) {}

    /**
     * Store bytes and insert an unbound row, or a row already naming `$container`.
     */
    public function store(
        User $author,
        string $filename,
        string $contents,
        ?string $contentType = null,
        ?string $description = null,
        ?Model $container = null,
    ): Attachment {
        $authorId = $author->getKey();
        if (! is_numeric($authorId)) {
            throw new DomainException('Save the author before storing an attachment.');
        }

        $original = $this->originalFilename($filename);
        $this->rules->assertAccepted($original, strlen($contents));
        $storedDescription = $this->description($description);
        $storedType = $this->contentType($contentType, $original);
        $containerType = null;
        $containerId = null;
        if ($container !== null) {
            [$containerType, $containerId] = $this->containerOf($container);
        }

        $createdOn = now();
        $directory = $createdOn->format('Y/m');
        $diskFilename = $this->claimDiskFile($directory, $this->diskToken($original), $contents);

        $attachment = new Attachment([
            'author_id' => (int) $authorId,
            'container_id' => $containerId,
            'container_type' => $containerType,
            'content_type' => $storedType,
            'created_on' => $createdOn,
            'description' => $storedDescription,
            'digest' => hash('sha256', $contents),
            'disk_directory' => $directory,
            'disk_filename' => $diskFilename,
            'downloads' => 0,
            'filename' => $original,
            'filesize' => strlen($contents),
        ]);
        $attachment->save();

        return $attachment->refresh();
    }

    /**
     * Point an unbound row at `$record`. A row already on that record is left as-is.
     *
     * Issue, Project, Version, TimeEntry, and User use the custom-field type
     * name. A journal uses `Journal`. That row is not a custom value.
     */
    /**
     * Replace the display name or description of a stored row.
     *
     * A new filename is checked against the size and extension settings.
     * The bytes on disk stay under the original disk name.
     */
    public function retitle(Attachment $attachment, ?string $filename, ?string $description): void
    {
        if ($filename !== null && $filename !== '') {
            $original = $this->originalFilename($filename);
            $this->rules->assertAccepted($original, (int) $attachment->filesize);
            $attachment->filename = $original;
            $attachment->content_type = $this->contentType(null, $original);
        }
        if ($description !== null) {
            $attachment->description = $this->description($description);
        }
        $attachment->save();
    }

    public function bind(Attachment $attachment, Model $record): void
    {
        [$type, $id] = $this->containerOf($record);
        if ($this->isUnbound($attachment)) {
            $attachment->container_type = $type;
            $attachment->container_id = $id;
            $attachment->save();

            return;
        }

        if ((string) $attachment->container_type === $type && (int) $attachment->container_id === $id) {
            return;
        }

        throw new DomainException('Attachment is not attached to this record.');
    }

    /**
     * @param  list<string>  $ids
     */
    public function bindStoredIds(Model $record, array $ids): void
    {
        foreach ($ids as $id) {
            if (preg_match('/^[1-9]\d*$/', $id) !== 1) {
                throw new DomainException('Value must be an attachment id.');
            }
            $attachment = Attachment::query()->find((int) $id);
            if (! $attachment instanceof Attachment) {
                throw new DomainException('Attachment does not exist.');
            }
            $this->bind($attachment, $record);
        }
    }

    /**
     * Remove the bytes for a row that is about to be, or already was, deleted.
     *
     * A missing file is left alone. The attachment row is not deleted here.
     */
    public function forgetFile(Attachment $attachment): void
    {
        try {
            $path = $this->absolutePath($attachment);
        } catch (DomainException) {
            return;
        }
        if (! is_file($path)) {
            return;
        }
        if (! unlink($path)) {
            throw new DomainException('Attachment file could not be removed.');
        }
    }

    public function diskPath(string $relative): string
    {
        if ($relative === '' || str_contains($relative, '..') || str_starts_with($relative, '/')) {
            throw new DomainException('Attachment file is not stored.');
        }

        return $this->adapter()->path($relative);
    }

    public function absolutePath(Attachment $attachment): string
    {
        $directory = $attachment->getAttribute('disk_directory');
        $name = $attachment->getAttribute('disk_filename');
        if (! is_string($directory) || ! is_string($name)) {
            throw new DomainException('Attachment file is not stored.');
        }
        if (preg_match('/^\d{4}\/\d{2}$/', $directory) !== 1) {
            throw new DomainException('Attachment file is not stored.');
        }
        if ($name === '' || str_contains($name, '/') || str_contains($name, '\\') || str_contains($name, '..')) {
            throw new DomainException('Attachment file is not stored.');
        }

        return $this->adapter()->path($directory.'/'.$name);
    }

    private function claimDiskFile(string $directory, string $token, string $contents): string
    {
        $adapter = $this->adapter();
        $timestamp = now()->format('ymdHis');
        $directoryPath = $adapter->path($directory);
        if (! is_dir($directoryPath) && ! mkdir($directoryPath, 0775, true) && ! is_dir($directoryPath)) {
            throw new DomainException('Attachment directory could not be created.');
        }

        for ($attempt = 0; $attempt < 100; $attempt++) {
            $diskFilename = $timestamp.'_'.$token;
            $absolute = $adapter->path($directory.'/'.$diskFilename);
            if (is_file($absolute)) {
                $timestamp = $this->successor($timestamp);

                continue;
            }

            $handle = fopen($absolute, 'xb');
            if ($handle === false) {
                $timestamp = $this->successor($timestamp);

                continue;
            }

            $written = fwrite($handle, $contents);
            fclose($handle);
            if ($written === false || $written !== strlen($contents)) {
                if (is_file($absolute)) {
                    unlink($absolute);
                }
                throw new DomainException('Attachment file could not be written.');
            }

            return $diskFilename;
        }

        throw new DomainException('Attachment file name is already taken.');
    }

    private function diskToken(string $filename): string
    {
        if (preg_match('/^[A-Za-z0-9_.-]+$/', $filename) === 1 && strlen($filename) <= 50) {
            return $filename;
        }

        $hash = hash('sha256', $filename);
        if (preg_match('/(\.[A-Za-z0-9]+)$/', $filename, $match) === 1) {
            $extension = $match[1];
            if (strlen($hash) + strlen($extension) <= 50) {
                return $hash.$extension;
            }
        }

        return $hash;
    }

    private function originalFilename(string $filename): string
    {
        $normalized = str_replace('\\', '/', trim($filename));
        $base = basename($normalized);
        $base = trim($base);
        if ($base === '' || $base === '.' || $base === '..') {
            throw new DomainException('Filename is not valid.');
        }
        if (mb_strlen($base) > 255) {
            throw new DomainException('Filename is too long.');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $base) === 1) {
            throw new DomainException('Filename is not valid.');
        }

        return $base;
    }

    private function description(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }
        if (strlen($description) > 255) {
            throw new DomainException('Description is too long.');
        }
        if ($description === '') {
            return null;
        }

        return $description;
    }

    private function contentType(?string $given, string $filename): ?string
    {
        $candidate = $given;
        if ($candidate === null || trim($candidate) === '') {
            $extension = $this->extension($filename);
            if ($extension === '') {
                return null;
            }
            $guessed = MimeTypes::getDefault()->getMimeTypes($extension);
            $candidate = $guessed[0] ?? null;
        }
        if (! is_string($candidate)) {
            return null;
        }
        $candidate = trim($candidate);
        if ($candidate === '' || strlen($candidate) > 255) {
            return null;
        }

        return $candidate;
    }

    private function extension(string $filename): string
    {
        $dot = strrpos($filename, '.');
        if ($dot === false || $dot === strlen($filename) - 1) {
            return '';
        }

        return strtolower(substr($filename, $dot + 1));
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function containerOf(Model $record): array
    {
        if ($record instanceof Journal) {
            $journalId = $record->getKey();
            if (! is_numeric($journalId)) {
                throw new DomainException('This record cannot own an attachment.');
            }

            return ['Journal', (int) $journalId];
        }

        $type = $this->context->customizedType($record);
        $id = $record->getKey();
        if ($type === null || ! is_numeric($id)) {
            throw new DomainException('This record cannot own an attachment.');
        }

        return [$type, (int) $id];
    }

    private function isUnbound(Attachment $attachment): bool
    {
        $type = $attachment->container_type;
        $typeBlank = $type === null || $type === '';

        return $typeBlank && $attachment->container_id === null;
    }

    private function successor(string $timestamp): string
    {
        $chars = str_split($timestamp);
        for ($index = count($chars) - 1; $index >= 0; $index--) {
            if ($chars[$index] !== '9') {
                $chars[$index] = chr(ord($chars[$index]) + 1);

                return implode('', $chars);
            }
            $chars[$index] = '0';
        }

        return '1'.implode('', $chars);
    }

    private function adapter(): FilesystemAdapter
    {
        $disk = $this->filesystems->disk(self::DISK);
        if (! $disk instanceof FilesystemAdapter) {
            throw new DomainException('Attachment storage must be a local disk.');
        }

        return $disk;
    }
}
