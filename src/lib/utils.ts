import { clsx, type ClassValue } from 'clsx'
import { twMerge } from 'tailwind-merge'

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

const ELLIPSIS = '...'

/**
 * Truncate a string in the middle when it exceeds maxLength.
 * e.g. truncateMiddle('Very Long Organization Name', 18) → 'Very Lo...on Name'
 */
export function truncateMiddle(str: string, maxLength: number): string {
  if (typeof str !== 'string' || str.length <= maxLength) return str
  const take = maxLength - ELLIPSIS.length
  const half = Math.floor(take / 2)
  const first = str.slice(0, half)
  const last = str.slice(-(take - half))
  return `${first}${ELLIPSIS}${last}`
}
