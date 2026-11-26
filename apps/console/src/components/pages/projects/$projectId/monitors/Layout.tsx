import { useMemo } from 'react'
import { Outlet, useMatch } from '@tanstack/react-router'
import { useInfiniteQuery } from '@tanstack/react-query'
import {
  alertsInfiniteQueryOptions,
  useMonitors,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { ServiceHeader, type Tab } from '../shared/ServiceHeader'

/** What the project watches and what that raised, as two tabs. */
export function MonitorsLayout({ projectId }: { projectId: string }) {
  const t = useT()
  const alerts = useMatch({
    from: '/_public/projects/$projectId/monitors/alerts',
    shouldThrow: false,
  })
  const activeTab = alerts ? 'alerts' : 'monitors'

  const { monitors } = useMonitors(projectId)
  // An acknowledged alert has someone on it, so it leaves the count while it keeps firing.
  const { data: firing } = useInfiniteQuery(
    alertsInfiniteQueryOptions(projectId, { status: 'firing' }),
  )
  const acknowledged = new Set(
    firing?.pages
      .flatMap((page) => page.alerts)
      .filter((alert) => alert.acknowledgedAt)
      .map((alert) => alert.$id),
  )
  const firingAlerts = monitors.filter(
    (monitor) =>
      monitor.enabled &&
      monitor.status === 'firing' &&
      !acknowledged.has(monitor.alertId),
  ).length

  const tabs = useMemo<Tab[]>(
    () => [
      {
        id: 'monitors',
        label: t('Monitors'),
        to: '/projects/$projectId/monitors',
        params: { projectId },
      },
      {
        id: 'alerts',
        label: t('Alerts'),
        count: firingAlerts > 0 ? firingAlerts : undefined,
        to: '/projects/$projectId/monitors/alerts',
        params: { projectId },
      },
    ],
    [t, projectId, firingAlerts],
  )

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title={t('Monitors')}
        tabs={tabs}
        activeTab={activeTab}
        fullWidthBorder
      />
      <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-6 pt-5 sm:px-6">
        <p className="mb-6 text-[13px] text-muted-foreground">
          {activeTab === 'monitors'
            ? t(
                'Get notified when usage crosses a threshold, and again when it recovers.',
              )
            : t(
                'Every time a monitor fired: when, how far over its threshold, and when it recovered.',
              )}
        </p>
        <Outlet />
      </div>
    </div>
  )
}
