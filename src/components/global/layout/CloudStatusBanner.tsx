import { Activity, ExternalLink, Wrench } from 'lucide-react'

import { Button } from '@/components/ui/button'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useAppwriteCloudStatus } from '@/lib/react-query/hooks'
import { useDebugOverrides } from '@/lib/debug-overrides'
import { cn } from '@/lib/utils'

function getStatusPresentation(state: 'degraded' | 'downtime' | 'maintenance') {
  switch (state) {
    case 'downtime':
      return {
        containerClassName:
          'bg-red-500/10 text-red-600 dark:bg-red-500/20 dark:text-red-400',
        buttonClassName:
          'bg-red-500 text-red-950 hover:bg-red-400 dark:bg-red-500 dark:text-red-950 dark:hover:bg-red-400',
        title: 'Appwrite Cloud is experiencing an outage.',
        Icon: Activity,
      }
    case 'maintenance':
      return {
        containerClassName:
          'bg-blue-500/10 text-blue-600 dark:bg-blue-500/20 dark:text-blue-400',
        buttonClassName:
          'bg-blue-500 text-blue-950 hover:bg-blue-400 dark:bg-blue-500 dark:text-blue-950 dark:hover:bg-blue-400',
        title: 'Maintenance is in progress.',
        Icon: Wrench,
      }
    default:
      return {
        containerClassName:
          'bg-amber-500/10 text-amber-600 dark:bg-amber-500/20 dark:text-amber-400',
        buttonClassName:
          'bg-amber-500 text-amber-950 hover:bg-amber-400 dark:bg-amber-500 dark:text-amber-950 dark:hover:bg-amber-400',
        title: 'Appwrite Cloud is experiencing degraded service.',
        Icon: Activity,
      }
  }
}

function getMockReportTitle(
  state: 'operational' | 'degraded' | 'downtime' | 'maintenance',
) {
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

function formatLocalMaintenanceWindow(
  startsAt?: string | null,
  endsAt?: string | null,
) {
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

export function CloudStatusBanner() {
  const { features } = useConsoleProfile()
  const { mockCloudStatusAlert } = useDebugOverrides()
  const { data } = useAppwriteCloudStatus(features.systemStatus)

  if (!features.systemStatus) {
    return null
  }

  const aggregateState =
    mockCloudStatusAlert !== 'live'
      ? mockCloudStatusAlert
      : data?.aggregateState ?? 'operational'

  if (aggregateState === 'operational') {
    return null
  }

  const presentation = getStatusPresentation(aggregateState)
  const { Icon } = presentation
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

  return (
    <div
      className={cn(
        'relative min-h-14 transition-all duration-200',
        `flex min-h-14 flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:gap-4 ${presentation.containerClassName}`,
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

        <Button
          asChild
          size="sm"
          className={`h-7 w-fit gap-1.5 px-3 text-[12px] font-medium sm:ml-auto ${presentation.buttonClassName}`}
        >
          <a
            href="https://status.appwrite.online"
            target="_blank"
            rel="noopener noreferrer"
          >
            <span className="hidden sm:inline">View Status</span>
            <span className="sm:hidden">Status</span>
            <ExternalLink className="h-3.5 w-3.5" />
          </a>
        </Button>
    </div>
  )
}
