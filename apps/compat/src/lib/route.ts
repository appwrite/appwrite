import { notFound } from '@tanstack/react-router'
import { loadLib } from './report'

/** A library's report for a route loader; a 404 when it is not in the report. */
export async function requireLib(lib: string) {
  const detail = await loadLib(lib)
  if (!detail) throw notFound()
  return detail
}

/** Search params shared by the filterable lists: text, a filter and how many rows show. */
export interface ListSearch {
  q?: string
  filter?: string
  limit?: number
}

export function listSearch(search: Record<string, unknown>): ListSearch {
  const limit = Number(search.limit)
  return {
    q: typeof search.q === 'string' && search.q ? search.q : undefined,
    filter: typeof search.filter === 'string' && search.filter !== 'all' ? search.filter : undefined,
    limit: Number.isFinite(limit) && limit > 0 ? limit : undefined,
  }
}
