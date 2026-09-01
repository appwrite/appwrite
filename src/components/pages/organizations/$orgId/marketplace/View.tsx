import { useEffect, useMemo, useState } from 'react'
import { useNavigate, useParams } from '@tanstack/react-router'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import type {
  MarketplaceApp,
  MarketplaceAppCategory,
} from '@/lib/marketplace/types'
import {
  MARKETPLACE_TOTAL_CAP,
  formatMarketplaceCount,
} from '@/lib/marketplace/types'
import {
  MARKETPLACE_SIDEBAR_LINKS,
  buildMarketplaceNavGroups,
  getAppsForMarketplaceNav,
  getMarketplaceNavItem,
  type MarketplaceNavId,
  type MarketplaceLinkItem,
} from '@/lib/marketplace/marketplace-nav'
import {
  MARKETPLACE_PAGE_SIZE,
  useCreateOrganizationApp,
  useMarketplaceCatalog,
  useMarketplaceCatalogPage,
  useMarketplaceNavCounts,
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
  const [page, setPage] = useState(1)
  const [createDialogOpen, setCreateDialogOpen] = useState(false)

  const searchActive = searchValue.trim().length > 0
  const isExplore = activeNavId === 'explore'
  const activeCategory = activeNavId.startsWith('category:')
    ? (activeNavId.slice('category:'.length) as MarketplaceAppCategory)
    : undefined
  // Explore, catalog, and category pages are server-paginated; other lists
  // paginate client-side.
  const isBrowseNav =
    isExplore || activeNavId === 'catalog' || activeCategory !== undefined
  const activeItem = getMarketplaceNavItem(activeNavId, navGroups)

  const { catalogTotal, categoryTotals } = useMarketplaceNavCounts(orgId)
  const {
    apps: browseApps,
    total: browseTotal,
    isLoading: browseLoading,
    isFetching: browseFetching,
  } = useMarketplaceCatalogPage(
    orgId,
    { category: activeCategory, page },
    teamNamesById,
  )

  const handleNavChange = (navId: MarketplaceNavId) => {
    setActiveNavId(navId)
    setPage(1)
  }

  const handleSearchChange = (value: string) => {
    setSearchValue(value)
    setPage(1)
  }

  // Snap back when the last page disappears (e.g. after a refetch shrinks the list).
  useEffect(() => {
    if (searchActive || !isBrowseNav || browseTotal === 0) return
    const maxPage = Math.max(1, Math.ceil(browseTotal / MARKETPLACE_PAGE_SIZE))
    if (page > maxPage) setPage(maxPage)
  }, [searchActive, isBrowseNav, browseTotal, page])

  const listLoading =
    (catalogLoading || ownedLoading) &&
    catalogApps.length === 0 &&
    ownedApps.length === 0
  const listFetching = catalogFetching || ownedFetching || browseFetching

  const filteredCatalog = useMemo(
    () => filterApps(catalogApps, searchValue),
    [catalogApps, searchValue],
  )

  // Search runs client-side over the loaded catalog; browsing uses server pages.
  const searchResults = useMemo(() => {
    if (!searchActive) return []
    if (isExplore) return filteredCatalog
    const base = getAppsForMarketplaceNav(activeNavId, catalogApps, ownedApps)
    return filterApps(base, searchValue)
  }, [
    searchActive,
    isExplore,
    filteredCatalog,
    activeNavId,
    catalogApps,
    ownedApps,
    searchValue,
  ])

  const listedApps = useMemo(() => {
    if (searchActive) return searchResults
    if (isBrowseNav) return browseApps
    return getAppsForMarketplaceNav(activeNavId, catalogApps, ownedApps)
  }, [
    searchActive,
    searchResults,
    isBrowseNav,
    browseApps,
    activeNavId,
    catalogApps,
    ownedApps,
  ])

  // Server pages arrive pre-sliced; everything else is paginated client-side.
  const listedTotal =
    !searchActive && isBrowseNav ? browseTotal : listedApps.length
  const displayedApps = useMemo(() => {
    if (!searchActive && isBrowseNav) return listedApps
    const start = (page - 1) * MARKETPLACE_PAGE_SIZE
    return listedApps.slice(start, start + MARKETPLACE_PAGE_SIZE)
  }, [searchActive, isBrowseNav, listedApps, page])

  const getItemCount = (navId: MarketplaceNavId): string => {
    if (searchActive) {
      if (navId === 'explore') return String(filteredCatalog.length)
      return String(
        filterApps(
          getAppsForMarketplaceNav(navId, catalogApps, ownedApps),
          searchValue,
        ).length,
      )
    }
    if (navId === 'explore' || navId === 'catalog') {
      return formatMarketplaceCount(catalogTotal)
    }
    if (navId === 'my-apps') return String(ownedApps.length)
    if (navId.startsWith('category:')) {
      const category = navId.slice('category:'.length) as MarketplaceAppCategory
      const total = categoryTotals?.[category]
      return total === undefined ? '' : formatMarketplaceCount(total)
    }
    return ''
  }

  const handleCreateApp = async (input: CreateMarketplaceAppInput) => {
    try {
      const app = await createAppMutation.mutateAsync(input)
      setCreateDialogOpen(false)
      toast.success(t('App created'))
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

  const emptyTitle = searchActive
    ? t('No apps match your search')
    : activeNavId === 'my-apps'
      ? t('No apps published yet')
      : isExplore
        ? t('Marketplace is empty')
        : t('No apps in this section')

  const emptyDescription = searchActive
    ? t('Try adjusting or clearing your search.')
    : activeNavId === 'my-apps'
      ? t('Add your first app to get started.')
      : t(
          'We invite app makers to contact us and get your integrations published.',
        )

  const mainContent = () => {
    const browseLoading2 =
      !searchActive && isBrowseNav && browseLoading && browseApps.length === 0

    if (listLoading || browseLoading2) {
      return (
        <div className="flex min-h-64 items-center justify-center">
          <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
        </div>
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
                {t('Create app')}
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
        {listedTotal > MARKETPLACE_PAGE_SIZE && (
          <Pagination
            currentPage={page}
            totalItems={listedTotal}
            totalDisplay={
              listedTotal >= MARKETPLACE_TOTAL_CAP
                ? formatMarketplaceCount(listedTotal)
                : undefined
            }
            pageSize={MARKETPLACE_PAGE_SIZE}
            onPageChange={setPage}
            onPageSizeChange={() => {}}
            showPageSizeSelector={false}
            itemLabel={t('apps')}
            className="mt-6"
          />
        )}
        {isExplore && !searchActive && (
          <div className="mt-10">
            <MarketplaceExplore
              categoryCounts={categoryTotals}
              onNavigate={handleNavChange}
            />
          </div>
        )}
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
        onNavChange={handleNavChange}
        searchValue={searchValue}
        onSearchChange={handleSearchChange}
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
