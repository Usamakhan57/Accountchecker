/**
 * Display formatting helpers.
 *
 * All timestamps arrive from the API as UTC "Y-m-d H:i:s" strings; they are
 * rendered in the viewer's local timezone.
 */

function toDate(value: string | null | undefined): Date | null {
  if (!value) {
    return null;
  }

  // "2026-03-04 11:22:33" is not a reliable ISO string in every engine, so the
  // separator and the UTC marker are normalised first.
  const normalized = value.includes('T') ? value : `${value.replace(' ', 'T')}Z`;
  const date = new Date(normalized);

  return Number.isNaN(date.getTime()) ? null : date;
}

export function formatDateTime(value: string | null | undefined): string {
  const date = toDate(value);
  if (!date) {
    return '—';
  }

  return new Intl.DateTimeFormat(undefined, {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(date);
}

export function formatDate(value: string | null | undefined): string {
  const date = toDate(value);
  if (!date) {
    return '—';
  }

  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(date);
}

export function formatRelative(value: string | null | undefined): string {
  const date = toDate(value);
  if (!date) {
    return '—';
  }

  const seconds = Math.round((date.getTime() - Date.now()) / 1000);
  const formatter = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
  const thresholds: [number, Intl.RelativeTimeFormatUnit][] = [
    [60, 'second'],
    [3600, 'minute'],
    [86400, 'hour'],
    [604800, 'day'],
    [2629800, 'week'],
    [31557600, 'month'],
  ];

  const absolute = Math.abs(seconds);
  let divisor = 1;
  let unit: Intl.RelativeTimeFormatUnit = 'second';

  for (const [limit, candidate] of thresholds) {
    if (absolute < limit) {
      unit = candidate;
      break;
    }
    divisor = limit;
    unit = candidate;
  }

  const unitDivisors: Record<string, number> = {
    second: 1,
    minute: 60,
    hour: 3600,
    day: 86400,
    week: 604800,
    month: 2629800,
    year: 31557600,
  };

  return formatter.format(Math.round(seconds / (unitDivisors[unit] ?? divisor)), unit);
}

export function formatNumber(value: number | null | undefined): string {
  if (value === null || value === undefined || Number.isNaN(value)) {
    return '—';
  }

  return new Intl.NumberFormat().format(value);
}

/** Credits are whole units; the wallet never holds fractional credits. */
export function formatCredits(value: number | null | undefined): string {
  return `${formatNumber(value)} cr`;
}

export function formatMoney(cents: number, currency = 'USD'): string {
  return new Intl.NumberFormat(undefined, {
    style: 'currency',
    currency,
    minimumFractionDigits: cents % 100 === 0 ? 0 : 2,
  }).format(cents / 100);
}

export function formatDuration(ms: number | null | undefined): string {
  if (ms === null || ms === undefined) {
    return '—';
  }

  if (ms < 1000) {
    return `${Math.round(ms)} ms`;
  }

  return `${(ms / 1000).toFixed(ms < 10_000 ? 2 : 1)} s`;
}

export function pluralize(count: number, singular: string, plural = `${singular}s`): string {
  return count === 1 ? singular : plural;
}
