import { useMemo, useState } from 'react'
import { EmptyState } from '@/components/global/shared/EmptyState'
import {
  MOCK_MARKETPLACE_CATALOG,
  MOCK_MARKETPLACE_OWNED,
  type MarketplaceApp,
  type MarketplaceAppCategory,
} from '@/lib/marketplace/mock-data'
import {
  MARKETPLACE_CATEGORY_ORDER,
  MARKETPLACE_SIDEBAR_LINKS,
  buildMarketplaceNavGroups,
  countAppsForNav,
  getAppsForMarketplaceNav,
  getMarketplaceNavItem,
  type MarketplaceNavId,
  type MarketplaceLinkItem,
} from '@/lib/marketplace/marketplace-nav'
import { MarketplaceAppCard } from './_components/MarketplaceAppCard'
import { CreateMarketplaceApp } from './_components/CreateMarketplaceApp'
import type { CreateMarketplaceAppInput } from './_components/CreateMarketplaceApp'
import { AppDetailDrawer } from './_components/AppDetailDrawer'
import { MarketplaceExplore } from './_components/MarketplaceExplore'
import { MarketplaceSidebar } from './_components/MarketplaceSidebar'
import { toast } from 'sonner'
import { Store } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { z } from 'zod'

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
  const navGroups = useMemo(() => buildMarketplaceNavGroups(), [])
  const [activeNavId, setActiveNavId] = useState<MarketplaceNavId>('explore')
  const [searchValue, setSearchValue] = useState('')
  const [catalogApps, setCatalogApps] = useState(MOCK_MARKETPLACE_CATALOG)
  const [ownedApps, setOwnedApps] = useState(MOCK_MARKETPLACE_OWNED)
  const [createDialogOpen, setCreateDialogOpen] = useState(false)
  const [detailApp, setDetailApp] = useState<MarketplaceApp | null>(null)
  const [detailOpen, setDetailOpen] = useState(false)

  const searchActive = searchValue.trim().length > 0
  const isExplore = activeNavId === 'explore'
  const activeItem = getMarketplaceNavItem(activeNavId, navGroups)

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

  const handleCreateApp = (input: CreateMarketplaceAppInput) => {
    const newApp: MarketplaceApp = {
      $id: `app-${input.slug}-${Date.now()}`,
      name: input.name,
      slug: input.slug,
      description: input.description,
      shortDescription: input.shortDescription,
      category: input.category,
      author: 'Your organization',
      creators: [{ name: 'You', role: 'Creator' }],
      installs: 0,
      rating: 0,
      featured: false,
      isOfficial: false,
      isVerified: false,
      isOwned: true,
      status: 'draft',
      tags: [input.category],
      $createdAt: new Date().toISOString(),
    }
    setOwnedApps((prev) => [newApp, ...prev])
    setActiveNavId('my-apps')
    toast.success('App saved as draft')
  }

  const handleInstall = (app: MarketplaceApp) => {
    toast.success(`${app.name} will be available when install APIs ship`)
    setDetailOpen(false)
  }

  const openDetail = (app: MarketplaceApp) => {
    setDetailApp(app)
    setDetailOpen(true)
  }

  const handleLinkAction = (link: MarketplaceLinkItem) => {
    if (link.action === 'add-app') {
      setCreateDialogOpen(true)
      return
    }
    if (link.action === 'publisher-guidelines') {
      toast.info(
        'Publisher guidelines will be available when marketplace APIs ship',
      )
    }
  }

  const exploreHasContent =
    featuredApps.length > 0 ||
    moreApps.length > 0 ||
    MARKETPLACE_CATEGORY_ORDER.some((c) => categoryCounts[c] > 0)

  const emptyTitle = searchActive
    ? 'No apps match your search'
    : activeNavId === 'my-apps'
      ? 'No apps published yet'
      : 'No apps in this section'

  const emptyDescription = searchActive
    ? 'Try adjusting or clearing your search.'
    : activeNavId === 'my-apps'
      ? 'Add your first app to share it with other organizations.'
      : 'New integrations will appear here when available.'

  const mainContent = () => {
    if (isExplore && !searchActive) {
      if (!exploreHasContent) {
        return (
          <EmptyState
            icon={Store}
            title="Marketplace is empty"
            description="Apps will appear here when listings are available."
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
              <Button size="sm" onClick={() => setCreateDialogOpen(true)}>
                Add app
              </Button>
            ) : undefined
          }
          variant="card"
        />
      )
    }

    return (
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
        {displayedApps.map((app) => (
          <MarketplaceAppCard
            key={app.$id}
            app={app}
            onClick={() => openDetail(app)}
          />
        ))}
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
      />

      <AppDetailDrawer
        app={detailApp}
        open={detailOpen}
        onOpenChange={setDetailOpen}
        onInstall={handleInstall}
      />
    </div>
  )
}
