<?php

namespace PryTax\DataLifecycle\Backup\Internal;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Class ArchiveUploads.
 *
 * Archives uploaded in pieces, to be imported (backups.md §5.1): a server takes small requests (PHP's upload limit,
 * a proxy's, Cloud Run's 32 MB), and its instances share no disk, so each piece is an object of the archives' disk of
 * its own, `tmp/uploads/<organization>/<upload>/<offset>.part`, under the organization, which started it. The import
 * joins them into a local file; the pruner deletes what's left after a day (`tmp/`).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Internal
 */
final class ArchiveUploads
{
    /**
     * The bytes of an archive at most.
     *
     * @var int
     */
    public const int MAX_BYTES = 200 * 1024 * 1024;

    /**
     * The bytes of a piece at most, well under a proxy's request limit.
     *
     * @var int
     */
    public const int MAX_PIECE = 8 * 1024 * 1024;

    /**
     * ArchiveUploads constructor.
     *
     * @param ArchiveStore $store
     */
    public function __construct(private ArchiveStore $store) {}

    /**
     * Get the bytes of a piece: as many as PHP here takes in an upload, less room for the request's other fields, at
     * most `MAX_PIECE`.
     *
     * @return int
     */
    public function pieceSize(): int
    {
        return (int) max(256 * 1024, min(self::MAX_PIECE, UploadedFile::getMaxFilesize() - 64 * 1024));
    }

    /**
     * Start an upload, and get its id.
     *
     * @return string
     */
    public function start(): string
    {
        return Str::random(32);
    }

    /**
     * Get the bytes of an upload so far: where its last piece ends.
     *
     * @param int $organizationId
     * @param string $upload
     *
     * @return int
     */
    public function size(int $organizationId, string $upload): int
    {
        $pieces = $this->pieces($organizationId, $upload);
        $last = array_key_last($pieces);

        return $last === null ? 0 : $last + $this->disk()->size($pieces[$last]);
    }

    /**
     * Store a piece of an upload, which starts at the offset, and get the upload's bytes with it.
     *
     * @param int $organizationId
     * @param string $upload
     * @param int $offset
     * @param string $local The piece's local file.
     *
     * @return int
     */
    public function append(int $organizationId, string $upload, int $offset, string $local): int
    {
        $this->store->put($local, $this->folder($organizationId, $upload) . '/' . str_pad((string) $offset, 12, '0', STR_PAD_LEFT) . '.part');

        return $offset + (int) filesize($local);
    }

    /**
     * Join the pieces of an upload into a local file, and get its path, or null, when there are none, or one is
     * missing between them.
     *
     * @param int $organizationId
     * @param string $upload
     *
     * @return string|null
     */
    public function assemble(int $organizationId, string $upload): ?string
    {
        $pieces = $this->pieces($organizationId, $upload);

        if ($pieces === []) {
            return null;
        }

        $local = (string) tempnam(sys_get_temp_dir(), 'pbu');
        $target = fopen($local, 'w');
        $bytes = 0;

        foreach ($pieces as $offset => $path) {
            $stream = $offset === $bytes ? $this->disk()->readStream($path) : null;

            if (!is_resource($stream)) {
                fclose($target);
                unlink($local);

                return null;
            }

            $bytes += (int) stream_copy_to_stream($stream, $target);
            fclose($stream);
        }

        fclose($target);

        return $local;
    }

    /**
     * Delete the pieces of an upload.
     *
     * @param int $organizationId
     * @param string $upload
     *
     * @return void
     */
    public function discard(int $organizationId, string $upload): void
    {
        $this->disk()->deleteDirectory($this->folder($organizationId, $upload));
    }

    /**
     * Get the pieces of an upload, by their offsets, in order.
     *
     * @param int $organizationId
     * @param string $upload
     *
     * @return array<int, string>
     */
    private function pieces(int $organizationId, string $upload): array
    {
        $pieces = [];

        foreach ($this->disk()->files($this->folder($organizationId, $upload)) as $path) {
            $pieces[(int) basename($path, '.part')] = $path;
        }

        ksort($pieces);

        return $pieces;
    }

    /**
     * Get the folder of an upload's pieces.
     *
     * @param int $organizationId
     * @param string $upload
     *
     * @return string
     */
    private function folder(int $organizationId, string $upload): string
    {
        return "tmp/uploads/$organizationId/$upload";
    }

    /**
     * Get the archives' disk.
     *
     * @return FilesystemAdapter
     */
    private function disk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($this->store->diskName());

        return $disk;
    }
}
