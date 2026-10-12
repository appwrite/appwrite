export type BlogRouteSearch = {
  search?: string
  category?: string
}

export function buildBlogRouteSearch(options: {
  search?: string
  category?: string
}): BlogRouteSearch {
  const next: BlogRouteSearch = {}
  const trimmedSearch = options.search?.trim()

  if (trimmedSearch) {
    next.search = trimmedSearch
  }

  if (options.category && options.category !== 'Latest') {
    next.category = options.category
  }

  return next
}
