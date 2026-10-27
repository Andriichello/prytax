<?php

namespace PryTax\DataLifecycle\Backup\Internal;

use DateTimeInterface;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Class ArchiveStore.
 *
 * The archives' disk (backups.md §3): a private bucket in production, any disk elsewhere. An upload is streamed and
 * verified by the disk's checksum against the local file; a download is a short-lived signed URL, which saves the
 * file under its name.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Internal
 */
final class ArchiveStore
{
    /**
     * Get name of the archives' disk.
     *
     * @return string
     */
    public function diskName(): string
    {
        return (string) config('data-lifecycle.backups.disk');
    }

    /**
     * Upload a local file to the archives' disk, and verify it.
     *
     * @param string $local
     * @param string $path
     *
     * @return void
     * @throws RuntimeException When the uploaded object differs from the local file.
     */
    public function put(string $local, string $path): void
    {
        $disk = $this->disk($this->diskName());
        $stream = fopen($local, 'r');

        try {
            $disk->writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($disk->checksum($path) !== md5_file($local)) {
            $disk->delete($path);

            throw new RuntimeException("The upload of $path doesn't match its file.");
        }
    }

    /**
     * Upload contents to the archives' disk.
     *
     * @param string $path
     * @param string $contents
     *
     * @return void
     */
    public function putContents(string $path, string $contents): void
    {
        $this->disk($this->diskName())->put($path, $contents);
    }

    /**
     * Download an object to a local temporary file, and get its path.
     *
     * @param string $disk
     * @param string $path
     *
     * @return string
     * @throws RuntimeException When the object is missing.
     */
    public function fetch(string $disk, string $path): string
    {
        $stream = $this->disk($disk)->readStream($path);

        if (!is_resource($stream)) {
            throw new RuntimeException("The archive $path is missing from its disk.");
        }

        $local = (string) tempnam(sys_get_temp_dir(), 'pbk');
        $target = fopen($local, 'w');
        stream_copy_to_stream($stream, $target);
        fclose($target);
        fclose($stream);

        return $local;
    }

    /**
     * Get a signed URL, which downloads the object under the name until the moment.
     *
     * @param string $disk
     * @param string $path
     * @param string $name
     * @param DateTimeInterface $expiresAt
     *
     * @return string
     */
    public function url(string $disk, string $path, string $name, DateTimeInterface $expiresAt): string
    {
        return $this->disk($disk)->temporaryUrl($path, $expiresAt, [
            'ResponseContentType' => 'application/zip',
            'ResponseContentDisposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, $name),
        ]);
    }

    /**
     * Delete an object; a missing one counts as deleted.
     *
     * @param string $disk
     * @param string $path
     *
     * @return void
     */
    public function delete(string $disk, string $path): void
    {
        $this->disk($disk)->delete($path);
    }

    /**
     * Get the temporary objects of downloads (`tmp/downloads/`), with when each was last modified.
     *
     * @return array<string, int>
     */
    public function temporaryObjects(): array
    {
        $disk = $this->disk($this->diskName());
        $objects = [];

        foreach ($disk->allFiles('tmp/downloads') as $path) {
            $objects[$path] = $disk->lastModified($path);
        }

        return $objects;
    }

    /**
     * Get a disk.
     *
     * @param string $name
     *
     * @return FilesystemAdapter
     */
    private function disk(string $name): FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($name);

        return $disk;
    }
}
