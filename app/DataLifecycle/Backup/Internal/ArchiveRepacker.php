<?php

namespace PryTax\DataLifecycle\Backup\Internal;

use RuntimeException;
use ZipArchive;

/**
 * Class ArchiveRepacker.
 *
 * Packs a stored archive again with every entry encrypted with AES-256 by a password, for one download (backups.md
 * §4.6). Stored archives aren't encrypted: a scheduled run would have to keep the password.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Backup\Internal
 */
final class ArchiveRepacker
{
    /**
     * Pack the local archive again with the password, into a new local file, and get its path.
     *
     * @param string $source
     * @param string $password
     *
     * @return string
     */
    public function encrypt(string $source, string $password): string
    {
        $from = $this->open($source, ZipArchive::RDONLY);
        $path = (string) tempnam(sys_get_temp_dir(), 'pbe');
        $to = $this->open($path, ZipArchive::OVERWRITE);

        for ($i = 0; $i < $from->numFiles; $i++) {
            $name = (string) $from->getNameIndex($i);
            $stat = $from->statIndex($i);

            $to->addFromString($name, (string) $from->getFromIndex($i));
            $to->setCompressionName($name, $stat['comp_method'] === ZipArchive::CM_STORE ? ZipArchive::CM_STORE : ZipArchive::CM_DEFLATE);
            $to->setEncryptionName($name, ZipArchive::EM_AES_256, $password);
        }

        $from->close();

        if (!$to->close()) {
            throw new RuntimeException("The archive couldn't be encrypted into $path.");
        }

        return $path;
    }

    /**
     * Open a zip.
     *
     * @param string $path
     * @param int $flags
     *
     * @return ZipArchive
     */
    private function open(string $path, int $flags): ZipArchive
    {
        $zip = new ZipArchive;
        $result = $zip->open($path, $flags);

        if ($result !== true) {
            throw new RuntimeException("The archive $path couldn't be opened ($result).");
        }

        return $zip;
    }
}
