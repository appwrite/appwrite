import { Link, useNavigate } from '@tanstack/react-router'
import { Search } from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'
import {
  MarketingHeroSection,
} from '@/components/pages/marketing/MarketingSections'
import { Input } from '@/components/ui/input'
import {
  buildIntegrationsRouteSearch,
  hasActiveIntegrationFilters,
} from '@/lib/integrations/search'
import type {
  IntegrationCategoryGroup,
  IntegrationMeta,
  IntegrationsCatalog,
  IntegrationsSearch,
} from '@/lib/integrations/types'
import { IntegrationCard } from './IntegrationCard'
import { IntegrationPartnerNote } from './IntegrationPartnerNote'
import { integrationPillClassName } from './IntegrationPill'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

type ViewProps = IntegrationsCatalog & {
  filtered: IntegrationMeta[]
  search?: IntegrationsSearch
}

function FilterPill({
  active,
  onClick,
  children,
}: {
  active: boolean
  onClick: () => void
  children: React.ReactNode
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={integrationPillClassName({ active })}
    >
      {children}
    </button>
  )
}

function CategorySection({ group }: { group: IntegrationCategoryGroup }) {
  const t = useT()
  return (
    <section id={group.category} className="scroll-mt-24">
      <div className="border-b border-border pb-4">
        <h2 className="font-aeonik-pro text-[22px] font-normal text-foreground">
          {t(group.heading)}
        </h2>
        <p className="mt-2 text-[13px] text-muted-foreground">{t(group.description)}</p>
      </div>
      <div className="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        {group.integrations.map((integration) => (
          <IntegrationCard key={integration.slug} integration={integration} />
        ))}
      </div>
    </section>
  )
}

export function View({
  featured,
  categories,
  platforms,
  grouped,
  filtered,
  search,
}: ViewProps) {
  const t = useT()
  const navigate = useNavigate()
  const searchDebounceRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const [query, setQuery] = useState(search?.search ?? '')
  const selectedCategory = search?.category ?? 'all'
  const selectedPlatform = search?.platform ?? 'all'
  const urlSearch = search?.search ?? ''
  const filtersActive = hasActiveIntegrationFilters(search ?? {})

  useEffect(() => {
    setQuery(urlSearch)
  }, [urlSearch])

  const applyFilters = useCallback(
    (next: IntegrationsSearch) => {
      navigate({
        to: '/integrations',
        search: () => buildIntegrationsRouteSearch(next),
        replace: true,
      })
    },
    [navigate],
  )

  useEffect(() => {
    if (searchDebounceRef.current) clearTimeout(searchDebounceRef.current)
    searchDebounceRef.current = setTimeout(() => {
      const trimmed = query.trim()
      if (trimmed === urlSearch) return
      applyFilters({
        search: trimmed,
        category: selectedCategory,
        platform: selectedPlatform,
      })
    }, 300)
    return () => {
      if (searchDebounceRef.current) clearTimeout(searchDebounceRef.current)
    }
  }, [query, urlSearch, selectedCategory, selectedPlatform, applyFilters])

  const showFeatured = featured.length > 0 && !filtersActive
  const showGrouped = !filtersActive

  return (
    <div className="relative overflow-x-hidden bg-background">
      <MarketingHeroSection
        eyebrow={t('Integrations')}
        title={t('Discover infinite possibilities')}
        description={t(
          "Find your favourite apps to integrate with your projects in Appwrite's marketplace.", // pragma: allowlist secret
        )}
        align="left"
      />

      <section className="border-b border-border py-10 sm:py-14">
        <div className="mx-auto max-w-7xl px-4 sm:px-6">
          <div className="grid gap-8 lg:grid-cols-[240px_minmax(0,1fr)] lg:gap-12">
            <aside className="flex flex-col gap-6 lg:sticky lg:top-24 lg:self-start">
              <div>
                <label htmlFor="integration-search" className="sr-only">
                  {t('Search integrations...')}
                </label>
                <div className="relative">
                  <Search className="pointer-events-none absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                  <Input
                    id="integration-search"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    placeholder={t('Search integrations...')}
                    className="h-10 ps-9 text-[13px]"
                  />
                </div>
              </div>

              <div>
                <h2 className="text-[11px] font-semibold uppercase tracking-[0.2em] text-muted-foreground">
                  {t('Platform')}
                </h2>
                <div className="mt-3 flex flex-wrap gap-2">
                  <FilterPill
                    active={selectedPlatform === 'all'}
                    onClick={() =>
                      applyFilters({
                        search: query,
                        category: selectedCategory,
                        platform: 'all',
                      })
                    }
                  >
                    {t('All')}
                  </FilterPill>
                  {platforms.map((platform) => (
                    <FilterPill
                      key={platform}
                      active={selectedPlatform === platform}
                      onClick={() =>
                        applyFilters({
                          search: query,
                          category: selectedCategory,
                          platform,
                        })
                      }
                    >
                      {platform}
                    </FilterPill>
                  ))}
                </div>
              </div>

              <div>
                <h2 className="text-[11px] font-semibold uppercase tracking-[0.2em] text-muted-foreground">
                  {t('Category')}
                </h2>
                <div className="mt-3 flex flex-wrap gap-2">
                  <Link
                    to="/integrations"
                    search={() => buildIntegrationsRouteSearch({ search: query, platform: selectedPlatform })}
                    className={cn(integrationPillClassName({ active: selectedCategory === 'all' }), 'link-unstyled')}
                  >
                    {t('All categories')}
                  </Link>
                  {categories.map((category) => (
                    <Link
                      key={category.slug}
                      to="/integrations"
                      search={() =>
                        buildIntegrationsRouteSearch({
                          search: query,
                          category: category.slug,
                          platform: selectedPlatform,
                        })
                      }
                      className={cn(
                        integrationPillClassName({
                          active: selectedCategory === category.slug,
                        }),
                        'link-unstyled',
                      )}
                    >
                      {t(category.heading)}
                    </Link>
                  ))}
                </div>
              </div>
            </aside>

            <div className="min-w-0 space-y-12">
              {filtersActive ? (
                <div>
                  <div className="border-b border-border pb-4">
                    <h2 className="font-aeonik-pro text-[22px] font-normal text-foreground">
                      {filtered.length} {filtered.length === 1 ? t('result') : t('results')}
                    </h2>
                    <p className="mt-2 text-[13px] text-muted-foreground">
                      {filtered.length === 0
                        ? t('Try adjusting or clearing your filters.')
                        : t('Integrations matching your search and filters.')}
                    </p>
                  </div>
                  {filtered.length > 0 ? (
                    <div className="mt-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-2">
                      {filtered.map((integration) => (
                        <IntegrationCard key={integration.slug} integration={integration} />
                      ))}
                    </div>
                  ) : null}
                </div>
              ) : null}

              {showFeatured ? (
                <section>
                  <div className="border-b border-border pb-4">
                    <h2 className="font-aeonik-pro text-[22px] font-normal text-foreground">
                      {t('Featured')}
                    </h2>
                    <p className="mt-2 text-[13px] text-muted-foreground">
                      {t('Popular integrations to get started quickly.')}
                    </p>
                  </div>
                  <div className="mt-6 flex flex-col gap-4">
                    {featured.map((integration) => (
                      <IntegrationCard
                        key={integration.slug}
                        integration={integration}
                        variant="featured"
                      />
                    ))}
                  </div>
                </section>
              ) : null}

              {showGrouped
                ? grouped.map((group) => <CategorySection key={group.category} group={group} />)
                : null}
            </div>
          </div>
        </div>
      </section>

      <IntegrationPartnerNote />
    </div>
  )
}
