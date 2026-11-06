/**
 * Shared copy and styling for Appwrite Cloud status alerts (header banner and fullscreen loader).
 * Single source of truth so both surfaces show the same text and colors.
 */

import type { LucideIcon } from 'lucide-react'
import { AlertTriangle, Wrench } from 'lucide-react'

export type CloudStatusState = 'degraded' | 'downtime' | 'maintenance'

export type CloudStatusStateWithOperational = 'operational' | CloudStatusState

export function getStatusPresentation(state: CloudStatusState) {
  switch (state) {
    case 'downtime':
      return {
        containerClassName:
          'bg-red-500/10 text-red-600 dark:bg-red-500/20 dark:text-red-400 border-b border-red-600/12 dark:border-red-400/18',
        buttonClassName:
          'border border-red-500 bg-transparent text-red-600 hover:bg-red-500/10 hover:text-red-700 dark:border-red-400 dark:text-red-400 dark:hover:bg-red-500/20 dark:hover:text-red-300',
        title: 'Some Appwrite Cloud services are temporarily unavailable.',
      }
    case 'maintenance':
      return {
        containerClassName:
          'bg-blue-500/10 text-blue-600 dark:bg-blue-500/20 dark:text-blue-400 border-b border-blue-600/12 dark:border-blue-400/18',
        buttonClassName:
          'border border-blue-500 bg-transparent text-blue-600 hover:bg-blue-500/10 hover:text-blue-700 dark:border-blue-400 dark:text-blue-400 dark:hover:bg-blue-500/20 dark:hover:text-blue-300',
        title: 'Scheduled maintenance is in progress.',
      }
    default:
      return {
        containerClassName:
          'bg-amber-500/10 text-amber-600 dark:bg-amber-500/20 dark:text-amber-400 border-b border-amber-700/14 dark:border-amber-400/22',
        buttonClassName:
          'border border-amber-500 bg-transparent text-amber-600 hover:bg-amber-500/10 hover:text-amber-700 dark:border-amber-400 dark:text-amber-400 dark:hover:bg-amber-500/20 dark:hover:text-amber-300',
        title: 'We’re experiencing issues with some services.',
      }
  }
}

export function getStatusIcon(state: CloudStatusState): LucideIcon {
  return state === 'maintenance' ? Wrench : AlertTriangle
}

export function getMockReportTitle(
  state: CloudStatusStateWithOperational,
): string | undefined {
  switch (state) {
    case 'downtime':
      return 'You may have trouble accessing some services. We’re working to restore full access.'
    case 'maintenance':
      return 'Planned maintenance window in progress.'
    case 'operational':
      return undefined
    default:
      return 'A subset of Appwrite Cloud services is degraded.'
  }
}

export function formatLocalMaintenanceWindow(
  startsAt?: string | null,
  endsAt?: string | null,
): string | undefined {
  if (!startsAt) {
    return undefined
  }

  const startDate = new Date(startsAt)
  if (Number.isNaN(startDate.getTime())) {
    return undefined
  }

  const endDate = endsAt ? new Date(endsAt) : undefined
  const hasValidEndDate =
    endDate !== undefined && !Number.isNaN(endDate.getTime())

  const dateFormatter = new Intl.DateTimeFormat(undefined, {
    month: 'short',
    day: 'numeric',
  })
  const timeFormatter = new Intl.DateTimeFormat(undefined, {
    hour: 'numeric',
    minute: '2-digit',
  })

  if (!hasValidEndDate) {
    return `Starts ${dateFormatter.format(startDate)} at ${timeFormatter.format(startDate)} local time.`
  }

  const isSameDay =
    startDate.getFullYear() === endDate.getFullYear() &&
    startDate.getMonth() === endDate.getMonth() &&
    startDate.getDate() === endDate.getDate()

  if (isSameDay) {
    return `${dateFormatter.format(startDate)}, ${timeFormatter.format(startDate)} - ${timeFormatter.format(endDate)} local time.`
  }

  return `${dateFormatter.format(startDate)}, ${timeFormatter.format(startDate)} - ${dateFormatter.format(endDate)}, ${timeFormatter.format(endDate)} local time.`
}

/**
 * Human-readable scope line for Cloud status alerts (banner + fullscreen loader).
 */
export function formatStatusAffectedRegionsLine(
  allRegionsAffected: boolean,
  regionCodes: string[],
): string | undefined {
  if (allRegionsAffected) {
    return 'All regions are affected.'
  }
  const codes = regionCodes.filter(Boolean)
  if (codes.length === 0) {
    return undefined
  }
  if (codes.length === 1) {
    return `Affected region: ${codes[0]}.`
  }
  return `Affected regions: ${codes.join(', ')}.`
}

export interface StatusBannerCopyOptions {
  reportTitle?: string | null
  startsAt?: string | null
  endsAt?: string | null
  /** Optional line after the report title (e.g. affected Cloud regions). */
  regionsLine?: string | null
}

/**
 * Returns the full status banner copy (title + report title + maintenance window)
 * so the header banner and fullscreen loader show the same text.
 */
export function getStatusBannerCopy(
  state: CloudStatusState,
  options?: StatusBannerCopyOptions,
): string {
  const { title, reportTitle, maintenanceWindow, regionsLine } =
    getStatusBannerParts(state, options)
  const parts = [title, reportTitle, maintenanceWindow, regionsLine].filter(
    Boolean,
  )
  return parts.join(' ')
}

/**
 * Returns the status banner content in parts so the same text styles can be
 * applied as the header banner (title inherits container color, report in
 * text-foreground, maintenance in text-foreground/70).
 */
export function getStatusBannerParts(
  state: CloudStatusState,
  options?: StatusBannerCopyOptions,
): {
  title: string
  reportTitle?: string
  maintenanceWindow?: string
  regionsLine?: string
} {
  const presentation = getStatusPresentation(state)
  const reportTitle = options?.reportTitle?.trim() || undefined
  const maintenanceWindow =
    state === 'maintenance' && (options?.startsAt ?? options?.endsAt)
      ? formatLocalMaintenanceWindow(options.startsAt, options.endsAt)
      : undefined
  const regionsLine = options?.regionsLine?.trim() || undefined
  return {
    title: presentation.title,
    reportTitle,
    maintenanceWindow,
    regionsLine,
  }
}
