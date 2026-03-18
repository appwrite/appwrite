/**
 * Shared copy and styling for Appwrite Cloud status alerts (header banner and fullscreen loader).
 * Single source of truth so both surfaces show the same text and colors.
 */

import type { LucideIcon } from 'lucide-react'
import { AlertTriangle, Wrench } from 'lucide-react'

export type CloudStatusState =
  | 'degraded'
  | 'downtime'
  | 'maintenance'

export type CloudStatusStateWithOperational =
  | 'operational'
  | CloudStatusState

export function getStatusPresentation(state: CloudStatusState) {
  switch (state) {
    case 'downtime':
      return {
        containerClassName:
          'bg-red-500/10 text-red-600 dark:bg-red-500/20 dark:text-red-400',
        buttonClassName:
          'bg-red-500 text-red-950 hover:bg-red-400 dark:bg-red-500 dark:text-red-950 dark:hover:bg-red-400',
        title: 'We’re currently experiencing an outage. Services may be unavailable.',
      }
    case 'maintenance':
      return {
        containerClassName:
          'bg-blue-500/10 text-blue-600 dark:bg-blue-500/20 dark:text-blue-400',
        buttonClassName:
          'bg-blue-500 text-blue-950 hover:bg-blue-400 dark:bg-blue-500 dark:text-blue-950 dark:hover:bg-blue-400',
        title: 'Scheduled maintenance is in progress. Some features may be temporarily limited.',
      }
    default:
      return {
        containerClassName:
          'bg-amber-500/10 text-amber-600 dark:bg-amber-500/20 dark:text-amber-400',
        buttonClassName:
          'bg-amber-500 text-amber-950 hover:bg-amber-400 dark:bg-amber-500 dark:text-amber-950 dark:hover:bg-amber-400',
        title: 'We’re experiencing issues with some services. Performance may be affected.',
      }
  }
}

export function getStatusIcon(
  state: CloudStatusState,
): LucideIcon {
  return state === 'maintenance' ? Wrench : AlertTriangle
}

export function getMockReportTitle(
  state: CloudStatusStateWithOperational,
): string | undefined {
  switch (state) {
    case 'downtime':
      return 'Major service disruption affecting Appwrite Cloud.'
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

export interface StatusBannerCopyOptions {
  reportTitle?: string | null
  startsAt?: string | null
  endsAt?: string | null
}

/**
 * Returns the full status banner copy (title + report title + maintenance window)
 * so the header banner and fullscreen loader show the same text.
 */
export function getStatusBannerCopy(
  state: CloudStatusState,
  options?: StatusBannerCopyOptions,
): string {
  const { title, reportTitle, maintenanceWindow } = getStatusBannerParts(
    state,
    options,
  )
  const parts = [title, reportTitle, maintenanceWindow].filter(Boolean)
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
} {
  const presentation = getStatusPresentation(state)
  const reportTitle = options?.reportTitle?.trim() || undefined
  const maintenanceWindow =
    state === 'maintenance' && (options?.startsAt ?? options?.endsAt)
      ? formatLocalMaintenanceWindow(options.startsAt, options.endsAt)
      : undefined
  return {
    title: presentation.title,
    reportTitle,
    maintenanceWindow,
  }
}
