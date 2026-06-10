/**
 * React Query hooks for Auth Security Features
 *
 * Handles auth limits, sessions, passwords, and MFA.
 */

import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import {
  useMutation,
  useQuery,
  useQueryClient,
  queryOptions,
  type QueryClient,
} from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { ProjectAuthMethodId } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { setConsoleAccountCache } from '@/lib/console-account-cache'
import {
  fetchConsoleAccount,
  getConsoleAccountFromSingleton,
  type FetchConsoleAccountOptions,
} from '@/lib/console-account-get'
import { getConsoleAccountQueryRevision } from '@/lib/console-impersonation'
import {
  buildDatabasesSidebarWidthPrefs,
  buildPostgresSqlEditorHeightPrefs,
  buildSavedFiltersPrefs,
  buildSavedImageTransformPresetsPrefs,
  buildStorageSidebarWidthPrefs,
  DATABASES_SIDEBAR_DEFAULT_WIDTH_PX,
  POSTGRES_SQL_EDITOR_DEFAULT_HEIGHT_PX,
  parseDatabasesSidebarWidthPx,
  parsePostgresSqlEditorHeightPx,
  parseStorageSidebarWidthPx,
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
  clearLegacyAIChatLocalStorage,
  clearLegacyBuildNotificationsOptedOutLocalStorage,
  clearLegacyCliShellHeightLocalStorage,
  clearLegacyStorageFilesTablePaneWidthLocalStorage,
  hasAIChatPanelOpenPref,
  hasAIChatPanelWidthPref,
  hasBuildNotificationsOptedOutPref,
  hasCliShellHeightPref,
  hasStorageFilesTablePaneWidthPref,
  mergeAIChatPanelOpenIntoPrefs,
  mergeAIChatPanelWidthPxIntoPrefs,
  mergeApiExplorerColumnsLayoutIntoPrefs,
  mergeApiExplorerExpandedProductGroupIntoPrefs,
  mergeApiExplorerResponseSplitLayoutIntoPrefs,
  mergeBuildNotificationsOptedOutIntoPrefs,
  mergeCliShellHeightPxIntoPrefs,
  mergeCliShellHistoryIntoPrefs,
  mergeCliShellOpenIntoPrefs,
  mergeCliShellSessionsIntoPrefs,
  mergeCliShellSessionsSidebarWidthPxIntoPrefs,
  parseCliShellHistory,
  parseCliShellSessions,
  type PersistedCliShellSessionsState,
  mergeStorageFilesTablePaneWidthPxIntoPrefs,
  parseAIChatPanelOpen,
  parseAIChatPanelWidthPx,
  parseApiExplorerColumnsLayout,
  parseApiExplorerExpandedProductGroup,
  parseApiExplorerResponseSplitLayout,
  parseBuildNotificationsOptedOut,
  parseCliShellHeightPx,
  parseCliShellOpen,
  parseCliShellSessionsSidebarWidthPx,
  parseStorageFilesTablePaneWidthPx,
  readLegacyAIChatPanelOpenFromLocalStorage,
  readLegacyAIChatPanelWidthFromLocalStorage,
  readLegacyBuildNotificationsOptedOutFromLocalStorage,
  readLegacyCliShellHeightFromLocalStorage,
  readLegacyStorageFilesTablePaneWidthFromLocalStorage,
  type UserPrefs,
} from '@/lib/user-prefs-keys'
import {
  API_EXPLORER_COLUMNS_DEFAULT_LAYOUT,
  API_EXPLORER_RESPONSE_SPLIT_DEFAULT_LAYOUT,
  normalizeApiExplorerColumnsLayout,
  normalizeApiExplorerResponseSplitLayout,
} from '@/lib/resizable-layout'
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

/** Account query stays fresh for the session; only explicit invalidation refetches. */
export const CONSOLE_ACCOUNT_STALE_TIME_MS = Number.POSITIVE_INFINITY

export type { FetchConsoleAccountOptions }
export {
  fetchConsoleAccount,
  getConsoleAccountFromSingleton as getConsoleAccountSync,
} from '@/lib/console-account-get'
export {
  clearConsoleAccountCache,
  setConsoleAccountCache,
} from '@/lib/console-account-cache'

function isConsoleAccountUser(value: unknown): value is Models.User {
  return !!value && typeof value === 'object' && '$id' in value
}

/** Write account to both the module singleton and every cached React Query entry. */
export function commitConsoleAccountToCaches(
  queryClient: QueryClient,
  account: Models.User,
  revision: number = getConsoleAccountQueryRevision(),
): void {
  setConsoleAccountCache(account, revision)
  queryClient.setQueriesData<Models.User>(
    { queryKey: ['account', 'console'] },
    account,
  )
}

/**
 * After any account mutation, keep React Query and the module singleton in sync.
 * Pass `apiResult` when the API returns a user; otherwise uses the React Query
 * cache (e.g. after optimistic pref updates in `onMutate`).
 */
export function syncConsoleAccountAfterMutation(
  queryClient: QueryClient,
  options?: {
    apiResult?: unknown
    patch?: Partial<Models.User>
    updater?: (current: Models.User) => Models.User
  },
): void {
  const revision = getConsoleAccountQueryRevision()
  const cached =
    (getConsoleAccountFromCache(queryClient) as Models.User | undefined) ??
    getConsoleAccountFromSingleton(revision)

  let next: Models.User | undefined

  if (options?.apiResult && isConsoleAccountUser(options.apiResult)) {
    next = cached
      ? ({
          ...cached,
          ...options.apiResult,
          prefs: options.apiResult.prefs ?? cached.prefs,
        } as Models.User)
      : options.apiResult
  } else if (options?.updater && cached) {
    next = options.updater(cached)
  } else if (options?.patch && cached) {
    next = { ...cached, ...options.patch } as Models.User
  } else {
    const fromQuery = getConsoleAccountFromCache(queryClient) as
      | Models.User
      | undefined
    next = fromQuery ?? cached
  }

  if (next && options?.patch) {
    next = { ...next, ...options.patch } as Models.User
  }

  if (next) {
    commitConsoleAccountToCaches(queryClient, next, revision)
  }
}

/** @deprecated Use `syncConsoleAccountAfterMutation`. */
export function syncConsoleAccountSingletonFromQueryClient(
  queryClient: QueryClient,
): void {
  syncConsoleAccountAfterMutation(queryClient)
}

/**
 * Console `account.get` — shared by route loaders (prefetch prefs before child
 * loaders) and auth UI. Pass `revision` from `useConsoleImpersonationRevision` when
 * overriding `queryFn` in components.
 */
export function consoleAccountQueryOptions(options?: {
  revision?: number
}) {
  const revision = options?.revision ?? getConsoleAccountQueryRevision()
  return queryOptions({
    queryKey: ['account', 'console', revision],
    queryFn: () => fetchConsoleAccount({ revision }),
    staleTime: CONSOLE_ACCOUNT_STALE_TIME_MS,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    enabled: typeof window !== 'undefined',
  })
}

// ============================================================================
// AUTH SECURITY FEATURES
// ============================================================================

/**
 * Hook to update project users limit
 *
 * @param projectId - The project ID
 */
function invalidateProjectAuthQueries(
  queryClient: ReturnType<typeof useQueryClient>,
  projectId: string | null | undefined,
) {
  queryClient.invalidateQueries({ queryKey: ['project', projectId] })
  queryClient.invalidateQueries({
    queryKey: ['project-auth-security', projectId],
  })
}

/**
 * Appwrite project policies with `total`: 1–5000, or null (disabled/unlimited).
 * UI represents unlimited/disabled as 0.
 */
export const MAX_AUTH_POLICY_TOTAL = 5000

function assertAuthPolicyTotal(limit: number, featureLabel: string): void {
  if (limit !== 0 && (limit < 1 || limit > MAX_AUTH_POLICY_TOTAL)) {
    throw new Error(
      `${featureLabel} must be disabled/unlimited or between 1 and ${MAX_AUTH_POLICY_TOTAL.toLocaleString()}`,
    )
  }
}

function authPolicyTotalForApi(limit: number): number | null {
  return limit === 0 ? null : limit
}

export function useUpdateAuthLimit(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (limit: number) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      assertAuthPolicyTotal(limit, 'Users limit')

      return await sdk.forProject(projectId).project.updateUserLimitPolicy({
        total: authPolicyTotalForApi(limit) as unknown as number,
      })
    },
    onSuccess: () => {
      invalidateProjectAuthQueries(queryClient, projectId)
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

      return await sdk.forProject(projectId).project.updateSessionDurationPolicy({
        duration: clampedDuration,
      })
    },
    onSuccess: () => {
      invalidateProjectAuthQueries(queryClient, projectId)
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
      assertAuthPolicyTotal(limit, 'Sessions limit')

      return await sdk.forProject(projectId).project.updateSessionLimitPolicy({
        total: authPolicyTotalForApi(limit) as unknown as number,
      })
    },
    onSuccess: () => {
      invalidateProjectAuthQueries(queryClient, projectId)
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
      assertAuthPolicyTotal(limit, 'Password history')

      return await sdk.forProject(projectId).project.updatePasswordHistoryPolicy({
        total: authPolicyTotalForApi(limit) as unknown as number,
      })
    },
    onSuccess: () => {
      invalidateProjectAuthQueries(queryClient, projectId)
    },
  })
}

/**
 * Hook to update project password strength requirements
 */
export function useUpdateAuthPasswordStrength(
  projectId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (policy: {
      min: number
      uppercase: boolean
      lowercase: boolean
      number: boolean
      symbols: boolean
    }) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      if (policy.min < 8 || policy.min > 256) {
        throw new Error('Minimum length must be between 8 and 256 characters')
      }

      return await sdk
        .forProject(projectId)
        .project.updatePasswordStrengthPolicy({
          min: policy.min,
          uppercase: policy.uppercase,
          lowercase: policy.lowercase,
          number: policy.number,
          symbols: policy.symbols,
        })
    },
    onSuccess: () => {
      invalidateProjectAuthQueries(queryClient, projectId)
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

      return await sdk
        .forProject(projectId)
        .project.updatePasswordDictionaryPolicy({ enabled })
    },
    onSuccess: () => {
      invalidateProjectAuthQueries(queryClient, projectId)
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

      return await sdk
        .forProject(projectId)
        .project.updatePasswordPersonalDataPolicy({ enabled })
    },
    onSuccess: () => {
      invalidateProjectAuthQueries(queryClient, projectId)
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

      return await sdk.forProject(projectId).project.updateSessionAlertPolicy({
        enabled: alerts,
      })
    },
    onSuccess: () => {
      invalidateProjectAuthQueries(queryClient, projectId)
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

      return await sdk
        .forProject(projectId)
        .project.updateSessionInvalidationPolicy({ enabled })
    },
    onSuccess: () => {
      invalidateProjectAuthQueries(queryClient, projectId)
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

      const projectSdk = sdk.forProject(projectId)
      const existing = await projectSdk.project.listMockPhones({ total: true })
      const existingByNumber = new Map(
        (existing.mockNumbers ?? []).map((n) => [n.number, n]),
      )
      const nextNumbers = new Set(numbers.map((n) => n.phone))

      for (const mock of existing.mockNumbers ?? []) {
        if (!nextNumbers.has(mock.number)) {
          await projectSdk.project.deleteMockPhone({ number: mock.number })
        }
      }

      for (const { phone, otp } of numbers) {
        const prev = existingByNumber.get(phone)
        if (prev) {
          if (prev.otp !== otp) {
            await projectSdk.project.updateMockPhone({ number: phone, otp })
          }
        } else {
          await projectSdk.project.createMockPhone({ number: phone, otp })
        }
      }
    },
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['project-auth-security', projectId],
      })
      invalidateProjectAuthQueries(queryClient, projectId)
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
      userId?: boolean
      userPhone?: boolean
    }) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }

      return await sdk.forProject(projectId).project.updateMembershipPrivacyPolicy({
        userName: privacy.userName,
        userEmail: privacy.userEmail,
        userMFA: privacy.mfa,
        userId: privacy.userId,
        userPhone: privacy.userPhone,
      })
    },
    onSuccess: () => {
      invalidateProjectAuthQueries(queryClient, projectId)
    },
  })
}

type ProjectEmailPolicyService = {
  updateDenyFreeEmailPolicy: (params: {
    enabled: boolean
  }) => Promise<unknown>
  updateDenyAliasedEmailPolicy: (params: {
    enabled: boolean
  }) => Promise<unknown>
  updateDenyDisposableEmailPolicy: (params: {
    enabled: boolean
  }) => Promise<unknown>
}

function projectEmailPolicyService(projectId: string): ProjectEmailPolicyService {
  return sdk.forProject(projectId).project as ProjectEmailPolicyService
}

/**
 * Hook to update the deny free email policy.
 */
export function useUpdateDenyFreeEmailPolicy(
  projectId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (enabled: boolean) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      return await projectEmailPolicyService(projectId).updateDenyFreeEmailPolicy(
        { enabled },
      )
    },
    onSuccess: () => {
      invalidateProjectAuthQueries(queryClient, projectId)
    },
  })
}

/**
 * Hook to update the deny aliased email policy.
 */
export function useUpdateDenyAliasedEmailPolicy(
  projectId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (enabled: boolean) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      return await projectEmailPolicyService(
        projectId,
      ).updateDenyAliasedEmailPolicy({ enabled })
    },
    onSuccess: () => {
      invalidateProjectAuthQueries(queryClient, projectId)
    },
  })
}

/**
 * Hook to update the deny disposable email policy.
 */
export function useUpdateDenyDisposableEmailPolicy(
  projectId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (enabled: boolean) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      return await projectEmailPolicyService(
        projectId,
      ).updateDenyDisposableEmailPolicy({ enabled })
    },
    onSuccess: () => {
      invalidateProjectAuthQueries(queryClient, projectId)
    },
  })
}

export type AuthEmailPoliciesInput = {
  denyFreeEmail: boolean
  denyAliasedEmail: boolean
  denyDisposableEmail: boolean
}

/**
 * Updates all three email policies in one request (legacy combined mutation).
 * Prefer the per-policy hooks in standalone cards.
 */
export function useUpdateAuthEmailPolicies(
  projectId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (policies: AuthEmailPoliciesInput) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }

      const service = projectEmailPolicyService(projectId)
      await Promise.all([
        service.updateDenyFreeEmailPolicy({ enabled: policies.denyFreeEmail }),
        service.updateDenyAliasedEmailPolicy({
          enabled: policies.denyAliasedEmail,
        }),
        service.updateDenyDisposableEmailPolicy({
          enabled: policies.denyDisposableEmail,
        }),
      ])
    },
    onSuccess: () => {
      invalidateProjectAuthQueries(queryClient, projectId)
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
        methodId: method as ProjectAuthMethodId,
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
  const account = await fetchConsoleAccount({ force: true })
  const fromPrefs = parseRecentImpersonationUsers(account.prefs as UserPrefs)
  const merged = mergeRecentImpersonationLists(fromPrefs, list)
  const updatedPrefs = mergeRecentImpersonationIntoAccountPrefs(
    account.prefs as UserPrefs,
    merged,
  )
  const updatedAccount = await updateAccountPrefs(updatedPrefs)
  setConsoleAccountCache(
    (updatedAccount ?? { ...account, prefs: updatedPrefs }) as Models.User,
    getConsoleAccountQueryRevision(),
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
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
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
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
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
// TABLE VIEW SIDEBAR WIDTH (DATABASES + STORAGE)
// ============================================================================

export type TableViewSidebarWidthScope = 'databases' | 'storage'

/**
 * Persisted sidebar width in px for `TableViewResizableLayout`.
 * - `databases`: `console.databases.sidebarWidth`
 * - `storage`: `console.storage.sidebarWidth`
 */
export function useTableViewSidebarWidth(
  account: { prefs?: Record<string, unknown> } | undefined,
  scope: TableViewSidebarWidthScope = 'databases',
) {
  const queryClient = useQueryClient()
  const parse =
    scope === 'storage'
      ? parseStorageSidebarWidthPx
      : parseDatabasesSidebarWidthPx
  const build =
    scope === 'storage'
      ? buildStorageSidebarWidthPrefs
      : buildDatabasesSidebarWidthPrefs

  const widthPx =
    parse(account?.prefs as UserPrefs | undefined) ??
    DATABASES_SIDEBAR_DEFAULT_WIDTH_PX

  const updateMutation = useMutation({
    mutationFn: async (value: number) => {
      if (!account) {
        throw new Error('Account data not available')
      }
      return await updateAccountPrefs({
        ...account.prefs,
        ...build(value),
      })
    },
    onMutate: async (value) => {
      const patch = build(value)
      queryClient.setQueriesData<{ prefs?: Record<string, unknown> }>(
        { queryKey: ['account', 'console'] },
        (current) =>
          current
            ? {
                ...current,
                prefs: { ...current.prefs, ...patch },
              }
            : current,
      )
    },
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
    },
  })

  const persistSidebarWidthPx = useCallback(
    (value: number) => {
      updateMutation.mutate(value)
    },
    [updateMutation],
  )

  return { widthPx, persistSidebarWidthPx }
}

/**
 * Persisted SQL editor container height in px for the Postgres SQL workbench.
 */
export function usePostgresSqlEditorHeight(
  account: { prefs?: Record<string, unknown> } | undefined,
) {
  const queryClient = useQueryClient()

  const heightPx =
    parsePostgresSqlEditorHeightPx(account?.prefs as UserPrefs | undefined) ??
    POSTGRES_SQL_EDITOR_DEFAULT_HEIGHT_PX

  const updateMutation = useMutation({
    mutationFn: async (value: number) => {
      if (!account) {
        throw new Error('Account data not available')
      }
      return await updateAccountPrefs({
        ...account.prefs,
        ...buildPostgresSqlEditorHeightPrefs(value),
      })
    },
    onMutate: async (value) => {
      const patch = buildPostgresSqlEditorHeightPrefs(value)
      queryClient.setQueriesData<{ prefs?: Record<string, unknown> }>(
        { queryKey: ['account', 'console'] },
        (current) =>
          current
            ? {
                ...current,
                prefs: { ...current.prefs, ...patch },
              }
            : current,
      )
    },
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
    },
  })

  const persistEditorHeightPx = useCallback(
    (value: number) => {
      updateMutation.mutate(value)
    },
    [updateMutation],
  )

  return { heightPx, persistEditorHeightPx }
}

// ============================================================================
// API EXPLORER PANEL LAYOUTS (ACCOUNT PREFERENCES)
// ============================================================================

function usePersistedPanelLayoutPref(
  account: ConsoleAccountCache | undefined,
  parseLayout: (prefs: UserPrefs | null | undefined) => number[] | null,
  normalizeLayout: (layout: number[]) => number[],
  defaultLayout: readonly number[],
  mergeIntoPrefs: (prefs: UserPrefs, layout: number[]) => UserPrefs,
) {
  const queryClient = useQueryClient()

  const layout = useMemo(
    () =>
      normalizeLayout(
        parseLayout(account?.prefs as UserPrefs | undefined) ?? [
          ...defaultLayout,
        ],
      ),
    [account?.prefs, defaultLayout, normalizeLayout, parseLayout],
  )

  const updateMutation = useMutation({
    mutationFn: async (value: number[]) => {
      if (!account) {
        throw new Error('Account data not available')
      }
      return await updateAccountPrefs(
        mergeIntoPrefs((account.prefs ?? {}) as UserPrefs, value),
      )
    },
    onMutate: async (value) => {
      const patch = mergeIntoPrefs((account?.prefs ?? {}) as UserPrefs, value)
      queryClient.setQueriesData<{ prefs?: Record<string, unknown> }>(
        { queryKey: ['account', 'console'] },
        (current) =>
          current
            ? {
                ...current,
                prefs: { ...current.prefs, ...patch },
              }
            : current,
      )
    },
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
    },
  })

  const persistLayout = useCallback(
    (value: number[]) => {
      if (!account) return
      updateMutation.mutate(normalizeLayout(value))
    },
    [account, normalizeLayout, updateMutation],
  )

  return { layout, persistLayout }
}

/** Services | methods | request column split (`console.apiExplorer.columnsLayout`). */
export function useApiExplorerColumnsLayout(
  account: ConsoleAccountCache | undefined,
) {
  return usePersistedPanelLayoutPref(
    account,
    parseApiExplorerColumnsLayout,
    normalizeApiExplorerColumnsLayout,
    API_EXPLORER_COLUMNS_DEFAULT_LAYOUT,
    mergeApiExplorerColumnsLayoutIntoPrefs,
  )
}

/** Request form | response split (`console.apiExplorer.responseSplitLayout`). */
export function useApiExplorerResponseSplitLayout(
  account: ConsoleAccountCache | undefined,
) {
  return usePersistedPanelLayoutPref(
    account,
    parseApiExplorerResponseSplitLayout,
    normalizeApiExplorerResponseSplitLayout,
    API_EXPLORER_RESPONSE_SPLIT_DEFAULT_LAYOUT,
    mergeApiExplorerResponseSplitLayoutIntoPrefs,
  )
}

/** Open services product group in the API explorer (`console.apiExplorer.expandedProductGroup`). */
export function useApiExplorerExpandedProductGroup(
  account: ConsoleAccountCache | undefined,
) {
  const queryClient = useQueryClient()
  const [expandedProductGroupId, setExpandedProductGroupId] = useState<string | null>(
    null,
  )
  const hydratedRef = useRef(false)
  const expandedProductGroupIdRef = useRef<string | null>(null)

  useEffect(() => {
    if (!account) {
      hydratedRef.current = false
      return
    }
    if (hydratedRef.current) return
    hydratedRef.current = true
    const parsed = parseApiExplorerExpandedProductGroup(
      account.prefs as UserPrefs | undefined,
    )
    expandedProductGroupIdRef.current = parsed
    setExpandedProductGroupId(parsed)
  }, [account])

  const updateMutation = useMutation({
    mutationFn: async (value: string | null) => {
      if (!account) {
        throw new Error('Account data not available')
      }
      return await updateAccountPrefs(
        mergeApiExplorerExpandedProductGroupIntoPrefs(
          (account.prefs ?? {}) as UserPrefs,
          value,
        ),
      )
    },
    onMutate: async (value) => {
      const patch = mergeApiExplorerExpandedProductGroupIntoPrefs(
        (account?.prefs ?? {}) as UserPrefs,
        value,
      )
      queryClient.setQueriesData<{ prefs?: Record<string, unknown> }>(
        { queryKey: ['account', 'console'] },
        (current) =>
          current
            ? {
                ...current,
                prefs: { ...current.prefs, ...patch },
              }
            : current,
      )
    },
  })

  const persistExpandedRef = useRef(updateMutation.mutate)
  persistExpandedRef.current = updateMutation.mutate

  const setExpandedProductGroup = useCallback(
    (groupId: string | undefined) => {
      const next = groupId ?? null
      if (expandedProductGroupIdRef.current === next) return
      expandedProductGroupIdRef.current = next
      setExpandedProductGroupId(next)
      if (account) persistExpandedRef.current(next)
    },
    [account],
  )

  useEffect(() => {
    expandedProductGroupIdRef.current = expandedProductGroupId
  }, [expandedProductGroupId])

  return {
    expandedProductGroupId,
    setExpandedProductGroup,
  }
}

// ============================================================================
// AI CHAT PANEL + BUILD NOTIFICATIONS (ACCOUNT PREFERENCES)
// ============================================================================

const AI_CHAT_PANEL_WIDTH_PERSIST_DEBOUNCE_MS = 250

async function migrateLegacyBrowserPrefsToAccount(
  account: ConsoleAccountCache,
  queryClient: QueryClient,
): Promise<void> {
  const prefs = (account.prefs ?? {}) as UserPrefs
  let next: UserPrefs = { ...prefs }
  let changed = false

  if (!hasAIChatPanelOpenPref(prefs)) {
    const legacyOpen = readLegacyAIChatPanelOpenFromLocalStorage()
    if (legacyOpen !== null) {
      next = mergeAIChatPanelOpenIntoPrefs(next, legacyOpen)
      changed = true
    }
  }

  if (!hasAIChatPanelWidthPref(prefs)) {
    const legacyWidth = readLegacyAIChatPanelWidthFromLocalStorage()
    if (legacyWidth !== null) {
      next = mergeAIChatPanelWidthPxIntoPrefs(next, legacyWidth)
      changed = true
    }
  }

  if (!hasBuildNotificationsOptedOutPref(prefs)) {
    const legacyOptedOut = readLegacyBuildNotificationsOptedOutFromLocalStorage()
    if (legacyOptedOut) {
      next = mergeBuildNotificationsOptedOutIntoPrefs(next, true)
      changed = true
    }
  }

  if (!hasStorageFilesTablePaneWidthPref(prefs)) {
    const legacyPaneWidth =
      readLegacyStorageFilesTablePaneWidthFromLocalStorage()
    if (legacyPaneWidth !== null) {
      next = mergeStorageFilesTablePaneWidthPxIntoPrefs(next, legacyPaneWidth)
      changed = true
    }
  }

  if (!hasCliShellHeightPref(prefs)) {
    const legacyCliShellHeight = readLegacyCliShellHeightFromLocalStorage()
    if (legacyCliShellHeight !== null) {
      next = mergeCliShellHeightPxIntoPrefs(next, legacyCliShellHeight)
      changed = true
    }
  }

  if (!changed) {
    clearLegacyAIChatLocalStorage()
    clearLegacyBuildNotificationsOptedOutLocalStorage()
    clearLegacyCliShellHeightLocalStorage()
    clearLegacyStorageFilesTablePaneWidthLocalStorage()
    return
  }

  const updatedAccount = await updateAccountPrefs(next)
  clearLegacyAIChatLocalStorage()
  clearLegacyBuildNotificationsOptedOutLocalStorage()
  clearLegacyCliShellHeightLocalStorage()
  clearLegacyStorageFilesTablePaneWidthLocalStorage()
  commitConsoleAccountToCaches(
    queryClient,
    (updatedAccount ?? { ...account, prefs: next }) as Models.User,
  )
}

let legacyBrowserPrefsMigrationPromise: Promise<void> | null = null

function useMigrateLegacyBrowserPrefsToAccount(
  account: ConsoleAccountCache | undefined,
) {
  const queryClient = useQueryClient()

  useEffect(() => {
    if (!account) return
    if (!legacyBrowserPrefsMigrationPromise) {
      legacyBrowserPrefsMigrationPromise = migrateLegacyBrowserPrefsToAccount(
        account,
        queryClient,
      ).catch(() => {
        legacyBrowserPrefsMigrationPromise = null
      })
    }
  }, [account, queryClient])
}

/**
 * AI assistant panel open state (`console.aiChat.panelOpen`).
 * Migrates legacy localStorage on first account load.
 */
export function useAIChatPanelOpen(
  account: ConsoleAccountCache | undefined,
) {
  const queryClient = useQueryClient()
  useMigrateLegacyBrowserPrefsToAccount(account)

  const isOpen = parseAIChatPanelOpen(account?.prefs as UserPrefs | undefined)

  const updateMutation = useMutation({
    mutationFn: async (value: boolean) => {
      if (!account) {
        throw new Error('Account data not available')
      }
      return await updateAccountPrefs(
        mergeAIChatPanelOpenIntoPrefs(
          (account.prefs ?? {}) as UserPrefs,
          value,
        ),
      )
    },
    onMutate: async (value) => {
      queryClient.setQueriesData<{ prefs?: Record<string, unknown> }>(
        { queryKey: ['account', 'console'] },
        (current) =>
          current
            ? {
                ...current,
                prefs: mergeAIChatPanelOpenIntoPrefs(
                  (current.prefs ?? {}) as UserPrefs,
                  value,
                ),
              }
            : current,
      )
    },
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
    },
  })

  const setIsOpen = useCallback(
    (value: boolean | ((prev: boolean) => boolean)) => {
      const nextValue = typeof value === 'function' ? value(isOpen) : value
      if (!account) return
      updateMutation.mutate(nextValue)
    },
    [account, isOpen, updateMutation],
  )

  return { isOpen, setIsOpen }
}

/**
 * AI assistant panel width (`console.aiChat.panelWidthPx`).
 * Debounces writes while resizing.
 */
export function useAIChatPanelWidth(
  account: ConsoleAccountCache | undefined,
) {
  const queryClient = useQueryClient()
  useMigrateLegacyBrowserPrefsToAccount(account)

  const widthPx = parseAIChatPanelWidthPx(
    account?.prefs as UserPrefs | undefined,
  )

  const persistTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  const updateMutation = useMutation({
    mutationFn: async (value: number) => {
      if (!account) {
        throw new Error('Account data not available')
      }
      return await updateAccountPrefs(
        mergeAIChatPanelWidthPxIntoPrefs(
          (account.prefs ?? {}) as UserPrefs,
          value,
        ),
      )
    },
    onMutate: async (value) => {
      const patch = mergeAIChatPanelWidthPxIntoPrefs(
        (account?.prefs ?? {}) as UserPrefs,
        value,
      )
      queryClient.setQueriesData<{ prefs?: Record<string, unknown> }>(
        { queryKey: ['account', 'console'] },
        (current) =>
          current
            ? {
                ...current,
                prefs: { ...current.prefs, ...patch },
              }
            : current,
      )
    },
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
    },
  })

  const setWidthPx = useCallback(
    (value: number | ((prev: number) => number)) => {
      const nextValue =
        typeof value === 'function' ? value(widthPx) : value
      if (!account) return
      if (persistTimerRef.current !== null) {
        clearTimeout(persistTimerRef.current)
      }
      const patch = mergeAIChatPanelWidthPxIntoPrefs(
        (account.prefs ?? {}) as UserPrefs,
        nextValue,
      )
      queryClient.setQueriesData<{ prefs?: Record<string, unknown> }>(
        { queryKey: ['account', 'console'] },
        (current) =>
          current
            ? {
                ...current,
                prefs: { ...current.prefs, ...patch },
              }
            : current,
      )
      persistTimerRef.current = setTimeout(() => {
        persistTimerRef.current = null
        updateMutation.mutate(nextValue)
      }, AI_CHAT_PANEL_WIDTH_PERSIST_DEBOUNCE_MS)
    },
    [account, queryClient, updateMutation, widthPx],
  )

  useEffect(() => {
    return () => {
      if (persistTimerRef.current !== null) {
        clearTimeout(persistTimerRef.current)
      }
    }
  }, [])

  return { widthPx, setWidthPx }
}

const CLI_SHELL_HEIGHT_PERSIST_DEBOUNCE_MS = 250

/**
 * CLI terminal open state (`console.cliShell.open`).
 */
export function useCliShellOpen(account: ConsoleAccountCache | undefined) {
  const queryClient = useQueryClient()
  useMigrateLegacyBrowserPrefsToAccount(account)

  const isOpen = parseCliShellOpen(account?.prefs as UserPrefs | undefined)

  const updateMutation = useMutation({
    mutationFn: async (value: boolean) => {
      if (!account) {
        throw new Error('Account data not available')
      }
      return await updateAccountPrefs(
        mergeCliShellOpenIntoPrefs((account.prefs ?? {}) as UserPrefs, value),
      )
    },
    onMutate: async (value) => {
      queryClient.setQueriesData<{ prefs?: Record<string, unknown> }>(
        { queryKey: ['account', 'console'] },
        (current) =>
          current
            ? {
                ...current,
                prefs: mergeCliShellOpenIntoPrefs(
                  (current.prefs ?? {}) as UserPrefs,
                  value,
                ),
              }
            : current,
      )
    },
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
    },
  })

  const setIsOpen = useCallback(
    (value: boolean | ((prev: boolean) => boolean)) => {
      const nextValue = typeof value === 'function' ? value(isOpen) : value
      if (!account) return
      updateMutation.mutate(nextValue)
    },
    [account, isOpen, updateMutation],
  )

  return { isOpen, setIsOpen }
}

/**
 * CLI terminal height (`console.cliShell.heightPx`).
 * Debounces writes while resizing.
 */
export function useCliShellHeight(account: ConsoleAccountCache | undefined) {
  const queryClient = useQueryClient()
  useMigrateLegacyBrowserPrefsToAccount(account)

  const heightPx = parseCliShellHeightPx(
    account?.prefs as UserPrefs | undefined,
  )

  const persistTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  const updateMutation = useMutation({
    mutationFn: async (value: number) => {
      if (!account) {
        throw new Error('Account data not available')
      }
      return await updateAccountPrefs(
        mergeCliShellHeightPxIntoPrefs(
          (account.prefs ?? {}) as UserPrefs,
          value,
        ),
      )
    },
    onMutate: async (value) => {
      const patch = mergeCliShellHeightPxIntoPrefs(
        (account?.prefs ?? {}) as UserPrefs,
        value,
      )
      queryClient.setQueriesData<{ prefs?: Record<string, unknown> }>(
        { queryKey: ['account', 'console'] },
        (current) =>
          current
            ? {
                ...current,
                prefs: { ...current.prefs, ...patch },
              }
            : current,
      )
    },
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
    },
  })

  const setHeightPx = useCallback(
    (value: number | ((prev: number) => number)) => {
      const nextValue =
        typeof value === 'function' ? value(heightPx) : value
      if (!account) return
      if (persistTimerRef.current !== null) {
        clearTimeout(persistTimerRef.current)
      }
      const patch = mergeCliShellHeightPxIntoPrefs(
        (account.prefs ?? {}) as UserPrefs,
        nextValue,
      )
      queryClient.setQueriesData<{ prefs?: Record<string, unknown> }>(
        { queryKey: ['account', 'console'] },
        (current) =>
          current
            ? {
                ...current,
                prefs: { ...current.prefs, ...patch },
              }
            : current,
      )
      persistTimerRef.current = setTimeout(() => {
        persistTimerRef.current = null
        updateMutation.mutate(nextValue)
      }, CLI_SHELL_HEIGHT_PERSIST_DEBOUNCE_MS)
    },
    [account, queryClient, updateMutation, heightPx],
  )

  useEffect(() => {
    return () => {
      if (persistTimerRef.current !== null) {
        clearTimeout(persistTimerRef.current)
      }
    }
  }, [])

  return { heightPx, setHeightPx }
}

const CLI_SHELL_SESSIONS_SIDEBAR_WIDTH_PERSIST_DEBOUNCE_MS = 250

/**
 * CLI terminal sessions list width (`console.cliShell.sessionsSidebarWidthPx`).
 * Debounces writes while resizing.
 */
export function useCliShellSessionsSidebarWidth(
  account: ConsoleAccountCache | undefined,
) {
  const queryClient = useQueryClient()

  const widthPx = parseCliShellSessionsSidebarWidthPx(
    account?.prefs as UserPrefs | undefined,
  )

  const persistTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  const updateMutation = useMutation({
    mutationFn: async (value: number) => {
      if (!account) {
        throw new Error('Account data not available')
      }
      return await updateAccountPrefs(
        mergeCliShellSessionsSidebarWidthPxIntoPrefs(
          (account.prefs ?? {}) as UserPrefs,
          value,
        ),
      )
    },
    onMutate: async (value) => {
      const patch = mergeCliShellSessionsSidebarWidthPxIntoPrefs(
        (account?.prefs ?? {}) as UserPrefs,
        value,
      )
      queryClient.setQueriesData<{ prefs?: Record<string, unknown> }>(
        { queryKey: ['account', 'console'] },
        (current) =>
          current
            ? {
                ...current,
                prefs: { ...current.prefs, ...patch },
              }
            : current,
      )
    },
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
    },
  })

  const persistSidebarWidthPx = useCallback(
    (value: number) => {
      if (!account) return
      if (persistTimerRef.current !== null) {
        clearTimeout(persistTimerRef.current)
      }
      const patch = mergeCliShellSessionsSidebarWidthPxIntoPrefs(
        (account.prefs ?? {}) as UserPrefs,
        value,
      )
      queryClient.setQueriesData<{ prefs?: Record<string, unknown> }>(
        { queryKey: ['account', 'console'] },
        (current) =>
          current
            ? {
                ...current,
                prefs: { ...current.prefs, ...patch },
              }
            : current,
      )
      persistTimerRef.current = setTimeout(() => {
        persistTimerRef.current = null
        updateMutation.mutate(value)
      }, CLI_SHELL_SESSIONS_SIDEBAR_WIDTH_PERSIST_DEBOUNCE_MS)
    },
    [account, queryClient, updateMutation],
  )

  useEffect(() => {
    return () => {
      if (persistTimerRef.current !== null) {
        clearTimeout(persistTimerRef.current)
      }
    }
  }, [])

  return { widthPx, persistSidebarWidthPx }
}

const CLI_SHELL_PREFS_PERSIST_DEBOUNCE_MS = 400

/**
 * Persisted CLI command history for a project (`console.cliShell.history.<projectId>`).
 */
export function useCliShellHistory(
  account: ConsoleAccountCache | undefined,
  projectId: string,
) {
  const queryClient = useQueryClient()
  const history = parseCliShellHistory(
    account?.prefs as UserPrefs | undefined,
    projectId,
  )
  const persistTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  const updateMutation = useMutation({
    mutationFn: async (value: string[]) => {
      if (!account) {
        throw new Error('Account data not available')
      }
      return await updateAccountPrefs(
        mergeCliShellHistoryIntoPrefs(
          (account.prefs ?? {}) as UserPrefs,
          projectId,
          value,
        ),
      )
    },
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
    },
  })

  const persistHistory = useCallback(
    (value: string[]) => {
      if (!account) return
      const patch = mergeCliShellHistoryIntoPrefs(
        (account.prefs ?? {}) as UserPrefs,
        projectId,
        value,
      )
      queryClient.setQueriesData<{ prefs?: Record<string, unknown> }>(
        { queryKey: ['account', 'console'] },
        (current) =>
          current
            ? {
                ...current,
                prefs: { ...current.prefs, ...patch },
              }
            : current,
      )
      if (persistTimerRef.current !== null) {
        clearTimeout(persistTimerRef.current)
      }
      persistTimerRef.current = setTimeout(() => {
        persistTimerRef.current = null
        updateMutation.mutate(value)
      }, CLI_SHELL_PREFS_PERSIST_DEBOUNCE_MS)
    },
    [account, projectId, queryClient, updateMutation],
  )

  useEffect(() => {
    return () => {
      if (persistTimerRef.current !== null) {
        clearTimeout(persistTimerRef.current)
      }
    }
  }, [])

  return { history, persistHistory }
}

/**
 * Persisted CLI session tabs for a project (`console.cliShell.sessions.<projectId>`).
 */
export function useCliShellSessionsPrefs(
  account: ConsoleAccountCache | undefined,
  projectId: string,
) {
  const queryClient = useQueryClient()
  const savedSessions = parseCliShellSessions(
    account?.prefs as UserPrefs | undefined,
    projectId,
  )
  const persistTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  const updateMutation = useMutation({
    mutationFn: async (value: PersistedCliShellSessionsState) => {
      if (!account) {
        throw new Error('Account data not available')
      }
      return await updateAccountPrefs(
        mergeCliShellSessionsIntoPrefs(
          (account.prefs ?? {}) as UserPrefs,
          projectId,
          value,
        ),
      )
    },
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
    },
  })

  const persistSessions = useCallback(
    (value: PersistedCliShellSessionsState) => {
      if (!account) return
      const patch = mergeCliShellSessionsIntoPrefs(
        (account.prefs ?? {}) as UserPrefs,
        projectId,
        value,
      )
      queryClient.setQueriesData<{ prefs?: Record<string, unknown> }>(
        { queryKey: ['account', 'console'] },
        (current) =>
          current
            ? {
                ...current,
                prefs: { ...current.prefs, ...patch },
              }
            : current,
      )
      if (persistTimerRef.current !== null) {
        clearTimeout(persistTimerRef.current)
      }
      persistTimerRef.current = setTimeout(() => {
        persistTimerRef.current = null
        updateMutation.mutate(value)
      }, CLI_SHELL_PREFS_PERSIST_DEBOUNCE_MS)
    },
    [account, projectId, queryClient, updateMutation],
  )

  useEffect(() => {
    return () => {
      if (persistTimerRef.current !== null) {
        clearTimeout(persistTimerRef.current)
      }
    }
  }, [])

  return { savedSessions, persistSessions }
}

/**
 * Whether the user opted out of the build-completion notification prompt.
 */
export function useBuildNotificationsOptedOut(
  account: ConsoleAccountCache | undefined,
) {
  const queryClient = useQueryClient()
  useMigrateLegacyBrowserPrefsToAccount(account)

  const optedOut = parseBuildNotificationsOptedOut(
    account?.prefs as UserPrefs | undefined,
  )

  const updateMutation = useMutation({
    mutationFn: async (value: boolean) => {
      if (!account) {
        throw new Error('Account data not available')
      }
      return await updateAccountPrefs(
        mergeBuildNotificationsOptedOutIntoPrefs(
          (account.prefs ?? {}) as UserPrefs,
          value,
        ),
      )
    },
    onMutate: async (value) => {
      queryClient.setQueriesData<{ prefs?: Record<string, unknown> }>(
        { queryKey: ['account', 'console'] },
        (current) =>
          current
            ? {
                ...current,
                prefs: mergeBuildNotificationsOptedOutIntoPrefs(
                  (current.prefs ?? {}) as UserPrefs,
                  value,
                ),
              }
            : current,
      )
    },
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
    },
  })

  const setOptedOut = useCallback(
    (value: boolean) => {
      if (!account) return
      updateMutation.mutate(value)
    },
    [account, updateMutation],
  )

  return { optedOut, setOptedOut }
}

/**
 * Storage files list / inline preview split: table pane width in px
 * (`console.storageFiles.tablePaneWidthPx`). Persists on demand (e.g. after drag).
 */
export function useStorageFilesTablePaneWidth(
  account: ConsoleAccountCache | undefined,
) {
  const queryClient = useQueryClient()
  useMigrateLegacyBrowserPrefsToAccount(account)

  const tablePaneWidthPx = parseStorageFilesTablePaneWidthPx(
    account?.prefs as UserPrefs | undefined,
  )

  const updateMutation = useMutation({
    mutationFn: async (value: number) => {
      if (!account) {
        throw new Error('Account data not available')
      }
      return await updateAccountPrefs(
        mergeStorageFilesTablePaneWidthPxIntoPrefs(
          (account.prefs ?? {}) as UserPrefs,
          value,
        ),
      )
    },
    onMutate: async (value) => {
      const patch = mergeStorageFilesTablePaneWidthPxIntoPrefs(
        (account?.prefs ?? {}) as UserPrefs,
        value,
      )
      queryClient.setQueriesData<{ prefs?: Record<string, unknown> }>(
        { queryKey: ['account', 'console'] },
        (current) =>
          current
            ? {
                ...current,
                prefs: { ...current.prefs, ...patch },
              }
            : current,
      )
    },
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
    },
  })

  const persistTablePaneWidthPx = useCallback(
    (value: number) => {
      if (!account) return
      updateMutation.mutate(value)
    },
    [account, updateMutation],
  )

  return { tablePaneWidthPx, persistTablePaneWidthPx }
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
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
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
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
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
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
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
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
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
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
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
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
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
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
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
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
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
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
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
