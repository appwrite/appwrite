import { useMemo, useState } from 'react'
import { useNavigate, useParams } from '@tanstack/react-router'
import { EmptyState } from '@/components/global/shared/EmptyState'
import type {
  MarketplaceApp,
  MarketplaceAppCategory,
} from '@/lib/marketplace/types'
import { MARKETPLACE_CATEGORY_ORDER } from '@/lib/marketplace/types'
import {
  MARKETPLACE_SIDEBAR_LINKS,
  buildMarketplaceNavGroups,
  countAppsForNav,
  getAppsForMarketplaceNav,
  getMarketplaceNavItem,
  type MarketplaceNavId,
  type MarketplaceLinkItem,
} from '@/lib/marketplace/marketplace-nav'
import {
  useCreateOrganizationApp,
  useMarketplaceCatalog,
  useOrganizationApps,
  useOrganizations,
} from '@/lib/react-query/hooks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { getDocsPageUrl } from '@/lib/marketing/urls'
import { openInNewWindow } from '@/lib/utils/context-menu'
import { MarketplaceAppCard } from './_components/MarketplaceAppCard'
import { CreateMarketplaceApp } from './_components/CreateMarketplaceApp'
import type { CreateMarketplaceAppInput } from './_components/CreateMarketplaceApp'
import { MarketplaceExplore } from './_components/MarketplaceExplore'
import { MarketplaceSidebar } from './_components/MarketplaceSidebar'
import { toast } from 'sonner'
import { Loader2, Store } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { z } from 'zod'
import { useT } from '@/lib/i18n/translate'
import { analyticsAttrs } from '@/lib/analytics-actions'

export const marketplaceSearchSchema = z.object({}).passthrough()

function filterApps(apps: MarketplaceApp[], query: string): MarketplaceApp[] {
  const q = query.trim().toLowerCase()
  if (!q) return apps
  return apps.filter(
    (app) =>
      app.name.toLowerCase().includes(q) ||
      app.shortDescription.toLowerCase().includes(q) ||
      app.description.toLowerCase().includes(q) ||
      app.tags.some((t) => t.toLowerCase().includes(q)) ||
      app.author.toLowerCase().includes(q),
  )
}

export function View() {
  const t = useT()
  const { orgId } = useParams({ strict: false })
  const navigate = useNavigate()
  const { features } = useConsoleProfile()
  const { organizations } = useOrganizations()

  const teamNamesById = useMemo(
    () =>
      Object.fromEntries(
        organizations.map((org) => [org.$id, org.name] as const),
      ),
    [organizations],
  )

  const {
    apps: catalogApps,
    isLoading: catalogLoading,
    isFetching: catalogFetching,
  } = useMarketplaceCatalog(orgId, teamNamesById)
  const {
    apps: ownedApps,
    isLoading: ownedLoading,
    isFetching: ownedFetching,
  } = useOrganizationApps(orgId, teamNamesById)

  const createAppMutation = useCreateOrganizationApp(orgId)

  const navGroups = useMemo(() => buildMarketplaceNavGroups(), [])
  const [activeNavId, setActiveNavId] = useState<MarketplaceNavId>('explore')
  const [searchValue, setSearchValue] = useState('')
  const [createDialogOpen, setCreateDialogOpen] = useState(false)

  const searchActive = searchValue.trim().length > 0
  const isExplore = activeNavId === 'explore'
  const activeItem = getMarketplaceNavItem(activeNavId, navGroups)

  const listLoading =
    (catalogLoading || ownedLoading) &&
    catalogApps.length === 0 &&
    ownedApps.length === 0
  const listFetching = catalogFetching || ownedFetching

  const filteredCatalog = useMemo(
    () => filterApps(catalogApps, searchValue),
    [catalogApps, searchValue],
  )

  const featuredApps = useMemo(
    () => filteredCatalog.filter((a) => a.featured),
    [filteredCatalog],
  )

  const moreApps = useMemo(
    () => filteredCatalog.filter((a) => !a.featured),
    [filteredCatalog],
  )

  const categoryCounts = useMemo(() => {
    const counts = Object.fromEntries(
      MARKETPLACE_CATEGORY_ORDER.map((c) => [c, 0]),
    ) as Record<MarketplaceAppCategory, number>
    for (const app of catalogApps) {
      counts[app.category] += 1
    }
    return counts
  }, [catalogApps])

  const displayedApps = useMemo(() => {
    if (isExplore) {
      return searchActive ? filteredCatalog : []
    }
    const base = getAppsForMarketplaceNav(activeNavId, catalogApps, ownedApps)
    return filterApps(base, searchValue)
  }, [
    isExplore,
    searchActive,
    filteredCatalog,
    activeNavId,
    catalogApps,
    ownedApps,
    searchValue,
  ])

  const getItemCount = (navId: MarketplaceNavId) => {
    if (navId === 'explore') {
      return searchActive
        ? filteredCatalog.length
        : countAppsForNav('catalog', catalogApps, ownedApps)
    }
    return filterApps(
      getAppsForMarketplaceNav(navId, catalogApps, ownedApps),
      searchValue,
    ).length
  }

  const handleCreateApp = async (input: CreateMarketplaceAppInput) => {
    try {
      const app = await createAppMutation.mutateAsync(input)
      setCreateDialogOpen(false)
      toast.success(t('App created as draft'))
      if (orgId && app?.$id) {
        navigate({
          to: '/organizations/$orgId/apps/$appId',
          params: { orgId, appId: app.$id },
        })
      }
    } catch (error) {
      toast.error(getErrorMessage(error, t('Failed to create app')))
    }
  }

  const openDetail = (app: MarketplaceApp) => {
    if (!orgId) return
    if (app.isOwned) {
      navigate({
        to: '/organizations/$orgId/apps/$appId',
        params: { orgId, appId: app.$id },
      })
      return
    }
    navigate({
      to: '/organizations/$orgId/marketplace/$appId',
      params: { orgId, appId: app.$id },
    })
  }

  const handleLinkAction = (link: MarketplaceLinkItem) => {
    if (link.action === 'add-app') {
      setCreateDialogOpen(true)
      return
    }
    if (link.action === 'publisher-guidelines') {
      openInNewWindow(getDocsPageUrl('/docs', features.marketing))
    }
  }

  const exploreHasContent =
    featuredApps.length > 0 ||
    moreApps.length > 0 ||
    MARKETPLACE_CATEGORY_ORDER.some((c) => categoryCounts[c] > 0)

  const emptyTitle = searchActive
    ? t('No apps match your search')
    : activeNavId === 'my-apps'
      ? t('No apps published yet')
      : t('No apps in this section')

  const emptyDescription = searchActive
    ? t('Try adjusting or clearing your search.')
    : activeNavId === 'my-apps'
      ? t('Add your first app to share it with other organizations.')
      : t('Published apps from other organizations will appear here.')

  const mainContent = () => {
    if (listLoading) {
      return (
        <div className="flex min-h-64 items-center justify-center">
          <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
        </div>
      )
    }

    if (isExplore && !searchActive) {
      if (!exploreHasContent) {
        return (
          <EmptyState
            icon={Store}
            title={t('Marketplace is empty')}
            description={t(
              'Apps will appear here when other organizations publish listings.',
            )}
            variant="card"
          />
        )
      }
      return (
        <MarketplaceExplore
          catalogApps={catalogApps}
          featuredApps={featuredApps}
          moreApps={moreApps}
          categoryCounts={categoryCounts}
          onAppClick={openDetail}
          onNavigate={setActiveNavId}
        />
      )
    }

    if (displayedApps.length === 0) {
      return (
        <EmptyState
          icon={Store}
          title={emptyTitle}
          description={emptyDescription}
          action={
            activeNavId === 'my-apps' && !searchActive ? (
              <Button
                size="sm"
                onClick={() => setCreateDialogOpen(true)}
                {...analyticsAttrs('create-marketplace-app')}
              >
                {t('Add app')}
              </Button>
            ) : undefined
          }
          variant="card"
        />
      )
    }

    return (
      <div className="relative">
        {listFetching && (
          <div className="absolute end-0 top-0 z-10">
            <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
          </div>
        )}
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
          {displayedApps.map((app) => (
            <MarketplaceAppCard
              key={app.$id}
              app={app}
              onClick={() => openDetail(app)}
            />
          ))}
        </div>
      </div>
    )
  }

  return (
    <div className="flex h-full flex-col">
      <MarketplaceSidebar
        navGroups={navGroups}
        links={MARKETPLACE_SIDEBAR_LINKS}
        activeNavId={activeNavId}
        activeItem={activeItem}
        onNavChange={setActiveNavId}
        searchValue={searchValue}
        onSearchChange={setSearchValue}
        getItemCount={getItemCount}
        onLinkAction={handleLinkAction}
      >
        {mainContent()}
      </MarketplaceSidebar>

      <CreateMarketplaceApp
        open={createDialogOpen}
        onOpenChange={setCreateDialogOpen}
        onCreate={handleCreateApp}
        isSubmitting={createAppMutation.isPending}
      />
    </div>
  )
}
