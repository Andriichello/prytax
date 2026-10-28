import type { BackupRecord, BackupRunRecord } from '@/api/types';
import { t } from '@/i18n';
import { addDays } from '@/utils/datePicker';
import { formatDate, formatDayMonth, formatDayMonthWords, kyivDate, today } from '@/utils/dates';

/**
 * What Резервні копії show of the backups (backups.md §4.8): sizes with a dot («4.2 МБ», the owner, 2026-10-28), times
 * in Kyiv, the days named relative to today, the kind of a run and how long a copy stays.
 */

/** «4.2 МБ», «0.9 МБ», «12 КБ». */
export function formatBytes(bytes: number | null | undefined): string {
    const value = bytes ?? 0;
    const megabytes = value / (1024 * 1024);

    return megabytes >= 0.1
        ? t('settings.backups.megabytes', { size: megabytes.toFixed(1) })
        : t('settings.backups.kilobytes', { size: Math.max(1, Math.round(value / 1024)) });
}

/** «02:37»: the time of a timestamp in Kyiv. */
export function kyivTime(timestamp: string | null | undefined): string {
    if (!timestamp) {
        return '';
    }

    return new Intl.DateTimeFormat('en-GB', { timeZone: 'Europe/Kyiv', hour: '2-digit', minute: '2-digit', hourCycle: 'h23' }).format(new Date(timestamp));
}

/** «Сьогодні», «Вчора», «Завтра», or «27 жовтня»: a timestamp's day in Kyiv, relative to today. */
export function relativeDay(timestamp: string | null | undefined): string {
    const day = kyivDate(timestamp);
    const now = today();

    if (day === now) {
        return t('settings.backups.today');
    }

    if (day === addDays(now, -1)) {
        return t('settings.backups.yesterday');
    }

    if (day === addDays(now, 1)) {
        return t('settings.backups.tomorrow');
    }

    return formatDayMonthWords(day);
}

/** «27.10, 02:37»: a run's moment, short. */
export function shortMoment(timestamp: string | null | undefined): string {
    return `${formatDayMonth(kyivDate(timestamp))}, ${kyivTime(timestamp)}`;
}

/** «27.10.2026». */
export function kyivFullDate(timestamp: string | null | undefined): string {
    return formatDate(kyivDate(timestamp));
}

/** The kind of a run, as its chip names it. */
export function runKindLabel(kind: BackupRunRecord['kind']): string {
    switch (kind) {
        case 'manual':
            return t('settings.backups.kindManual');
        case 'automatic':
            return t('settings.backups.kindAutomatic');
        default:
            return t('settings.backups.kindScheduled');
    }
}

/** How long a run's copies stay, from its first completed backup's estimate: «зберігається до 03.11 · остання копія тижня». */
export function keptLine(backup: BackupRecord | undefined): string | null {
    if (!backup?.keep_until) {
        return null;
    }

    const date = formatDayMonth(backup.keep_until);

    switch (backup.keep_reason) {
        case 'week':
            return t('settings.backups.keptUntilWeek', { date });
        case 'month':
            return t('settings.backups.keptUntilMonth', { date });
        default:
            return t('settings.backups.keptUntil', { date });
    }
}
