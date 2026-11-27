import { useEffect, useMemo, useState } from 'react'
import {
  Link,
  Outlet,
  useNavigate,
  useParams,
  useRouterState,
} from '@tanstack/react-router'
import { AlertCircle, ArrowLeft, Package, Rocket } from 'lucide-react'
import { ServiceHeader, type Tab } from '../../shared/ServiceHeader'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { analyticsAttrs } from '@/lib/analytics-actions'
import type { DistributionApp } from '@/lib/appwrite/distribution'
import {
  canPublishDistributionApp,
  canWriteDistributionApp,
} from '@/lib/console-access-checks'
import { useT } from '@/lib/i18n/translate'
import {
  useDistributionApp,
  useDistributionTargets,
  useOrganizationScopes,
  useProject,
} from '@/lib/react-query/hooks'
import {
  getErrorMessage,
  isAppwriteHttpStatus,
} from '@/lib/utils/error-formatting'
import { DisabledReasonTooltip } from '../_components/DisabledReasonTooltip'
import { Publish } from './_components/Publish'

const APP_TABS = [
  'overview',
  'builds',
  'releases',
  'artifacts',
  'targets',
  'settings',
  'variables',
] as const

type AppTabId = (typeof APP_TABS)[number]

function isAppTab(segment: string | undefined): segment is AppTabId {
  return APP_TABS.some((tab) => tab === segment)
}

export type LayoutInitialData = {
  app: DistributionApp
}

type LayoutProps = {
  initialData?: LayoutInitialData
}

export function Layout({ initialData }: LayoutProps = {}) {
  const t = useT()
  const navigate = useNavigate()
  const { projectId = '', appId = '' } = useParams({ strict: false })
  const pathname = useRouterState({
    select: (state) =>
      state.resolvedLocation?.pathname ?? state.location.pathname,
  })

  const {
    data: appFromHook,
    isLoading,
    isFetching,
    error,
    refetch,
  } = useDistributionApp(projectId, appId)
  const app = appFromHook ?? initialData?.app

  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const showSettings = canWriteDistributionApp(access, features)
  const canPublish = canPublishDistributionApp(access, features)
  const {
    targets,
    isLoading: targetsLoading,
    error: targetsError,
  } = useDistributionTargets(projectId, appId)
  const [publishOpen, setPublishOpen] = useState(false)
  const [publishSession, setPublishSession] = useState(0)

  const activeTab = useMemo<AppTabId>(() => {
    const segments = pathname.split('/').filter(Boolean)
    const distributionIndex = segments.indexOf('distribution')
    const segment = segments[distributionIndex + 2]
    return distributionIndex >= 0 && isAppTab(segment) ? segment : 'overview'
  }, [pathname])

  useEffect(() => {
    if (showSettings || !projectId || !appId) return
    if (activeTab === 'settings' || activeTab === 'variables') {
      navigate({
        to: '/projects/$projectId/distribution/$appId/targets',
        params: { projectId, appId },
        replace: true,
      })
    }
  }, [showSettings, activeTab, projectId, appId, navigate])

  const tabs = useMemo<Tab[]>(() => {
    const params = { projectId, appId }
    return [
      {
        id: 'overview',
        label: t('Overview'),
        to: '/projects/$projectId/distribution/$appId/overview',
        params,
      },
      {
        id: 'builds',
        label: t('Builds'),
        to: '/projects/$projectId/distribution/$appId/builds',
        params,
      },
      {
        id: 'releases',
        label: t('Releases'),
        to: '/projects/$projectId/distribution/$appId/releases',
        params,
      },
      {
        id: 'artifacts',
        label: t('Artifacts'),
        to: '/projects/$projectId/distribution/$appId/artifacts',
        params,
      },
      {
        id: 'targets',
        label: t('Targets'),
        to: '/projects/$projectId/distribution/$appId/targets',
        params,
      },
      ...(showSettings
        ? [
            {
              id: 'settings',
              label: t('Settings'),
              to: '/projects/$projectId/distribution/$appId/settings',
              params,
            },
            {
              id: 'variables',
              label: t('Variables'),
              to: '/projects/$projectId/distribution/$appId/variables',
              params,
            },
          ]
        : []),
    ]
  }, [projectId, appId, showSettings, t])

  const back = (
    <Button
      variant="ghost"
      size="sm"
      asChild
      className="h-7 w-7 shrink-0 p-0"
      aria-label={t('Back to apps')}
    >
      <Link to="/projects/$projectId/distribution" params={{ projectId }}>
        <ArrowLeft className="h-4 w-4 rtl:rotate-180" />
      </Link>
    </Button>
  )

  if (!app && !isLoading) {
    const backToApps = (
      <Button variant="outline" size="sm" asChild>
        <Link to="/projects/$projectId/distribution" params={{ projectId }}>
          {t('Back to apps')}
        </Link>
      </Button>
    )
    const missing = !error || isAppwriteHttpStatus(error, 404)
    return (
      <div className="flex min-h-0 flex-1 flex-col">
        <ServiceHeader
          title={
            <span className="flex min-w-0 items-center gap-2">
              {back}
              <span className="truncate">{t('Distribution')}</span>
            </span>
          }
          fullWidthBorder
          hideToolbar
        />
        <div className="mx-auto w-full max-w-7xl flex-1 px-4 py-4 sm:px-6 sm:py-6">
          {missing ? (
            <EmptyState
              icon={Package}
              title={t('App not found')}
              description={t(
                'This distribution app does not exist or has been removed.',
              )}
              variant="card"
              action={backToApps}
            />
          ) : (
            <EmptyState
              icon={AlertCircle}
              title={t('Could not load this app')}
              description={getErrorMessage(
                error,
                t('Something went wrong. Please try again.'),
              )}
              variant="card"
              action={
                <div className="flex items-center gap-2">
                  {backToApps}
                  <Button
                    size="sm"
                    disabled={isFetching}
                    onClick={() => void refetch()}
                  >
                    {t('Try again')}
                  </Button>
                </div>
              }
            />
          )}
        </div>
      </div>
    )
  }

  return (
    <div className="flex min-h-0 flex-1 flex-col">
      <ServiceHeader
        title={
          <span className="flex min-w-0 items-center gap-2">
            {back}
            <span className="truncate">{app?.name ?? t('App')}</span>
            {app && !app.enabled ? (
              <Badge variant="inactive" className="text-[10px] shrink-0">
                {t('Disabled')}
              </Badge>
            ) : null}
            {app ? (
              <CopyableId id={app.$id} size="xs" className="shrink-0" />
            ) : null}
          </span>
        }
        titleRightContent={
          app ? (
            <PublishButton
              disabled={!canPublish || targetsLoading || targets.length === 0}
              disabledReason={
                !canPublish
                  ? t("You don't have permission to publish this app.")
                  : targetsError
                    ? t('Could not load the targets of this app.')
                    : !targetsLoading && targets.length === 0
                      ? t('Add a target to publish this app.')
                      : undefined
              }
              onClick={() => {
                setPublishSession((session) => session + 1)
                setPublishOpen(true)
              }}
            />
          ) : undefined
        }
        tabs={tabs}
        activeTab={activeTab}
        fullWidthBorder
        hideToolbar
      />
      <Outlet />
      {app && canPublish && targets.length > 0 ? (
        <Publish
          key={publishSession}
          open={publishOpen}
          onOpenChange={setPublishOpen}
          projectId={projectId}
          app={app}
          targets={targets}
        />
      ) : null}
    </div>
  )
}

function PublishButton({
  disabled,
  disabledReason,
  onClick,
}: {
  disabled: boolean
  disabledReason: string | undefined
  onClick: () => void
}) {
  const t = useT()
  return (
    <DisabledReasonTooltip reason={disabledReason ?? null} side="bottom">
      <Button
        type="button"
        variant="brandCta"
        size="sm"
        className="h-8 gap-1.5 text-[13px] font-medium"
        onClick={onClick}
        disabled={disabled}
        {...analyticsAttrs('distribution-publish')}
      >
        <Rocket className="h-4 w-4 shrink-0" />
        {t('Publish')}
      </Button>
    </DisabledReasonTooltip>
  )
}
