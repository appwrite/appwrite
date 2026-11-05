/**
 * Centralized date formatting utilities for consistent date display across the app
 */

import {
  formatLocalizedDateShort,
  formatLocalizedDateTime,
  getIntlLocale,
} from '@/lib/i18n/date-format'

function parseDisplayDate(
  date: string | number | Date | null | undefined,
): Date | null {
  if (!date) return null

  let dateObj: Date

  if (date instanceof Date) {
    dateObj = date
  } else if (typeof date === 'number') {
    dateObj = new Date(date < 1e12 ? date * 1000 : date)
  } else if (typeof date === 'string') {
    dateObj = new Date(date)
  } else {
    return null
  }

  if (isNaN(dateObj.getTime())) {
    return null
  }

  return dateObj
}

/**
 * Formats a date for display in a consistent format: "11 Dec 2025"
 * Handles various input formats (ISO string, Unix timestamp in seconds or milliseconds, Date object)
 */
export function formatDate(
  date: string | number | Date | null | undefined,
): string {
  const dateObj = parseDisplayDate(date)
  if (!dateObj) return 'N/A'

  return formatLocalizedDateShort(dateObj)
}

/**
 * Formats a date for display with month and year only: "Dec 2025"
 */
export function formatDateMonthYear(
  date: string | number | Date | null | undefined,
): string {
  const dateObj = parseDisplayDate(date)
  if (!dateObj) return 'N/A'

  return dateObj.toLocaleDateString(getIntlLocale(), {
    month: 'short',
    year: 'numeric',
  })
}

/**
 * Formats a date with time: "11 Dec 2025, 14:30"
 */
export function formatDateTime(
  date: string | number | Date | null | undefined,
): string {
  const dateObj = parseDisplayDate(date)
  if (!dateObj) return 'N/A'

  return formatLocalizedDateTime(dateObj, {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  })
}
