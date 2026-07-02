import type { ComponentType } from 'react'
import {
  MARKETPLACE_CATEGORY_ICONS,
  MARKETPLACE_CATEGORY_LABELS,
  type MarketplaceApp,
  type MarketplaceAppCategory,
} from '@/lib/marketplace/types'
import {
  MARKETPLACE_CATEGORY_ORDER,
  type MarketplaceNavId,
} from '@/lib/marketplace/marketplace-nav'
import {
  RESOURCE_CARD_GRID_2_COL_CLASSNAME,
  RESOURCE_CARD_GRID_WIDE_CLASSNAME,
} from '@/components/pages/projects/$projectId/shared/ResourceCard'
import { MarketplaceAppCard } from './MarketplaceAppCard'
import { cn } from '@/lib/utils'
import { ChevronRight } from 'lucide-react'
import { useT } from '@/lib/i18n/translate'

type MarketplaceExploreProps = {
  catalogApps: MarketplaceApp[]
  featuredApps: MarketplaceApp[]
  moreApps: MarketplaceApp[]
  categoryCounts: Record<MarketplaceAppCategory, number>
  onAppClick: (app: MarketplaceApp) => void
  onNavigate: (navId: MarketplaceNavId) => void
}

function SectionHeader({
  title,
  description,
  actionLabel,
  onAction,
  icon: Icon,
}: {
  title: string
  description?: string
  actionLabel?: string
  onAction?: () => void
  icon?: ComponentType<{ className?: string }>
}) {
  return (
    <div className="mb-4 flex items-start justify-between gap-4">
      <div className="min-w-0">
        <div className="flex items-center gap-2">
          {Icon && <Icon className="h-4 w-4 text-muted-foreground" />}
          <h3 className="text-[15px] font-semibold text-foreground">{title}</h3>
        </div>
        {description && (
          <p className="text-[13px] text-muted-foreground mt-1">{description}</p>
        )}
      </div>
      {actionLabel && onAction && (
        <button
          type="button"
          onClick={onAction}
          className="inline-flex shrink-0 cursor-pointer items-center gap-1 text-[13px] font-medium link-neutral underline-offset-4"
        >
          {actionLabel}
          <ChevronRight className="h-3.5 w-3.5" />
        </button>
      )}
    </div>
  )
}

function AppGrid({
  apps,
  onAppClick,
  columns = 'default',
}: {
  apps: MarketplaceApp[]
  onAppClick: (app: MarketplaceApp) => void
  columns?: 'default' | 'compact'
}) {
  return (
    <div
      className={
        columns === 'compact'
          ? RESOURCE_CARD_GRID_2_COL_CLASSNAME
          : RESOURCE_CARD_GRID_WIDE_CLASSNAME
      }
    >
      {apps.map((app) => (
        <MarketplaceAppCard key={app.$id} app={app} onClick={() => onAppClick(app)} />
      ))}
    </div>
  )
}

export function MarketplaceExplore({
  catalogApps,
  featuredApps,
  moreApps,
  categoryCounts,
  onAppClick,
  onNavigate,
}: MarketplaceExploreProps) {
  const t = useT()
  const showViewAllCatalog =
    catalogApps.length > featuredApps.length + moreApps.length

  return (
    <div className="space-y-10 [&>section:not(:first-child)]:pt-4">
      {featuredApps.length > 0 && (
        <section>
          <AppGrid apps={featuredApps} onAppClick={onAppClick} />
        </section>
      )}

      <section>
        <SectionHeader
          title={t('More apps')}
          description={t('Popular integrations from the marketplace catalog.')}
          actionLabel={showViewAllCatalog ? t('View all apps') : undefined}
          onAction={
            showViewAllCatalog ? () => onNavigate('catalog') : undefined
          }
        />
        {moreApps.length === 0 ? (
          <p className="text-[13px] text-muted-foreground py-4">
            {t('No additional apps in the catalog yet.')}
          </p>
        ) : (
          <AppGrid apps={moreApps} onAppClick={onAppClick} />
        )}
      </section>

      <section>
        <SectionHeader
          title={t('Browse by category')}
          description={t(
            'Find integrations grouped by what they help you build.',
          )}
        />
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
          {MARKETPLACE_CATEGORY_ORDER.map((category) => {
            const Icon = MARKETPLACE_CATEGORY_ICONS[category]
            const count = categoryCounts[category]
            return (
              <button
                key={category}
                type="button"
                onClick={() => onNavigate(`category:${category}`)}
                className={cn(
                  'group flex cursor-pointer flex-col items-start gap-3 rounded-lg border border-border bg-card p-4 text-start transition-all',
                  'hover:border-border hover:bg-accent/50',
                )}
              >
                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                  <Icon className="h-5 w-5" />
                </div>
                <div className="min-w-0 w-full">
                  <p className="text-[13px] font-medium text-foreground truncate">
                    {t(MARKETPLACE_CATEGORY_LABELS[category])}
                  </p>
                  <p className="text-[12px] text-muted-foreground mt-0.5">
                    {count} {count !== 1 ? t('apps') : t('app')}
                  </p>
                </div>
              </button>
            )
          })}
        </div>
      </section>
    </div>
  )
}
