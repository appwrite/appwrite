import { useMemo } from 'react'
import { Outlet, useLocation, useNavigate, useParams } from '@tanstack/react-router'
import { ArrowLeft, Loader2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { ConsoleLayout } from '@/components/global/layout/ConsoleLayout'
import { OrganizationBillingHeaderBanners } from '@/components/global/shared/OrganizationBillingHeaderBanners'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { SettingsLayoutShell } from '@/components/global/shared/settings-search/SettingsLayoutShell'
import { ServiceHeader } from '@/components/pages/projects/$projectId/shared/ServiceHeader'
import { mapAppToMarketplaceApp } from '@/lib/marketplace/map-app'
import {
  buildOrgAppSettingsNavItems,
  getOrgAppSettingsSectionId,
} from '@/lib/org-apps/nav'
import { useOrganizationApp } from '@/lib/react-query/hooks'
import { MarketplaceAppBadges } from '../../marketplace/_components/MarketplaceAppBadges'
import { useT } from '@/lib/i18n/translate'

export type OrgAppLayoutInitialData = {
  app: import('@appwrite.io/console').Models.App
}

type LayoutProps = {
  initialData?: OrgAppLayoutInitialData
}

export function Layout({ initialData }: LayoutProps = {}) {
  const t = useT()
  const { orgId, appId } = useParams({ strict: false })
  const location = useLocation()
  const navigate = useNavigate()

  const { app: appFromHook, isLoading, isFetching } = useOrganizationApp(appId)
  const app = appFromHook ?? initialData?.app

  const mapped = useMemo(() => {
    if (!app || !orgId) return null
    return mapAppToMarketplaceApp(app, { organizationId: orgId })
  }, [app, orgId])

  const activeSectionId = getOrgAppSettingsSectionId(location.pathname)

  const navItems = useMemo(
    () =>
      orgId && appId
        ? buildOrgAppSettingsNavItems(orgId, appId, {
            showSecrets: app?.type !== 'public',
          })
        : [],
    [orgId, appId, app?.type],
  )

  const handleBack = () => {
    if (!orgId) return
    navigate({ to: '/organizations/$orgId/settings/oauth-apps', params: { orgId } })
  }

  if (!app && !initialData?.app && !isLoading) {
    return (
      <ConsoleLayout
        header={{
          onCommandCenterOpen: () => {},
          onCreateOrganization: () => {},
        }}
        headerBanner={
          <OrganizationBillingHeaderBanners organizationId={orgId} />
        }
        showFooter
        containerClassName="org-layout-container"
      >
        <div className="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6">
          <EmptyState
            title={t('App not found')}
            description={t(
              'This app may have been deleted or you do not have access.',
            )}
            variant="card"
          />
        </div>
      </ConsoleLayout>
    )
  }

  if (!app || !mapped || !orgId || !appId) {
    return (
      <ConsoleLayout
        header={{
          onCommandCenterOpen: () => {},
          onCreateOrganization: () => {},
        }}
        headerBanner={
          <OrganizationBillingHeaderBanners organizationId={orgId} />
        }
        showFooter
        containerClassName="org-layout-container"
      >
        <div className="flex min-h-64 items-center justify-center">
          <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
        </div>
      </ConsoleLayout>
    )
  }

  return (
    <ConsoleLayout
      header={{
        onCommandCenterOpen: () => {},
        onCreateOrganization: () => {},
      }}
      headerBanner={
        <OrganizationBillingHeaderBanners organizationId={orgId} />
      }
      showFooter
      containerClassName="org-layout-container"
    >
      <div className="flex flex-col">
        <ServiceHeader
          title={
            <div className="flex min-w-0 items-center gap-2">
              <Button
                variant="ghost"
                size="sm"
                className="h-7 w-7 p-0"
                onClick={handleBack}
                aria-label={t('Back to OAuth apps')}
              >
                <ArrowLeft className="h-4 w-4" />
              </Button>
              <span className="truncate text-[15px] font-semibold text-foreground">
                {mapped.name}
              </span>
              <CopyableId id={app.$id} size="xs" className="shrink-0" />
              {isFetching ? (
                <Loader2 className="h-4 w-4 shrink-0 animate-spin text-muted-foreground" />
              ) : null}
            </div>
          }
          titleRightContent={<MarketplaceAppBadges app={mapped} />}
          showFilters={false}
          fullWidthBorder
        />

        <div className="min-w-0 flex-1">
          <div className="mx-auto min-w-0 max-w-7xl px-4 py-4 sm:px-6">
            <SettingsLayoutShell
              navItems={navItems}
              activeSectionId={activeSectionId}
              cardIndex={[]}
              onNavigateToSection={(sectionId) => {
                const item = navItems.find((entry) => entry.id === sectionId)
                if (item) {
                  navigate({
                    to: item.to as '/',
                    params: item.params ?? { orgId, appId },
                  })
                }
              }}
              searchPlaceholder={t('Search app settings...')}
              mobileNavAriaLabel={t('App settings sections')}
              desktopNavAriaLabel={t('App settings')}
            >
              <Outlet />
            </SettingsLayoutShell>
          </div>
        </div>
      </div>
    </ConsoleLayout>
  )
}
