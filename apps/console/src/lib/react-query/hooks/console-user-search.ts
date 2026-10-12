import { queryOptions } from '@tanstack/react-query'
import { Query, type Models } from '@appwrite.io/console'
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

/**
 * Console users by id. Fills in name/email for recent impersonation targets whose
 * labels are not cached in this browser (account prefs only keep the IDs).
 */
export function consoleUsersByIdQueryOptions(userIds: string[]) {
  return queryOptions({
    queryKey: ['console', 'users', 'by-id', userIds],
    queryFn: () =>
      sdk.forConsole.users.list({
        queries: [Query.equal('$id', userIds), Query.limit(userIds.length)],
      }),
    staleTime: 5 * 60 * 1000,
    retry: false,
  })
}

/** How a `/impersonate` deep link identifies the target: console user id or email. */
export type ConsoleImpersonationTargetLookup =
  | { userId: string; email?: undefined }
  | { email: string; userId?: undefined }

/**
 * Target console user for the `/impersonate` deep links. Support notes usually
 * carry the requester's email rather than their console user id, so both are
 * accepted. Shared by the route loaders and the View so the first paint has data.
 * Resolves to `null` when no console user matches an email.
 */
export function consoleImpersonationTargetQueryOptions(
  lookup: ConsoleImpersonationTargetLookup,
) {
  const email = lookup.email?.trim().toLowerCase() ?? ''
  const userId = lookup.userId ?? ''
  return queryOptions({
    queryKey: [
      'console',
      'users',
      'impersonation-target',
      userId ? 'id' : 'email',
      userId || email,
    ],
    queryFn: async (): Promise<Models.User | null> => {
      if (userId) return sdk.forConsole.users.get(userId)
      const list = await sdk.forConsole.users.list({
        queries: [Query.equal('email', [email]), Query.limit(1)],
      })
      return list.users[0] ?? null
    },
    staleTime: 30 * 1000,
    retry: false,
  })
}
