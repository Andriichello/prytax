<?php

namespace PryTax\Console\Starter;

use Carbon\CarbonImmutable;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class SummaryTable.
 *
 * An installation's overview (`InstallationSummary`), as `prytax:summary` and the starters print it (builds.md §6):
 * its database, then its organizations with their ФОПs under them, the documents and the backup archives each keeps,
 * and its latest backup. Without intl, which Docker's image lacks, so sizes are formatted here.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Console\Starter
 */
final class SummaryTable
{
    /**
     * Print the summary.
     *
     * @param array{
     *     database: array{driver: string, name: string, bytes: int|null, created_at: string|null},
     *     organizations: list<array{name: string, users: int, documents: array{count: int, bytes: int}, backups: array{count: int, bytes: int}, latest_backup_at: string|null, profiles: list<array{name: string, closed: bool, documents: array{count: int, bytes: int}, backups: array{count: int, bytes: int}, latest_backup_at: string|null}>}>,
     *     totals: array{organizations: int, profiles: int, documents: array{count: int, bytes: int}, backups: array{count: int, bytes: int}, latest_backup_at: string|null},
     * } $summary
     * @param OutputInterface $output
     *
     * @return void
     */
    public static function render(array $summary, OutputInterface $output): void
    {
        ['database' => $database, 'organizations' => $organizations, 'totals' => $totals] = $summary;

        $output->writeln('  ' . implode(' · ', array_filter([
            match ($database['driver']) {
                'sqlite' => 'SQLite',
                'mysql' => 'MySQL',
                'mariadb' => 'MariaDB',
                default => $database['driver'],
            } . " {$database['name']}",
            $database['bytes'] === null ? null : self::size($database['bytes']),
            $database['created_at'] === null ? null : 'created ' . self::day($database['created_at']),
        ])));

        if ($organizations === []) {
            $output->writeln('  <fg=gray>No organization yet.</>');

            return;
        }

        $rows = [];

        foreach ($organizations as $organization) {
            $users = $organization['users'] === 1 ? '1 user' : "{$organization['users']} users";
            $rows[] = ["<options=bold>{$organization['name']}</> ($users)", ...self::cells($organization)];

            foreach ($organization['profiles'] as $profile) {
                $rows[] = ['  ' . $profile['name'] . ($profile['closed'] ? ' <fg=gray>(closed)</>' : ''), ...self::cells($profile)];
            }

            if ($organization['profiles'] === []) {
                $rows[] = ['  <fg=gray>No ФОП yet</>', '', '', ''];
            }
        }

        if ($totals['organizations'] > 1) {
            $rows[] = new TableSeparator;
            $rows[] = ["Total: {$totals['organizations']} organizations, {$totals['profiles']} ФОП", ...self::cells($totals)];
        }

        (new Table($output))
            ->setHeaders(['Organization · ФОП', 'Documents', 'Backups', 'Latest backup'])
            ->setRows($rows)
            ->setStyle('box')
            ->render();
    }

    /**
     * Get the cells of an organization, a ФОП or the totals: documents, backups, latest backup.
     *
     * @param array{documents: array{count: int, bytes: int}, backups: array{count: int, bytes: int}, latest_backup_at: string|null} $item
     *
     * @return list<string>
     */
    protected static function cells(array $item): array
    {
        return [
            self::usage($item['documents']),
            self::usage($item['backups']),
            $item['latest_backup_at'] === null ? '<fg=gray>none</>' : CarbonImmutable::parse($item['latest_backup_at'])->format('d.m.Y H:i'),
        ];
    }

    /**
     * Get a usage as its size and count, such as `3.1 MB (12)`, or a dash without files.
     *
     * @param array{count: int, bytes: int} $usage
     *
     * @return string
     */
    protected static function usage(array $usage): string
    {
        return $usage['count'] === 0 ? '<fg=gray>—</>' : self::size($usage['bytes']) . " ({$usage['count']})";
    }

    /**
     * Get a size in bytes for a person, such as `512 B` or `3.1 MB`.
     *
     * @param int $bytes
     *
     * @return string
     */
    public static function size(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = (float) $bytes;
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return $unit === 0 ? "$bytes B" : sprintf('%.1f %s', $size, $units[$unit]);
    }

    /**
     * Get an ISO 8601 date as its day, such as `31.10.2026`.
     *
     * @param string $date
     *
     * @return string
     */
    public static function day(string $date): string
    {
        return CarbonImmutable::parse($date)->format('d.m.Y');
    }
}
