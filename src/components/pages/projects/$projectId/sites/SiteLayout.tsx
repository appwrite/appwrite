import { Outlet, useParams, useNavigate, Link } from '@tanstack/react-router'
import { ServiceHeader, type Tab } from '../shared/ServiceHeader'
import { CopyableId } from '@/components/global/shared/CopyableId'
import {
  useProjectSite,
  useProject,
  useOrganizationScopes,
} from '@/lib/react-query/hooks'
import { canShowSiteSettingsTab } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useMemo } from 'react'
import { useLocation } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { ArrowLeft, AlertCircle } from 'lucide-react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import {
  RefreshProvider,
  useRefresh,
} from '@/components/global/shared/RefreshContext'
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'

export function SiteLayout() {
  return (
    <RefreshProvider>
      <SiteLayoutContent />
    </RefreshProvider>
  )
}

function SiteLayoutContent() {
  const t = useT()
  const { projectId, siteId } = useParams({ strict: false })
  const location = useLocation()
  const navigate = useNavigate()
  const { isRefreshing, triggerRefresh, hasRefreshHandler } = useRefresh()
  const { data: site } = useProjectSite(projectId, siteId)
  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const showSettingsTab = canShowSiteSettingsTab(access, features)

  const handleBack = () => {
    navigate({
      to: '/projects/$projectId/sites',
      params: { projectId: projectId! },
    })
  }

  const handleFilterClick = () => {
    // TODO: Open filters dialog
    toast.info(t('Filters coming soon'))
  }

  // Derive active tab from pathname
  const activeTab = useMemo(() => {
    const pathParts = location.pathname.split('/').filter(Boolean)
    const sitesIndex = pathParts.findIndex((part) => part === 'sites')

    if (sitesIndex >= 0 && pathParts[sitesIndex + 2]) {
      const tab = pathParts[sitesIndex + 2]
      if (
        ['deployments', 'logs', 'domains', 'variables', 'settings'].includes(
          tab,
        )
      ) {
        return tab
      }
    }

    // Default to deployments for index route
    return 'deployments'
  }, [location.pathname])

  const tabs: Tab[] = useMemo(
    () => [
      {
        id: 'deployments',
        label: t('Deployments'),
        to: '/projects/$projectId/sites/$siteId',
        params: { projectId: projectId!, siteId: siteId! },
      },
      {
        id: 'domains',
        label: t('Domains'),
        to: '/projects/$projectId/sites/$siteId/domains',
        params: { projectId: projectId!, siteId: siteId! },
      },
      {
        id: 'logs',
        label: t('Logs'),
        to: '/projects/$projectId/sites/$siteId/logs',
        params: { projectId: projectId!, siteId: siteId! },
      },
      ...(showSettingsTab
        ? [
            {
              id: 'variables' as const,
              label: t('Variables'),
              to: '/projects/$projectId/sites/$siteId/variables',
              params: { projectId: projectId!, siteId: siteId! },
            },
            {
              id: 'settings' as const,
              label: t('Settings'),
              to: '/projects/$projectId/sites/$siteId/settings',
              params: { projectId: projectId!, siteId: siteId! },
            },
          ]
        : []),
    ],
    [projectId, siteId, showSettingsTab, t],
  )

  const disabledAlert =
    site && site.enabled === false ? (
      <div className="border-b border-border bg-amber-500/5">
        <div className="mx-auto w-full max-w-7xl px-4 py-3 sm:px-6">
          <Alert
            variant="default"
            className="border-amber-500/30 bg-transparent"
          >
            <AlertCircle className="h-4 w-4 text-amber-500" />
            <AlertTitle className="text-[13px] font-medium text-amber-600 dark:text-amber-400">
              {t('Site is disabled')}
            </AlertTitle>
            <AlertDescription className="text-[12px] text-amber-600/80 dark:text-amber-400/80">
              <span className="inline">
                {t(
                  'This site is disabled and not accessible to visitors. Console actions remain available.',
                )}{' '}
                <Link
                  to="/projects/$projectId/sites/$siteId/settings"
                  params={{ projectId: projectId!, siteId: siteId! }}
                  className="font-medium underline hover:no-underline inline"
                >
                  {t('Enable this site in the Settings tab')}
                </Link>{' '}
                {t('to make it available to visitors.')}
              </span>
            </AlertDescription>
          </Alert>
        </div>
      </div>
    ) : undefined

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title={
          <div className="flex min-w-0 items-center gap-2">
            <Button
              variant="ghost"
              size="sm"
              className="h-7 w-7 p-0"
              onClick={handleBack}
              aria-label={t('Back to sites')}
            >
              <ArrowLeft className="h-4 w-4" />
            </Button>
            <span className="truncate">{site?.name || t('Site')}</span>
            {site?.$id ? (
              <CopyableId id={site.$id} size="xs" className="shrink-0" />
            ) : null}
          </div>
        }
        tabs={tabs}
        activeTab={activeTab}
        fullWidthBorder
        showFilters={activeTab === 'logs'}
        onFilterClick={activeTab === 'logs' ? handleFilterClick : undefined}
        showRefresh={activeTab === 'logs' && hasRefreshHandler}
        onRefresh={activeTab === 'logs' ? triggerRefresh : undefined}
        isRefreshing={isRefreshing}
        contentAfterBorder={disabledAlert}
      />
      <div className="flex-1 min-h-0">
        <Outlet />
      </div>
    </div>
  )
}
