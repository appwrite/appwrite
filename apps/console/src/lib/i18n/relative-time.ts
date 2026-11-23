import { getActiveLanguage } from '@/lib/i18n/active-language'
import { getIntlLocale } from '@/lib/i18n/date-format'
import type { Translator } from '@/lib/i18n/translate'
import { translate } from '@/lib/i18n/translate'

export type RelativeTimeUnit =
  | 'minute'
  | 'hour'
  | 'day'
  | 'week'
  | 'month'
  | 'year'

const UNIT_FORMS: Record<
  RelativeTimeUnit,
  { singular: string; plural: string }
> = {
  minute: { singular: 'minute', plural: 'minutes' },
  hour: { singular: 'hour', plural: 'hours' },
  day: { singular: 'day', plural: 'days' },
  week: { singular: 'week', plural: 'weeks' },
  month: { singular: 'month', plural: 'months' },
  year: { singular: 'year', plural: 'years' },
}

function getUnitLabel(
  count: number,
  unit: RelativeTimeUnit,
  t: Translator,
): string {
  const forms = UNIT_FORMS[unit]
  return t(count === 1 ? forms.singular : forms.plural)
}

function formatDurationPhrase(
  duration: string,
  isFuture: boolean,
  t: Translator,
): string {
  const language = getActiveLanguage()
  if (language === 'he') {
    const prefix = t(isFuture ? 'In relative time' : 'Ago relative time')
    return `${prefix} ${duration}`
  }
  if (language === 'ja') {
    const suffix = t(isFuture ? 'In relative time' : 'Ago relative time')
    return `${duration}${suffix}`
  }

  return isFuture ? `in ${duration}` : `${duration} ago`
}

/**
 * Formats a relative duration like "2 hours ago" or "in 3 days".
 */
export function formatRelativeDuration(
  count: number,
  unit: RelativeTimeUnit,
  options: { isFuture?: boolean; t?: Translator } = {},
): string {
  const t = options.t ?? translate
  const duration = `${count} ${getUnitLabel(count, unit, t)}`
  return formatDurationPhrase(duration, options.isFuture ?? false, t)
}

/**
 * Formats comma-separated relative breakdown like "2 days, 3 hours ago".
 */
export function formatRelativeDurationBreakdown(
  parts: Array<{ count: number; unit: RelativeTimeUnit }>,
  options: { isFuture?: boolean; t?: Translator } = {},
): string {
  if (parts.length === 0) return ''

  const t = options.t ?? translate
  const duration = parts
    .map(({ count, unit }) => `${count} ${getUnitLabel(count, unit, t)}`)
    .join(', ')

  return formatDurationPhrase(duration, options.isFuture ?? false, t)
}

function parseDate(date: string | Date): Date | null {
  const dateObj = typeof date === 'string' ? new Date(date) : date
  return Number.isNaN(dateObj.getTime()) ? null : dateObj
}

/**
 * Compact relative time like "2m ago" (English) or "לפני 2 דקות" (Hebrew).
 */
export function formatShortRelativeTime(
  date: string | Date,
  options: { t?: Translator } = {},
): string {
  const t = options.t ?? translate
  const dateObj = parseDate(date)
  if (!dateObj) return t('Unknown')

  const now = new Date()
  const diffMs = now.getTime() - dateObj.getTime()
  const diffMinutes = Math.floor(diffMs / 60000)

  if (diffMinutes < 1) return t('Just now')

  const language = getActiveLanguage()
  if (language === 'he' || language === 'ja') {
    if (diffMinutes < 60) {
      return formatRelativeDuration(diffMinutes, 'minute', { t })
    }

    const diffHours = Math.floor(diffMinutes / 60)
    if (diffHours < 24) {
      return formatRelativeDuration(diffHours, 'hour', { t })
    }

    const diffDays = Math.floor(diffHours / 24)
    if (diffDays < 7) {
      return formatRelativeDuration(diffDays, 'day', { t })
    }

    const diffWeeks = Math.floor(diffDays / 7)
    if (diffWeeks < 4) {
      return formatRelativeDuration(diffWeeks, 'week', { t })
    }

    return dateObj.toLocaleDateString(getIntlLocale(language), {
      day: 'numeric',
      month: 'short',
      year: 'numeric',
    })
  }

  if (diffMinutes < 60) {
    return `${diffMinutes}m ago`
  }

  const diffHours = Math.floor(diffMinutes / 60)
  if (diffHours < 24) {
    return `${diffHours}h ago`
  }

  const diffDays = Math.floor(diffHours / 24)
  if (diffDays < 7) {
    return `${diffDays}d ago`
  }

  const diffWeeks = Math.floor(diffDays / 7)
  if (diffWeeks < 4) {
    return `${diffWeeks}w ago`
  }

  return dateObj.toLocaleDateString(getIntlLocale('en'), {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  })
}

export function pickPrimaryRelativeUnit(diff: {
  diffYears: number
  diffMonths: number
  diffWeeks: number
  diffDays: number
  diffHours: number
  diffMinutes: number
}): { count: number; unit: RelativeTimeUnit } {
  if (diff.diffYears > 0) {
    return { count: diff.diffYears, unit: 'year' }
  }
  if (diff.diffMonths > 0) {
    return { count: diff.diffMonths, unit: 'month' }
  }
  if (diff.diffWeeks > 0) {
    return { count: diff.diffWeeks, unit: 'week' }
  }
  if (diff.diffDays > 0) {
    return { count: diff.diffDays, unit: 'day' }
  }
  if (diff.diffHours > 0) {
    return { count: diff.diffHours, unit: 'hour' }
  }
  return { count: diff.diffMinutes, unit: 'minute' }
}
