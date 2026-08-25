import { queryOptions } from '@tanstack/react-query'
import { Query } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { buildAttributePrefixSearchQueries } from '@/lib/appwrite-id'
import {
  USERS_DEFAULT_SORT_BY,
  USERS_DEFAULT_SORT_ORDER,
} from '@/lib/react-query/hooks/users'

const CONSOLE_USERS_SEARCH_LIMIT = 25

/**
 * Console SDK user list (project = console) for operator impersonation picker.
 * Uses query `or(startsWith(name|email|phone|$id))` instead of the list `search` param.
 */
export async function fetchConsoleUsersSearch(search: string) {
  const trimmed = search?.trim() ?? ''
  const sortOrder = USERS_DEFAULT_SORT_ORDER as 'asc' | 'desc'
  const orderQuery =
    sortOrder === 'asc'
      ? Query.orderAsc(USERS_DEFAULT_SORT_BY)
      : Query.orderDesc(USERS_DEFAULT_SORT_BY)

  const queries: string[] = [
    ...buildAttributePrefixSearchQueries(
      ['name', 'email', 'phone', '$id'],
      trimmed,
    ),
    orderQuery,
    Query.limit(CONSOLE_USERS_SEARCH_LIMIT),
    Query.offset(0),
  ]

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
