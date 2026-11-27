import { useMemo } from 'react'
import { Link, Outlet, useMatch } from '@tanstack/react-router'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { CopyableId } from '@/components/global/shared/CopyableId'
import {
  monitorQueryOptions,
  useOrganizationScopes,
  useProject,
} from '@/lib/react-query/hooks'
import { canWriteMonitors } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useT } from '@/lib/i18n/translate'
import { ServiceHeader, type Tab } from '../../shared/ServiceHeader'

/** A monitor's own page: what it is doing, its alerts, and its settings. */
export function MonitorLayout({
  projectId,
  monitorId,
}: {
  projectId: string
  monitorId: string
}) {
  const t = useT()
  // The rendered route, so the tab moves with the page it names.
  const settings = useMatch({
    from: '/_public/projects/$projectId/monitors_/$monitorId/settings',
    shouldThrow: false,
  })
  const activeTab = settings ? 'settings' : 'overview'
  const { data: monitor, isError } = useQuery(
    monitorQueryOptions(projectId, monitorId),
  )
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const canWrite = canWriteMonitors(access, features)

  const tabs = useMemo<Tab[]>(
    () => [
      {
        id: 'overview',
        label: t('Overview'),
        to: '/projects/$projectId/monitors/$monitorId',
        params: { projectId, monitorId },
      },
      ...(canWrite
        ? [
            {
              id: 'settings',
              label: t('Settings'),
              to: '/projects/$projectId/monitors/$monitorId/settings',
              params: { projectId, monitorId },
            },
          ]
        : []),
    ],
    [t, projectId, monitorId, canWrite],
  )

  if (isError) {
    return (
      <div className="flex items-center justify-center py-16">
        <div className="rounded-lg border border-border bg-card px-6 py-12 text-center">
          <p className="text-[13px] text-muted-foreground">
            {t('Monitor not found')}
          </p>
        </div>
      </div>
    )
  }

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title={
          <div className="flex min-w-0 items-center gap-2">
            <Button
              variant="ghost"
              size="sm"
              asChild
              className="h-7 w-7 p-0"
              aria-label={t('Back to monitors')}
            >
              <Link to="/projects/$projectId/monitors" params={{ projectId }}>
                <ArrowLeft className="h-4 w-4" />
              </Link>
            </Button>
            <span className="truncate">{monitor?.name ?? t('Monitor')}</span>
            <CopyableId id={monitorId} size="xs" className="shrink-0" />
          </div>
        }
        tabs={tabs}
        activeTab={activeTab}
        fullWidthBorder
      />
      <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 pt-4 sm:px-6 sm:pb-6 sm:pt-6">
        <Outlet />
      </div>
    </div>
  )
}
