/**
 * Centralized date formatting utilities for consistent date display across the app
 */

/**
 * Formats a date for display in a consistent format: "11 Dec 2025"
 * Handles various input formats (ISO string, Unix timestamp in seconds or milliseconds, Date object)
 */
export function formatDate(
  date: string | number | Date | null | undefined,
): string {
  if (!date) return 'N/A'

  let dateObj: Date

  if (date instanceof Date) {
    dateObj = date
  } else if (typeof date === 'number') {
    // Handle both seconds (Unix timestamp) and milliseconds
    // If the number is less than 1e12, assume it's in seconds, otherwise milliseconds
    dateObj = new Date(date < 1e12 ? date * 1000 : date)
  } else if (typeof date === 'string') {
    dateObj = new Date(date)
  } else {
    return 'N/A'
  }

  // Check if date is valid
  if (isNaN(dateObj.getTime())) {
    return 'N/A'
  }

  return dateObj.toLocaleDateString('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  })
}

/**
 * Formats a date for display with month and year only: "Dec 2025"
 */
export function formatDateMonthYear(
  date: string | number | Date | null | undefined,
): string {
  if (!date) return 'N/A'

  let dateObj: Date

  if (date instanceof Date) {
    dateObj = date
  } else if (typeof date === 'number') {
    dateObj = new Date(date < 1e12 ? date * 1000 : date)
  } else if (typeof date === 'string') {
    dateObj = new Date(date)
  } else {
    return 'N/A'
  }

  if (isNaN(dateObj.getTime())) {
    return 'N/A'
  }

  return dateObj.toLocaleDateString('en-GB', {
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
  if (!date) return 'N/A'

  let dateObj: Date

  if (date instanceof Date) {
    dateObj = date
  } else if (typeof date === 'number') {
    dateObj = new Date(date < 1e12 ? date * 1000 : date)
  } else if (typeof date === 'string') {
    dateObj = new Date(date)
  } else {
    return 'N/A'
  }

  if (isNaN(dateObj.getTime())) {
    return 'N/A'
  }

  return dateObj.toLocaleString('en-GB', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  })
}
