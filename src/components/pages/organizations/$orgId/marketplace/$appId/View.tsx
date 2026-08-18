import { useMemo } from 'react'
import { Link, useParams } from '@tanstack/react-router'
import { useMutation } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { ArrowLeft, Loader2, Store } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { ConsoleLayout } from '@/components/global/layout/ConsoleLayout'
import { OrganizationBillingHeaderBanners } from '@/components/global/shared/OrganizationBillingHeaderBanners'
import { resolveAppLogoDisplayUrl } from '@/lib/appwrite/apps-logo'
import { mapAppToMarketplaceApp } from '@/lib/marketplace/map-app'
import { startMarketplaceAppInstall } from '@/lib/marketplace/install-app'
import {
  MARKETPLACE_CATEGORY_LABELS,
  MARKETPLACE_CATEGORY_ICONS,
} from '@/lib/marketplace/types'
import {
  useOrganizationApp,
  useOrganizations,
} from '@/lib/react-query/hooks'
import { MarketplaceAppBadges } from '../_components/MarketplaceAppBadges'
import { MarketplaceAppCreators } from '../_components/MarketplaceAppCreators'
import { toast } from 'sonner'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'

export type MarketplaceCatalogAppInitialData = {
  app: Models.App
}

type ViewProps = {
  initialData?: MarketplaceCatalogAppInitialData
}

export function View({ initialData }: ViewProps = {}) {
  const t = useT()
  const { orgId, appId } = useParams({ strict: false })
  const { organizations } = useOrganizations()

  const { app: appFromHook, isLoading, isFetching } = useOrganizationApp(appId)
  const app = appFromHook ?? initialData?.app

  const teamNamesById = useMemo(
    () =>
      Object.fromEntries(
        organizations.map((org) => [org.$id, org.name] as const),
      ),
    [organizations],
  )

  const mapped = useMemo(() => {
    if (!app || !orgId) return null
    return mapAppToMarketplaceApp(app, {
      organizationId: orgId,
      teamNamesById,
    })
  }, [app, orgId, teamNamesById])

  const installMutation = useMutation({
    mutationFn: () => {
      if (!mapped) throw new Error('App not loaded')
      return startMarketplaceAppInstall(mapped)
    },
    onError: (error) => {
      toast.error(getErrorMessage(error, t('Failed to start installation')))
    },
  })

  if (!app && !initialData?.app && !isLoading) {
    return (
      <ConsoleLayout
        headerBanner={
          <OrganizationBillingHeaderBanners organizationId={orgId} />
        }
      >
        <div className="mx-auto w-full max-w-7xl px-4 py-8 sm:px-6">
          <EmptyState
            icon={Store}
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

  if (!app || !mapped || !orgId) {
    return (
      <ConsoleLayout
        headerBanner={
          <OrganizationBillingHeaderBanners organizationId={orgId} />
        }
      >
        <div className="flex min-h-64 items-center justify-center">
          <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
        </div>
      </ConsoleLayout>
    )
  }

  const CategoryIcon = MARKETPLACE_CATEGORY_ICONS[mapped.category]
  const logoUrl = resolveAppLogoDisplayUrl(mapped.logoUri, {
    width: 96,
    height: 96,
  })

  return (
    <ConsoleLayout
      headerBanner={
        <OrganizationBillingHeaderBanners organizationId={orgId} />
      }
    >
      <div className="flex h-full flex-col">
        <div className="border-b border-border bg-background">
          <div className="mx-auto w-full max-w-7xl px-4 py-4 sm:px-6">
            <Link
              to="/organizations/$orgId/marketplace"
              params={{ orgId }}
              className="inline-flex items-center gap-1.5 text-[13px] text-muted-foreground hover:text-foreground mb-4"
            >
              <ArrowLeft className="h-3.5 w-3.5" />
              {t('Marketplace')}
            </Link>

            <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
              <div className="flex items-start gap-4 min-w-0">
                <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-muted text-muted-foreground overflow-hidden">
                  {logoUrl ? (
                    <img
                      src={logoUrl}
                      alt=""
                      className="h-full w-full object-cover"
                    />
                  ) : (
                    <CategoryIcon className="h-6 w-6" />
                  )}
                </div>
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <h1 className="text-[20px] font-semibold text-foreground truncate">
                      {mapped.name}
                    </h1>
                    <MarketplaceAppBadges app={mapped} />
                    {isFetching && (
                      <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
                    )}
                  </div>
                  <p className="text-[13px] text-muted-foreground mt-1">
                    {mapped.shortDescription}
                  </p>
                  <div className="flex flex-wrap items-center gap-2 mt-2 text-[12px] text-muted-foreground">
                    <Badge variant="inactive" className="text-[10px] shrink-0">
                      {t(MARKETPLACE_CATEGORY_LABELS[mapped.category])}
                    </Badge>
                    <span>{t('by')} {mapped.author}</span>
                    <span>·</span>
                    <DateTooltip date={mapped.$createdAt} />
                  </div>
                </div>
              </div>

              <Button
                disabled={installMutation.isPending}
                onClick={() => installMutation.mutate()}
              >
                {t('Install app')}
              </Button>
            </div>
          </div>
        </div>

        <div className="mx-auto w-full max-w-7xl flex-1 overflow-y-auto px-4 py-6 sm:px-6 sm:pb-8">
          <div className="space-y-4 rounded-xl border border-border bg-card/50 p-6">
            <p className="text-[13px] text-muted-foreground leading-relaxed">
              {mapped.description}
            </p>
            {mapped.clientUri && (
              <p className="text-[13px]">
                <span className="text-muted-foreground">{t('Homepage:')} </span>
                <a
                  href={mapped.clientUri}
                  target="_blank"
                  rel="noopener noreferrer"
                  className="text-foreground underline-offset-4 hover:underline"
                >
                  {mapped.clientUri}
                </a>
              </p>
            )}
            <div className="space-y-2">
              <h4 className="text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                {t('Creators')}
              </h4>
              <MarketplaceAppCreators creators={mapped.creators} />
            </div>
          </div>
        </div>
      </div>
    </ConsoleLayout>
  )
}
