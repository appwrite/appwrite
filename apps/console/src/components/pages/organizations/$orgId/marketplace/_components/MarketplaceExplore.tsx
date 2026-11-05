import {
  MARKETPLACE_CATEGORY_ICONS,
  MARKETPLACE_CATEGORY_LABELS,
  formatMarketplaceCount,
  type MarketplaceAppCategory,
} from '@/lib/marketplace/types'
import {
  MARKETPLACE_CATEGORY_ORDER,
  type MarketplaceNavId,
} from '@/lib/marketplace/marketplace-nav'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

type MarketplaceExploreProps = {
  /** Server totals per category; null while loading. */
  categoryCounts: Record<MarketplaceAppCategory, number> | null
  onNavigate: (navId: MarketplaceNavId) => void
}

/** "Browse by category" tiles shown under the Explore app list. */
export function MarketplaceExplore({
  categoryCounts,
  onNavigate,
}: MarketplaceExploreProps) {
  const t = useT()

  return (
    <section className="pt-4">
      <div className="mb-4 min-w-0">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Browse by category')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-1">
          {t('Find integrations grouped by what they help you build.')}
        </p>
      </div>
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
        {MARKETPLACE_CATEGORY_ORDER.map((category) => {
          const Icon = MARKETPLACE_CATEGORY_ICONS[category]
          const count = categoryCounts?.[category]
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
                  {count === undefined
                    ? ' '
                    : `${formatMarketplaceCount(count)} ${count !== 1 ? t('apps') : t('app')}`}
                </p>
              </div>
            </button>
          )
        })}
      </div>
    </section>
  )
}
