import { ExternalLink } from 'lucide-react'

import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  getMockReportTitle,
  getStatusIcon,
  getStatusPresentation,
  formatLocalMaintenanceWindow,
} from '@/lib/cloud-status-copy'
import { useAppwriteCloudStatus } from '@/lib/react-query/hooks'
import { useDebugOverrides } from '@/lib/debug-overrides'
import { cn } from '@/lib/utils'

export function CloudStatusBanner() {
  const { features } = useConsoleProfile()
  const { mockCloudStatusAlert } = useDebugOverrides()
  const { data, isSuccess } = useAppwriteCloudStatus(features.systemStatus)

  if (!features.systemStatus) {
    return null
  }

  const aggregateState =
    mockCloudStatusAlert !== 'live'
      ? mockCloudStatusAlert
      : isSuccess
        ? (data?.aggregateState ?? 'operational')
        : 'operational'

  if (aggregateState === 'operational') {
    return null
  }

  const presentation = getStatusPresentation(aggregateState)
  const Icon = getStatusIcon(aggregateState)
  const activeReportTitle =
    mockCloudStatusAlert !== 'live'
      ? getMockReportTitle(mockCloudStatusAlert)
      : data?.activeReport?.title
  const maintenanceWindow =
    aggregateState === 'maintenance'
      ? mockCloudStatusAlert !== 'live'
        ? formatLocalMaintenanceWindow(
            new Date(Date.now() + 30 * 60 * 1000).toISOString(),
            new Date(Date.now() + 2 * 60 * 60 * 1000).toISOString(),
          )
        : formatLocalMaintenanceWindow(
            data?.activeReport?.startsAt,
            data?.activeReport?.endsAt,
          )
      : undefined

  const statusUrl = 'https://status.appwrite.online'

  return (
    <a
      href={statusUrl}
      target="_blank"
      rel="noopener noreferrer"
      className={cn(
        'relative flex min-h-14 min-w-0 flex-col gap-3 px-4 py-3 transition-all duration-200 hover:opacity-95 sm:flex-row sm:items-center sm:gap-4',
        presentation.containerClassName,
      )}
    >
      <div className="flex min-w-0 flex-1 items-start gap-3 sm:items-center">
        <Icon className="mt-0.5 h-4 w-4 shrink-0 sm:mt-0" />
        <p className="text-[13px] font-medium leading-snug">
          {presentation.title}
          {activeReportTitle ? (
            <>
              {' '}
              <span className="text-foreground">{activeReportTitle}</span>
            </>
          ) : null}
          {maintenanceWindow ? (
            <>
              {' '}
              <span className="text-foreground/70">{maintenanceWindow}</span>
            </>
          ) : null}
        </p>
      </div>
      <span
        className={cn(
          'flex h-8 w-fit shrink-0 items-center gap-2 rounded-md px-3 text-[13px] font-medium sm:ml-auto',
          presentation.buttonClassName,
        )}
      >
        <span className="hidden sm:inline">View Status</span>
        <span className="sm:hidden">Status</span>
        <ExternalLink className="h-3.5 w-3.5" />
      </span>
    </a>
  )
}
