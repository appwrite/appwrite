import { queryOptions } from '@tanstack/react-query'
import { Query } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  USERS_DEFAULT_SORT_BY,
  USERS_DEFAULT_SORT_ORDER,
} from '@/lib/react-query/hooks/users'

const CONSOLE_USERS_SEARCH_LIMIT = 25

/** Max length for prefix passed to startsWith (API query size limits). */
const MAX_PREFIX_LENGTH = 128

function buildConsoleUserPrefixQueries(trimmed: string): string[] {
  const prefix = trimmed.slice(0, MAX_PREFIX_LENGTH)
  return [
    Query.or([
      Query.startsWith('name', prefix),
      Query.startsWith('email', prefix),
      Query.startsWith('phone', prefix),
      Query.startsWith('$id', prefix),
    ]),
  ]
}

/**
 * Console SDK user list (project = console) for operator impersonation picker.
 * Uses query `or(startsWith(name|email|phone|$id))` instead of the list `search` param.
 */
export async function fetchConsoleUsersSearch(search: string) {
  const trimmed = search?.trim() ?? ''
  const sortOrder = USERS_DEFAULT_SORT_ORDER
  const orderQuery =
    sortOrder === 'asc'
      ? Query.orderAsc(USERS_DEFAULT_SORT_BY)
      : Query.orderDesc(USERS_DEFAULT_SORT_BY)

  const queries: string[] = []
  if (trimmed.length > 0) {
    queries.push(...buildConsoleUserPrefixQueries(trimmed))
  }
  queries.push(
    orderQuery,
    Query.limit(CONSOLE_USERS_SEARCH_LIMIT),
    Query.offset(0),
  )

  return sdk.forConsole.users.list({
    queries,
  })
}

export function consoleUsersImpersonationSearchQueryOptions(
  debouncedSearch: string,
) {
  const trimmed = debouncedSearch.trim()
  return queryOptions({
    queryKey: ['console', 'users', 'impersonation-search', trimmed],
    queryFn: () => fetchConsoleUsersSearch(debouncedSearch),
    staleTime: 30 * 1000,
    retry: false,
  })
}
