/**
 * React Query hooks for Auth Security Features
 *
 * Handles auth limits, sessions, passwords, and MFA.
 */

import { useCallback, useMemo } from 'react'
import {
  useMutation,
  useQuery,
  useQueryClient,
  queryOptions,
  type QueryClient,
} from '@tanstack/react-query'
import { MethodId } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  buildDatabasesSidebarWidthPrefs,
  buildSavedFiltersPrefs,
  buildSavedImageTransformPresetsPrefs,
  DATABASES_SIDEBAR_WIDTH_DEFAULT_PERCENT,
  parseDatabasesSidebarWidthPercent,
  parseSavedFilters,
  parseSavedImageTransformPresets,
  MAX_SAVED_FILTER_NAME_LENGTH,
  MAX_SAVED_IMAGE_TRANSFORM_PRESET_JSON_CHARS,
  MAX_SAVED_IMAGE_TRANSFORM_PRESET_NAME_LENGTH,
  MAX_SAVED_IMAGE_TRANSFORM_PRESETS,
  clearRecentImpersonationSessionList,
  mergeRecentImpersonationIntoAccountPrefs,
  mergeRecentImpersonationLists,
  mergeTablesDbRowsListColumnsIntoPrefs,
  parseRecentImpersonationUsers,
  parseTablesDbRowsListColumnsFromPrefs,
  readRecentImpersonationSessionList,
  type UserPrefs,
} from '@/lib/user-prefs-keys'
import type {
  SavedFilter,
  SavedImageTransformPreset,
} from '@/lib/user-prefs-keys'
import { DEFAULT_STALE_TIME } from './constants'
import { useConsoleTeam, useUpdateConsoleTeamPrefs } from './teams'

export type ConsoleAccountCache = { prefs?: Record<string, unknown> }

/**
 * Account is cached under `['account', 'console', consoleImpersonationRevision]`.
 * `getQueryData(['account', 'console'])` never matches - use prefix query (see useSidebarCollapsed).
 */
export function getConsoleAccountFromCache(
  queryClient: QueryClient,
): ConsoleAccountCache | undefined {
  const rows = queryClient.getQueriesData<ConsoleAccountCache>({
    queryKey: ['account', 'console'],
  })
  for (const [, data] of rows) {
    if (data) return data
  }
  return undefined
}

// ============================================================================
// AUTH SECURITY FEATURES
// ============================================================================

/**
 * Hook to update project users limit
 *
 * @param projectId - The project ID
 */
export function useUpdateAuthLimit(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (limit: number) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      if (limit !== 0 && (limit < 1 || limit > 10000)) {
        throw new Error('Limit must be 0 (unlimited) or between 1 and 10,000')
      }

      return await sdk.forConsole.projects.updateAuthLimit({
        projectId,
        limit,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['project', projectId],
      })
    },
  })
}

/**
 * Hook to update project auth duration (session length)
 *
 * @param projectId - The project ID
 */
export function useUpdateAuthDuration(projectId: string | null | undefined) {
  const queryClient = useQueryClient()
  const MAX_DURATION_SECONDS = 31_536_000 // 1 year in seconds

  return useMutation({
    mutationFn: async (duration: number) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      if (duration < 0 || duration > MAX_DURATION_SECONDS) {
        throw new Error(
          `Duration must be between 0 and ${MAX_DURATION_SECONDS} seconds (1 year)`,
        )
      }

      // Clamp the value to ensure it's within valid range
      const clampedDuration = Math.max(
        0,
        Math.min(duration, MAX_DURATION_SECONDS),
      )

      return await sdk.forConsole.projects.updateAuthDuration({
        projectId,
        duration: clampedDuration, // Duration in seconds
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['project', projectId],
      })
    },
  })
}

/**
 * Hook to update project sessions limit
 *
 * @param projectId - The project ID
 */
export function useUpdateAuthSessionsLimit(
  projectId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (limit: number) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      if (limit < 1 || limit > 100) {
        throw new Error('Limit must be between 1 and 100')
      }

      return await sdk.forConsole.projects.updateAuthSessionsLimit({
        projectId,
        limit,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['project', projectId],
      })
    },
  })
}

/**
 * Hook to update project password history limit
 *
 * @param projectId - The project ID
 */
export function useUpdateAuthPasswordHistory(
  projectId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (limit: number) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      if (limit !== 0 && (limit < 1 || limit > 20)) {
        throw new Error('Limit must be 0 (disabled) or between 1 and 20')
      }

      return await sdk.forConsole.projects.updateAuthPasswordHistory({
        projectId,
        limit,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['project', projectId],
      })
    },
  })
}

/**
 * Hook to update project password dictionary check
 *
 * @param projectId - The project ID
 */
export function useUpdateAuthPasswordDictionary(
  projectId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (enabled: boolean) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }

      return await sdk.forConsole.projects.updateAuthPasswordDictionary({
        projectId,
        enabled,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['project', projectId],
      })
    },
  })
}

/**
 * Hook to update project personal data check
 *
 * @param projectId - The project ID
 */
export function useUpdatePersonalDataCheck(
  projectId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (enabled: boolean) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }

      return await sdk.forConsole.projects.updatePersonalDataCheck({
        projectId,
        enabled,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['project', projectId],
      })
    },
  })
}

/**
 * Hook to update project session alerts
 *
 * @param projectId - The project ID
 */
export function useUpdateSessionAlerts(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (alerts: boolean) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }

      return await sdk.forConsole.projects.updateSessionAlerts({
        projectId,
        alerts,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['project', projectId],
      })
    },
  })
}

/**
 * Hook to update project session invalidation
 *
 * @param projectId - The project ID
 */
export function useUpdateSessionInvalidation(
  projectId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (enabled: boolean) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }

      return await sdk.forConsole.projects.updateSessionInvalidation({
        projectId,
        enabled,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['project', projectId],
      })
    },
  })
}

/**
 * Hook to update project mock phone numbers
 *
 * @param projectId - The project ID
 */
export function useUpdateMockNumbers(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (numbers: Array<{ phone: string; otp: string }>) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      if (numbers.length > 10) {
        throw new Error('Maximum 10 mock phone numbers allowed')
      }

      return await sdk.forConsole.projects.updateMockNumbers({
        projectId,
        numbers,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['project', projectId],
      })
    },
  })
}

/**
 * Hook to update project memberships privacy
 *
 * @param projectId - The project ID
 */
export function useUpdateMembershipsPrivacy(
  projectId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (privacy: {
      userName: boolean
      userEmail: boolean
      mfa: boolean
    }) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }

      return await sdk.forConsole.projects.updateMembershipsPrivacy({
        projectId,
        userName: privacy.userName,
        userEmail: privacy.userEmail,
        mfa: privacy.mfa,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['project', projectId],
      })
    },
  })
}

// ============================================================================
// AUTH METHODS & OAUTH PROVIDERS
// ============================================================================

/**
 * Hook to update project auth method status
 *
 * @param projectId - The project ID
 */
export function useUpdateAuthMethod(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({
      method,
      status,
    }: {
      method: string
      status: boolean
    }) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }

      return await sdk.forProject(projectId).project.updateAuthMethod({
        methodId: method as MethodId,
        enabled: status,
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['project', projectId],
      })
    },
  })
}

// ============================================================================
// ACCOUNT & MFA
// ============================================================================

/**
 * Query function to fetch MFA factors
 *
 * This is extracted so it can be reused in both hooks and route loaders.
 */
export async function fetchMFAFactors() {
  const response = await sdk.forConsole.account.listMFAFactors()
  return response
}

/**
 * Query options for fetching MFA factors
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function mfaFactorsQueryOptions() {
  return queryOptions({
    queryKey: ['factors', 'account'],
    queryFn: fetchMFAFactors,
    staleTime: DEFAULT_STALE_TIME,
    retry: false, // Don't retry on error
    refetchOnMount: false, // Data is prefetched in route loader, no need to refetch on mount
    refetchOnWindowFocus: false, // Prevent refetch when switching tabs/windows
    refetchOnReconnect: false, // Prevent refetch on network reconnect
  })
}

/**
 * Hook to fetch MFA factors
 */
export function useMFAFactors() {
  return useQuery(mfaFactorsQueryOptions())
}

/**
 * Query function to fetch account identities
 *
 * This is extracted so it can be reused in both hooks and route loaders.
 */
export async function fetchAccountIdentities() {
  const response = await sdk.forConsole.account.listIdentities()
  return {
    identities: response.identities || [],
    total: response.total || 0,
  }
}

/**
 * Query function to fetch account sessions
 *
 * This is extracted so it can be reused in both hooks and route loaders.
 */
export async function fetchAccountSessions() {
  const response = await sdk.forConsole.account.listSessions()
  return {
    sessions: response.sessions || [],
    total: response.total || 0,
  }
}

// ============================================================================
// QUERY OPTIONS
// ============================================================================

/**
 * Query options for fetching account identities
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function accountIdentitiesQueryOptions() {
  return queryOptions({
    queryKey: ['identities', 'account'],
    queryFn: fetchAccountIdentities,
    staleTime: DEFAULT_STALE_TIME,
    retry: false, // Don't retry on error
    refetchOnMount: false, // Data is prefetched in route loader, no need to refetch on mount
    refetchOnWindowFocus: false, // Prevent refetch when switching tabs/windows
    refetchOnReconnect: false, // Prevent refetch on network reconnect
  })
}

/**
 * Query options for fetching account sessions
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function accountSessionsQueryOptions() {
  return queryOptions({
    queryKey: ['sessions', 'account'],
    queryFn: fetchAccountSessions,
    staleTime: DEFAULT_STALE_TIME,
    retry: false, // Don't retry on error
    refetchOnMount: false, // Data is prefetched in route loader, no need to refetch on mount
    refetchOnWindowFocus: false, // Prevent refetch when switching tabs/windows
    refetchOnReconnect: false, // Prevent refetch on network reconnect
  })
}

// ============================================================================
// HOOKS
// ============================================================================

/**
 * Hook to fetch account identities
 */
export function useAccountIdentities() {
  return useQuery(accountIdentitiesQueryOptions())
}

/**
 * Hook to fetch account sessions
 */
export function useAccountSessions() {
  return useQuery(accountSessionsQueryOptions())
}

// ============================================================================
// ACCOUNT PREFERENCES - FEATURE NOTIFICATIONS
// ============================================================================

/**
 * Mutation function to update account preferences
 */
export async function updateAccountPrefs(prefs: Record<string, unknown>) {
  return await sdk.forConsole.account.updatePrefs({ prefs })
}

/**
 * Merge session-stored recent impersonation targets (while operator was impersonating)
 * into the operator account prefs. Call after impersonation headers are cleared so
 * `account.get()` resolves to the operator.
 */
export async function flushRecentImpersonationUsersToAccountPrefs(
  operatorId: string,
) {
  const list = readRecentImpersonationSessionList(operatorId)
  if (list.length === 0) return
  clearRecentImpersonationSessionList(operatorId)
  const account = await sdk.forConsole.account.get()
  const fromPrefs = parseRecentImpersonationUsers(account.prefs as UserPrefs)
  const merged = mergeRecentImpersonationLists(fromPrefs, list)
  await updateAccountPrefs(
    mergeRecentImpersonationIntoAccountPrefs(
      account.prefs as UserPrefs,
      merged,
    ),
  )
}

/**
 * Hook to toggle a feature notification preference
 *
 * Manages the 'featureNotifications' string in user preferences.
 * Uses a comma-separated string to store all feature IDs.
 * If the feature ID exists, it removes it. If it doesn't exist, it adds it.
 */
export function useToggleFeatureNotification() {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (featureId: string) => {
      // Get current account data from cache (key includes consoleImpersonationRevision)
      const account = getConsoleAccountFromCache(queryClient)

      if (!account) {
        throw new Error('Account data not available')
      }

      // Get current feature notifications (handle both string and legacy array formats)
      const currentNotificationsRaw = account.prefs?.featureNotifications

      // Parse into an array, handling different data types
      let currentNotifications: string[] = []
      if (typeof currentNotificationsRaw === 'string') {
        currentNotifications = currentNotificationsRaw
          ? currentNotificationsRaw.split(',').filter(Boolean)
          : []
      } else if (Array.isArray(currentNotificationsRaw)) {
        // Handle legacy array format
        currentNotifications = currentNotificationsRaw
      }

      // Toggle: if feature exists, remove it; otherwise add it
      const updatedNotifications = currentNotifications.includes(featureId)
        ? currentNotifications.filter((id: string) => id !== featureId)
        : [...currentNotifications, featureId]

      // Convert back to comma-separated string
      const updatedNotificationsStr = updatedNotifications.join(',')

      // Update preferences with the new string
      const updatedPrefs = {
        ...account.prefs,
        featureNotifications: updatedNotificationsStr,
      }

      return await updateAccountPrefs(updatedPrefs)
    },
    onSuccess: () => {
      // Invalidate account query to refetch with new prefs
      queryClient.invalidateQueries({ queryKey: ['account', 'console'] })
    },
  })
}

// ============================================================================
// SIDEBAR COLLAPSED PREFERENCE
// ============================================================================

/**
 * Hook to manage navigation sidebar collapsed state persisted in account preferences.
 * Reads from account.prefs.sidebarCollapsed and persists on toggle.
 *
 * Must be used within RequireAuth (or where account is available).
 */
export function useSidebarCollapsed(
  account: { prefs?: Record<string, unknown> } | undefined,
) {
  const queryClient = useQueryClient()

  const accountPrefs = account?.prefs
  const collapsed =
    accountPrefs?.sidebarCollapsed === true ||
    accountPrefs?.sidebarCollapsed === 'true'

  const updateMutation = useMutation({
    mutationFn: async (value: boolean) => {
      if (!account) {
        throw new Error('Account data not available')
      }
      return await updateAccountPrefs({
        ...account.prefs,
        sidebarCollapsed: value,
      })
    },
    // The auth query key includes consoleImpersonationRevision, so an exact
    // ['account', 'console'] lookup misses it. Use prefix matching to update
    // every cached variant optimistically.
    onMutate: async (value) => {
      queryClient.setQueriesData<{ prefs?: Record<string, unknown> }>(
        { queryKey: ['account', 'console'] },
        (current) =>
          current
            ? {
                ...current,
                prefs: { ...current.prefs, sidebarCollapsed: value },
              }
            : current,
      )
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['account', 'console'] })
    },
  })

  const setCollapsed = useCallback(
    (value: boolean | ((prev: boolean) => boolean)) => {
      const nextValue = typeof value === 'function' ? value(collapsed) : value
      updateMutation.mutate(nextValue)
    },
    [collapsed, updateMutation],
  )

  return { collapsed, setCollapsed }
}

// ============================================================================
// DATABASES SIDEBAR WIDTH PREFERENCE
// ============================================================================

/**
 * Hook to manage the persisted width (percent) of the tables sidebar inside
 * the database detail view. Persisted in `account.prefs` under
 * `console.databases.sidebarWidth` so the value follows the user across
 * devices and browsers and is shared across all databases/tables.
 *
 * Reads synchronously from the cached account, writes asynchronously through
 * `account.updatePrefs` with optimistic cache updates (matches the pattern of
 * `useSidebarCollapsed`).
 *
 * Must be used within RequireAuth (or where the account query is loaded).
 */
export function useDatabasesSidebarWidth(
  account: { prefs?: Record<string, unknown> } | undefined,
) {
  const queryClient = useQueryClient()

  const widthPercent =
    parseDatabasesSidebarWidthPercent(account?.prefs) ??
    DATABASES_SIDEBAR_WIDTH_DEFAULT_PERCENT

  const updateMutation = useMutation({
    mutationFn: async (value: number) => {
      if (!account) {
        throw new Error('Account data not available')
      }
      return await updateAccountPrefs({
        ...account.prefs,
        ...buildDatabasesSidebarWidthPrefs(value),
      })
    },
    // The auth query key includes consoleImpersonationRevision, so an exact
    // ['account', 'console'] lookup misses it. Use prefix matching to update
    // every cached variant optimistically.
    onMutate: async (value) => {
      queryClient.setQueriesData<{ prefs?: Record<string, unknown> }>(
        { queryKey: ['account', 'console'] },
        (current) =>
          current
            ? {
                ...current,
                prefs: {
                  ...current.prefs,
                  ...buildDatabasesSidebarWidthPrefs(value),
                },
              }
            : current,
      )
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['account', 'console'] })
    },
  })

  const setWidthPercent = useCallback(
    (value: number) => {
      updateMutation.mutate(value)
    },
    [updateMutation],
  )

  return { widthPercent, setWidthPercent }
}

// ============================================================================
// SAVED FILTERS (USER AND TEAM PREFERENCES)
// ============================================================================

export type SavedFilterLevel = 'user' | 'team'

/**
 * Hook to read and update saved filter presets for a view scope.
 * User filters: account prefs (console.savedFilters.<scope>).
 * Team filters: team/org prefs (same key), when teamId is provided.
 *
 * Must be used within RequireAuth (account required).
 */
export function useSavedFilters(
  scope: string | null | undefined,
  account: { prefs?: Record<string, unknown> } | undefined,
  teamId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  const { data: team } = useConsoleTeam(teamId)
  const updateTeamPrefs = useUpdateConsoleTeamPrefs(teamId)

  const userSavedFilters: SavedFilter[] =
    scope && account?.prefs ? parseSavedFilters(account.prefs, scope) : []

  const teamSavedFilters: SavedFilter[] =
    scope && team?.prefs && teamId
      ? parseSavedFilters(team.prefs as Record<string, unknown>, scope)
      : []

  const addUserMutation = useMutation({
    mutationFn: async ({
      name,
      query,
      sort,
    }: {
      name: string
      query: string
      sort?: string
    }) => {
      const currentAccount = getConsoleAccountFromCache(queryClient)
      if (!currentAccount || !scope) {
        throw new Error('Account or filter scope not available')
      }
      const current = parseSavedFilters(currentAccount.prefs, scope)
      const trimmedName = name.trim().slice(0, MAX_SAVED_FILTER_NAME_LENGTH)
      if (!trimmedName) throw new Error('Name is required')
      const newFilter: SavedFilter = {
        id: crypto.randomUUID(),
        name: trimmedName,
        query,
        ...(sort ? { sort } : {}),
      }
      const next = [newFilter, ...current]
      return await updateAccountPrefs({
        ...currentAccount.prefs,
        ...buildSavedFiltersPrefs(scope, next),
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['account', 'console'] })
    },
  })

  const addTeamMutation = useMutation({
    mutationFn: async ({
      name,
      query,
      sort,
    }: {
      name: string
      query: string
      sort?: string
    }) => {
      const currentTeam = queryClient.getQueryData<{
        prefs?: Record<string, unknown>
      }>(['team', 'console', teamId])
      if (!currentTeam || !scope || !teamId) {
        throw new Error('Team or filter scope not available')
      }
      const current = parseSavedFilters(
        currentTeam.prefs as Record<string, unknown>,
        scope,
      )
      const trimmedName = name.trim().slice(0, MAX_SAVED_FILTER_NAME_LENGTH)
      if (!trimmedName) throw new Error('Name is required')
      const newFilter: SavedFilter = {
        id: crypto.randomUUID(),
        name: trimmedName,
        query,
        ...(sort ? { sort } : {}),
      }
      const next = [newFilter, ...current]
      await updateTeamPrefs.mutateAsync({
        ...(currentTeam.prefs as Record<string, unknown>),
        ...buildSavedFiltersPrefs(scope, next),
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['team', 'console', teamId],
      })
    },
  })

  const deleteUserMutation = useMutation({
    mutationFn: async (id: string) => {
      const currentAccount = getConsoleAccountFromCache(queryClient)
      if (!currentAccount || !scope) {
        throw new Error('Account or filter scope not available')
      }
      const current = parseSavedFilters(currentAccount.prefs, scope)
      const next = current.filter((f) => f.id !== id)
      return await updateAccountPrefs({
        ...currentAccount.prefs,
        ...buildSavedFiltersPrefs(scope, next),
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['account', 'console'] })
    },
  })

  const deleteTeamMutation = useMutation({
    mutationFn: async (id: string) => {
      const currentTeam = queryClient.getQueryData<{
        prefs?: Record<string, unknown>
      }>(['team', 'console', teamId])
      if (!currentTeam || !scope || !teamId) {
        throw new Error('Team or filter scope not available')
      }
      const current = parseSavedFilters(
        currentTeam.prefs as Record<string, unknown>,
        scope,
      )
      const next = current.filter((f) => f.id !== id)
      await updateTeamPrefs.mutateAsync({
        ...(currentTeam.prefs as Record<string, unknown>),
        ...buildSavedFiltersPrefs(scope, next),
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['team', 'console', teamId],
      })
    },
  })

  const reorderUserMutation = useMutation({
    mutationFn: async (orderedFilters: SavedFilter[]) => {
      const currentAccount = getConsoleAccountFromCache(queryClient)
      if (!currentAccount || !scope) {
        throw new Error('Account or filter scope not available')
      }
      return await updateAccountPrefs({
        ...currentAccount.prefs,
        ...buildSavedFiltersPrefs(scope, orderedFilters),
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['account', 'console'] })
    },
  })

  const reorderTeamMutation = useMutation({
    mutationFn: async (orderedFilters: SavedFilter[]) => {
      const currentTeam = queryClient.getQueryData<{
        prefs?: Record<string, unknown>
      }>(['team', 'console', teamId])
      if (!currentTeam || !scope || !teamId) {
        throw new Error('Team or filter scope not available')
      }
      await updateTeamPrefs.mutateAsync({
        ...(currentTeam.prefs as Record<string, unknown>),
        ...buildSavedFiltersPrefs(scope, orderedFilters),
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['team', 'console', teamId],
      })
    },
  })

  const addSavedFilter = async (args: {
    name: string
    query: string
    level?: SavedFilterLevel
    sort?: string
  }) => {
    const level = args.level ?? 'user'
    if (level === 'team' && teamId) {
      return addTeamMutation.mutateAsync({
        name: args.name,
        query: args.query,
        sort: args.sort,
      })
    }
    return addUserMutation.mutateAsync({
      name: args.name,
      query: args.query,
      sort: args.sort,
    })
  }

  const deleteSavedFilter = async (id: string, level: SavedFilterLevel) => {
    if (level === 'team' && teamId) {
      return deleteTeamMutation.mutateAsync(id)
    }
    return deleteUserMutation.mutateAsync(id)
  }

  const reorderSavedFilters = async (
    orderedFilters: SavedFilter[],
    level: SavedFilterLevel,
  ) => {
    if (level === 'team' && teamId) {
      return reorderTeamMutation.mutateAsync(orderedFilters)
    }
    return reorderUserMutation.mutateAsync(orderedFilters)
  }

  const updateUserFilterMutation = useMutation({
    mutationFn: async ({ id, name }: { id: string; name: string }) => {
      const currentAccount = getConsoleAccountFromCache(queryClient)
      if (!currentAccount || !scope) {
        throw new Error('Account or filter scope not available')
      }
      const current = parseSavedFilters(currentAccount.prefs, scope)
      const trimmedName = name.trim().slice(0, MAX_SAVED_FILTER_NAME_LENGTH)
      if (!trimmedName) throw new Error('Name is required')
      const next = current.map((f) =>
        f.id === id ? { ...f, name: trimmedName } : f,
      )
      return await updateAccountPrefs({
        ...currentAccount.prefs,
        ...buildSavedFiltersPrefs(scope, next),
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['account', 'console'] })
    },
  })

  const updateTeamFilterMutation = useMutation({
    mutationFn: async ({ id, name }: { id: string; name: string }) => {
      const currentTeam = queryClient.getQueryData<{
        prefs?: Record<string, unknown>
      }>(['team', 'console', teamId])
      if (!currentTeam || !scope || !teamId) {
        throw new Error('Team or filter scope not available')
      }
      const current = parseSavedFilters(
        currentTeam.prefs as Record<string, unknown>,
        scope,
      )
      const trimmedName = name.trim().slice(0, MAX_SAVED_FILTER_NAME_LENGTH)
      if (!trimmedName) throw new Error('Name is required')
      const next = current.map((f) =>
        f.id === id ? { ...f, name: trimmedName } : f,
      )
      await updateTeamPrefs.mutateAsync({
        ...(currentTeam.prefs as Record<string, unknown>),
        ...buildSavedFiltersPrefs(scope, next),
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['team', 'console', teamId],
      })
    },
  })

  const updateSavedFilterName = async (
    id: string,
    level: SavedFilterLevel,
    name: string,
  ) => {
    if (level === 'team' && teamId) {
      return updateTeamFilterMutation.mutateAsync({ id, name })
    }
    return updateUserFilterMutation.mutateAsync({ id, name })
  }

  return {
    userSavedFilters,
    teamSavedFilters,
    /** All filters for backward compatibility; user first, then team. */
    savedFilters: [...userSavedFilters, ...teamSavedFilters],
    addSavedFilter,
    deleteSavedFilter,
    reorderSavedFilters,
    updateSavedFilterName,
    isAdding: addUserMutation.isPending || addTeamMutation.isPending,
    isDeleting: deleteUserMutation.isPending || deleteTeamMutation.isPending,
    hasTeamLevel: !!teamId,
  }
}

/**
 * Tables DB row grid: ordered column keys (system fields + attributes) for display;
 * non-`$` keys are passed to `Query.select` (see `buildRowListSelectQuery`).
 * Account prefs key `console.tablesDb.rowsListColumns.<databaseId>.<tableId>`.
 * Use within RequireAuth (account required).
 */
export function useTablesDbRowsListColumns(
  databaseId: string | null | undefined,
  tableId: string | null | undefined,
  account: { prefs?: Record<string, unknown> } | undefined,
) {
  const queryClient = useQueryClient()

  const savedAttrKeys = useMemo(() => {
    if (!databaseId || !tableId || !account?.prefs) return null
    return parseTablesDbRowsListColumnsFromPrefs(
      account.prefs as UserPrefs,
      databaseId,
      tableId,
    )
  }, [account?.prefs, databaseId, tableId])

  const persistMutation = useMutation({
    mutationFn: async (keys: string[] | null) => {
      const currentAccount = getConsoleAccountFromCache(queryClient)
      if (!currentAccount || !databaseId || !tableId) {
        throw new Error('Account or table context unavailable')
      }
      return await updateAccountPrefs(
        mergeTablesDbRowsListColumnsIntoPrefs(
          (currentAccount.prefs || {}) as UserPrefs,
          databaseId,
          tableId,
          keys,
        ),
      )
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['account', 'console'] })
    },
  })

  return {
    savedAttrKeys,
    persistAttrKeys: persistMutation.mutateAsync,
    isPersisting: persistMutation.isPending,
  }
}

export type ImageTransformSavedPresetLevel = 'user' | 'team'

/**
 * Saved image-transform presets (account + team prefs, key `console.imageTransformPresets`).
 * Team list uses the same key on the organization team document.
 */
export function useImageTransformSavedPresets(
  account: { prefs?: Record<string, unknown> } | undefined,
  teamId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  const { data: team } = useConsoleTeam(teamId)
  const updateTeamPrefs = useUpdateConsoleTeamPrefs(teamId)

  const userPresets: SavedImageTransformPreset[] = account?.prefs
    ? parseSavedImageTransformPresets(account.prefs)
    : []

  const teamPresets: SavedImageTransformPreset[] =
    team?.prefs && teamId
      ? parseSavedImageTransformPresets(team.prefs as Record<string, unknown>)
      : []

  const addUserMutation = useMutation({
    mutationFn: async ({ name, json }: { name: string; json: string }) => {
      const currentAccount = getConsoleAccountFromCache(queryClient)
      if (!currentAccount) throw new Error('Account not available')
      if (json.length > MAX_SAVED_IMAGE_TRANSFORM_PRESET_JSON_CHARS) {
        throw new Error('Preset data is too large')
      }
      const current = parseSavedImageTransformPresets(currentAccount.prefs)
      const trimmedName = name.trim().slice(0, MAX_SAVED_IMAGE_TRANSFORM_PRESET_NAME_LENGTH)
      if (!trimmedName) throw new Error('Name is required')
      if (current.length >= MAX_SAVED_IMAGE_TRANSFORM_PRESETS) {
        throw new Error(`Maximum ${MAX_SAVED_IMAGE_TRANSFORM_PRESETS} presets`)
      }
      const next: SavedImageTransformPreset[] = [
        {
          id: crypto.randomUUID(),
          name: trimmedName,
          json,
        },
        ...current,
      ]
      return await updateAccountPrefs({
        ...currentAccount.prefs,
        ...buildSavedImageTransformPresetsPrefs(next),
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['account', 'console'] })
    },
  })

  const addTeamMutation = useMutation({
    mutationFn: async ({ name, json }: { name: string; json: string }) => {
      const currentTeam = queryClient.getQueryData<{
        prefs?: Record<string, unknown>
      }>(['team', 'console', teamId])
      if (!currentTeam || !teamId) throw new Error('Team not available')
      if (json.length > MAX_SAVED_IMAGE_TRANSFORM_PRESET_JSON_CHARS) {
        throw new Error('Preset data is too large')
      }
      const current = parseSavedImageTransformPresets(
        currentTeam.prefs as Record<string, unknown>,
      )
      const trimmedName = name.trim().slice(0, MAX_SAVED_IMAGE_TRANSFORM_PRESET_NAME_LENGTH)
      if (!trimmedName) throw new Error('Name is required')
      if (current.length >= MAX_SAVED_IMAGE_TRANSFORM_PRESETS) {
        throw new Error(`Maximum ${MAX_SAVED_IMAGE_TRANSFORM_PRESETS} presets`)
      }
      const next: SavedImageTransformPreset[] = [
        { id: crypto.randomUUID(), name: trimmedName, json },
        ...current,
      ]
      await updateTeamPrefs.mutateAsync({
        ...(currentTeam.prefs as Record<string, unknown>),
        ...buildSavedImageTransformPresetsPrefs(next),
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['team', 'console', teamId] })
    },
  })

  const deleteUserMutation = useMutation({
    mutationFn: async (id: string) => {
      const currentAccount = getConsoleAccountFromCache(queryClient)
      if (!currentAccount) throw new Error('Account not available')
      const current = parseSavedImageTransformPresets(currentAccount.prefs)
      const next = current.filter((p) => p.id !== id)
      return await updateAccountPrefs({
        ...currentAccount.prefs,
        ...buildSavedImageTransformPresetsPrefs(next),
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['account', 'console'] })
    },
  })

  const deleteTeamMutation = useMutation({
    mutationFn: async (id: string) => {
      const currentTeam = queryClient.getQueryData<{
        prefs?: Record<string, unknown>
      }>(['team', 'console', teamId])
      if (!currentTeam || !teamId) throw new Error('Team not available')
      const current = parseSavedImageTransformPresets(
        currentTeam.prefs as Record<string, unknown>,
      )
      const next = current.filter((p) => p.id !== id)
      await updateTeamPrefs.mutateAsync({
        ...(currentTeam.prefs as Record<string, unknown>),
        ...buildSavedImageTransformPresetsPrefs(next),
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['team', 'console', teamId] })
    },
  })

  const reorderUserMutation = useMutation({
    mutationFn: async (ordered: SavedImageTransformPreset[]) => {
      const currentAccount = getConsoleAccountFromCache(queryClient)
      if (!currentAccount) throw new Error('Account not available')
      return await updateAccountPrefs({
        ...currentAccount.prefs,
        ...buildSavedImageTransformPresetsPrefs(ordered),
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['account', 'console'] })
    },
  })

  const reorderTeamMutation = useMutation({
    mutationFn: async (ordered: SavedImageTransformPreset[]) => {
      const currentTeam = queryClient.getQueryData<{
        prefs?: Record<string, unknown>
      }>(['team', 'console', teamId])
      if (!currentTeam || !teamId) throw new Error('Team not available')
      await updateTeamPrefs.mutateAsync({
        ...(currentTeam.prefs as Record<string, unknown>),
        ...buildSavedImageTransformPresetsPrefs(ordered),
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['team', 'console', teamId] })
    },
  })

  const updateUserPresetNameMutation = useMutation({
    mutationFn: async ({ id, name }: { id: string; name: string }) => {
      const currentAccount = getConsoleAccountFromCache(queryClient)
      if (!currentAccount) throw new Error('Account not available')
      const current = parseSavedImageTransformPresets(currentAccount.prefs)
      const trimmedName = name
        .trim()
        .slice(0, MAX_SAVED_IMAGE_TRANSFORM_PRESET_NAME_LENGTH)
      if (!trimmedName) throw new Error('Name is required')
      const next = current.map((p) =>
        p.id === id ? { ...p, name: trimmedName } : p,
      )
      return await updateAccountPrefs({
        ...currentAccount.prefs,
        ...buildSavedImageTransformPresetsPrefs(next),
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['account', 'console'] })
    },
  })

  const updateTeamPresetNameMutation = useMutation({
    mutationFn: async ({ id, name }: { id: string; name: string }) => {
      const currentTeam = queryClient.getQueryData<{
        prefs?: Record<string, unknown>
      }>(['team', 'console', teamId])
      if (!currentTeam || !teamId) throw new Error('Team not available')
      const current = parseSavedImageTransformPresets(
        currentTeam.prefs as Record<string, unknown>,
      )
      const trimmedName = name
        .trim()
        .slice(0, MAX_SAVED_IMAGE_TRANSFORM_PRESET_NAME_LENGTH)
      if (!trimmedName) throw new Error('Name is required')
      const next = current.map((p) =>
        p.id === id ? { ...p, name: trimmedName } : p,
      )
      await updateTeamPrefs.mutateAsync({
        ...(currentTeam.prefs as Record<string, unknown>),
        ...buildSavedImageTransformPresetsPrefs(next),
      })
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['team', 'console', teamId] })
    },
  })

  const addPreset = useCallback(
    async (args: { name: string; json: string; level: ImageTransformSavedPresetLevel }) => {
      if (args.level === 'team' && teamId) {
        return addTeamMutation.mutateAsync({
          name: args.name,
          json: args.json,
        })
      }
      return addUserMutation.mutateAsync({ name: args.name, json: args.json })
    },
    [addTeamMutation, addUserMutation, teamId],
  )

  const deletePreset = useCallback(
    async (id: string, level: ImageTransformSavedPresetLevel) => {
      if (level === 'team' && teamId) return deleteTeamMutation.mutateAsync(id)
      return deleteUserMutation.mutateAsync(id)
    },
    [deleteTeamMutation, deleteUserMutation, teamId],
  )

  const reorderPresets = useCallback(
    async (
      ordered: SavedImageTransformPreset[],
      level: ImageTransformSavedPresetLevel,
    ) => {
      if (level === 'team' && teamId) {
        return reorderTeamMutation.mutateAsync(ordered)
      }
      return reorderUserMutation.mutateAsync(ordered)
    },
    [reorderTeamMutation, reorderUserMutation, teamId],
  )

  const updatePresetName = useCallback(
    async (
      id: string,
      level: ImageTransformSavedPresetLevel,
      name: string,
    ) => {
      if (level === 'team' && teamId) {
        return updateTeamPresetNameMutation.mutateAsync({ id, name })
      }
      return updateUserPresetNameMutation.mutateAsync({ id, name })
    },
    [teamId, updateTeamPresetNameMutation, updateUserPresetNameMutation],
  )

  return {
    userPresets,
    teamPresets,
    addPreset,
    deletePreset,
    reorderPresets,
    updatePresetName,
    isAdding: addUserMutation.isPending || addTeamMutation.isPending,
    isDeleting: deleteUserMutation.isPending || deleteTeamMutation.isPending,
    isReordering:
      reorderUserMutation.isPending || reorderTeamMutation.isPending,
    hasTeamLevel: !!teamId,
  }
}
