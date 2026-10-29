import type { IntegrationsSearch } from './types'

export function buildIntegrationsRouteSearch(
  search: IntegrationsSearch,
): IntegrationsSearch {
  const next: IntegrationsSearch = {}

  const query = search.search?.trim()
  if (query) next.search = query

  const category = search.category?.trim()
  if (category && category !== 'all') next.category = category

  const platform = search.platform?.trim()
  if (platform && platform !== 'all') next.platform = platform

  return next
}

export function hasActiveIntegrationFilters(search: IntegrationsSearch): boolean {
  return Boolean(
    search.search?.trim() ||
      (search.category && search.category !== 'all') ||
      (search.platform && search.platform !== 'all'),
  )
}
