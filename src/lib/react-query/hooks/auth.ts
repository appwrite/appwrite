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
import type { Models } from '@appwrite.io/console' // pragma: allowlist secret
import {
  AppwriteException,
  ProjectAuthMethodId,
  Query,
} from '@appwrite.io/console' // pragma: allowlist secret
import {
  clearConsoleImpersonateUser,
  clearConsoleSessionLocally,
  sdk,
} from '@/lib/appwrite/sdk' // pragma: allowlist secret
import {
  clearConsoleAccountCache,
  getConsoleAccountSync,
  getConsoleAccountUnauthenticatedError,
  setConsoleAccountCache,
} from '@/lib/console-account-cache'
import {
  fetchConsoleAccount,
  getConsoleAccountFromSingleton,
  hasLikelyConsoleSession,
  type FetchConsoleAccountOptions,
} from '@/lib/console-account-get'
import {
  clearConsoleImpersonationSession,
  getConsoleAccountQueryRevision,
  hasConsoleImpersonationSessionTarget,
} from '@/lib/console-impersonation'
import { resolvePostAuthRedirect } from '@/lib/post-auth-navigation'
import { isHttpUnauthorizedError } from '@/lib/utils/error-formatting'
import {
  buildDatabasesSidebarWidthPrefs,
  buildMysqlSqlEditorHeightPrefs,
  buildPostgresSqlEditorHeightPrefs,
  buildSavedFiltersPrefs,
  buildSavedImageTransformPresetsPrefs,
  buildStorageSidebarWidthPrefs,
  DATABASES_SIDEBAR_DEFAULT_WIDTH_PX,
  MYSQL_SQL_EDITOR_DEFAULT_HEIGHT_PX,
  POSTGRES_SQL_EDITOR_DEFAULT_HEIGHT_PX,
  parseDatabasesSidebarWidthPx,
  parseMysqlSqlEditorHeightPx,
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
  writeRecentImpersonationDetails,
  clearLegacyAIChatLocalStorage,
  clearLegacyBuildNotificationsOptedOutLocalStorage,
  clearLegacyCliShellHeightLocalStorage,
  clearLegacyStorageFilesTablePaneWidthLocalStorage,
  hasAIChatPanelOpenPref,
  hasAIChatPanelWidthPref,
  hasRightPaneWidthPref,
  hasBuildNotificationsOptedOutPref,
  hasCliShellHeightPref,
  hasStorageFilesTablePaneWidthPref,
  mergeAIChatActiveConversationIdIntoPrefs,
  mergeAIChatConversationsWidthPxIntoPrefs,
  mergeAIChatExpandedIntoPrefs,
  mergeAIChatPanelOpenIntoPrefs,
  mergeAIChatPanelWidthPxIntoPrefs,
  mergeAIChatPinnedConversationIdsIntoPrefs,
  mergeRightPaneWidthPxIntoPrefs,
  mergeAuthPasswordStrengthComplianceOpenIntoPrefs,
  mergeApiExplorerColumnsLayoutIntoPrefs,
  mergeApiExplorerExpandedProductGroupIntoPrefs,
  mergeApiExplorerResponseSplitLayoutIntoPrefs,
  mergeCoverGeneratorColumnsLayoutIntoPrefs,
  mergeDiagramGeneratorPropertiesSplitLayoutIntoPrefs,
  mergeGeneratorPanelVisibilityIntoPrefs,
  mergeBuildNotificationsOptedOutIntoPrefs,
  mergeCliShellHeightPxIntoPrefs,
  mergeCliShellHistoryIntoPrefs,
  mergeCliShellOpenIntoPrefs,
  mergeCliShellSessionsIntoPrefs,
  mergeCliShellSessionsSidebarWidthPxIntoPrefs,
  mergeConnectProjectTabIntoPrefs,
  mergeSidebarCollapsedIntoPrefs,
  getCliShellSessionsKey,
  parseCliShellHistory,
  parseCliShellSessions,
  serializeCliShellSessionsState,
  type PersistedCliShellSessionsState,
  mergeStorageFilesTablePaneWidthPxIntoPrefs,
  parseAIChatActiveConversationId,
  parseAIChatConversationsWidthPx,
  parseAIChatExpanded,
  parseAIChatPanelOpen,
  parseAIChatPanelWidthPx,
  parseAIChatPinnedConversationIds,
  parseRightPaneWidthPx,
  parseAuthPasswordStrengthComplianceOpen,
  parseApiExplorerColumnsLayout,
  parseApiExplorerExpandedProductGroup,
  parseApiExplorerResponseSplitLayout,
  parseCoverGeneratorColumnsLayout,
  parseDiagramGeneratorPropertiesSplitLayout,
  parseGeneratorPanelVisibility,
  parseBuildNotificationsOptedOut,
  parseCliShellHeightPx,
  parseCliShellOpen,
  parseCliShellSessionsSidebarWidthPx,
  parseConnectProjectTab,
  parseSidebarCollapsed,
  parseStorageFilesTablePaneWidthPx,
  readLegacyAIChatPanelOpenFromLocalStorage,
  readLegacyAIChatPanelWidthFromLocalStorage,
  readLegacyBuildNotificationsOptedOutFromLocalStorage,
  readLegacyCliShellHeightFromLocalStorage,
  readLegacyStorageFilesTablePaneWidthFromLocalStorage,
  sanitizeAccountPrefsForWrite,
  USER_PREFS_KEY_FEATURE_NOTIFICATIONS,
  type UserPrefs,
} from '@/lib/user-prefs-keys'
import {
  API_EXPLORER_COLUMNS_DEFAULT_LAYOUT,
  API_EXPLORER_RESPONSE_SPLIT_DEFAULT_LAYOUT,
  COVER_GENERATOR_COLUMNS_DEFAULT_LAYOUT,
  DIAGRAM_GENERATOR_PROPERTIES_SPLIT_DEFAULT_LAYOUT,
  normalizeApiExplorerColumnsLayout,
  normalizeApiExplorerResponseSplitLayout,
  normalizeCoverGeneratorColumnsLayout,
  normalizeDiagramGeneratorPropertiesSplitLayout,
} from '@/lib/resizable-layout'
import {
  GENERATOR_PANEL_VISIBILITY_DEFAULT,
  readGeneratorPanelVisibilityFromStorage,
  writeGeneratorPanelVisibilityToStorage,
  type GeneratorPanelVisibility,
} from '@/lib/generator/panel-visibility'
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

/** Keep settled account state for the session (including guest 401). */
export const CONSOLE_ACCOUNT_GC_TIME_MS = Number.POSITIVE_INFINITY

export type { FetchConsoleAccountOptions }
export {
  fetchConsoleAccount,
  getConsoleAccountFromSingleton as getConsoleAccountSync,
} from '@/lib/console-account-get'
export { clearConsoleAccountCache, setConsoleAccountCache }

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
    // Prefer the API prefs as source of truth. Merging cached prefs back in
    // reintroduced nested/legacy keys and bloated the client prefs bag until
    // the next write exceeded Appwrite's 64KB Assoc limit.
    next = cached
      ? ({
          ...cached,
          ...options.apiResult,
          prefs: sanitizeAccountPrefsForWrite(
            (options.apiResult.prefs ?? {}) as Record<string, unknown>,
          ),
        } as Models.User)
      : ({
          ...options.apiResult,
          prefs: sanitizeAccountPrefsForWrite(
            (options.apiResult.prefs ?? {}) as Record<string, unknown>,
          ),
        } as Models.User)
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
 * Console `account.get` - shared by route loaders (prefetch prefs before child
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
    gcTime: CONSOLE_ACCOUNT_GC_TIME_MS,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    enabled: typeof window !== 'undefined',
  })
}

export function isConsoleAccountQuerySettled(
  queryClient: QueryClient,
  revision?: number,
): boolean {
  const { queryKey } = consoleAccountQueryOptions({ revision })
  const state = queryClient.getQueryState(queryKey)
  return state?.status === 'success' || state?.status === 'error'
}

export function isConsoleMfaRequiredError(error: unknown): boolean {
  return (
    error instanceof AppwriteException &&
    error.type === 'user_more_factors_required'
  )
}

/** Drop module singleton + React Query account entries (e.g. before MFA challenge). */
export function purgeConsoleAccountCaches(queryClient: QueryClient): void {
  clearConsoleAccountCache()
  void queryClient.cancelQueries({ queryKey: ['account', 'console'] })
  queryClient.removeQueries({ queryKey: ['account', 'console'] })
}

const CONSOLE_SIGN_OUT_COVER_ID = 'console-sign-out-cover'

/** True while sign-out is in progress (covers SPA auth redirects / UI thrash). */
let consoleSigningOut = false

export function isConsoleSigningOut(): boolean {
  return consoleSigningOut
}

/**
 * Opaque full-viewport cover so async session delete cannot flash logged-out
 * chrome, empty RequireAuth fallbacks, or SPA /sign-in transitions.
 */
function showConsoleSignOutCover(): void {
  if (typeof document === 'undefined') return
  if (document.getElementById(CONSOLE_SIGN_OUT_COVER_ID)) return
  const cover = document.createElement('div')
  cover.id = CONSOLE_SIGN_OUT_COVER_ID
  cover.setAttribute('aria-busy', 'true')
  cover.setAttribute('aria-live', 'polite')
  cover.style.cssText = [
    'position:fixed',
    'inset:0',
    'z-index:2147483647',
    // Match themed app background (avoids white/black flash across light/dark).
    'background:var(--background)',
    'pointer-events:auto',
  ].join(';')
  document.documentElement.appendChild(cover)
}

/** Hard navigation so protected routes (org overview) do not flash during SPA transitions. */
export function redirectToSignInAfterConsoleSignOut(redirect?: string): void {
  if (typeof window === 'undefined') return
  const isValidRelativeRedirect =
    !!redirect &&
    redirect.startsWith('/') &&
    !redirect.startsWith('//') &&
    !redirect.includes('://')
  window.location.replace(
    isValidRelativeRedirect
      ? `/sign-in?redirect=${encodeURIComponent(redirect)}`
      : '/sign-in',
  )
}

/**
 * Clear client auth state, best-effort server session delete, then open sign-in.
 *
 * Keep React Query account data until the hard redirect so the console does not
 * briefly render as logged-out (RequireAuth fallback, header guest state, SPA
 * /sign-in navigation) while deleteSession is in flight.
 *
 * Server revoke must succeed for other apps/domains sharing the same Appwrite
 * session cookie to be logged out. Local cookie clearing only affects
 * non-httpOnly cookies on this document host; the real session cookie is
 * httpOnly on the API host and is cleared via Set-Cookie on deleteSession.
 */
export async function performConsoleSignOut(
  queryClient: QueryClient,
  options?: { redirect?: string },
): Promise<void> {
  if (consoleSigningOut) return
  consoleSigningOut = true
  showConsoleSignOutCover()

  // Drop impersonation headers only. Do not clear session credentials before
  // the delete call or the API request may go out unauthenticated.
  clearConsoleImpersonateUser()
  clearConsoleImpersonationSession()

  try {
    // Preferred Appwrite logout: revoke the current server session by id
    // alias. Avoid listSessions-first (can skip delete when the list is empty
    // or `current` is missing) so cross-domain consoles sharing this session
    // are actually invalidated.
    await sdk.forConsole.account.deleteSession({ sessionId: 'current' })
  } catch (error) {
    console.error('Error signing out (deleteSession current):', error)
    // Fallback if `current` is rejected: resolve the active session id, then
    // revoke it. Last resort: wipe all account sessions.
    try {
      const sessionsResponse = await sdk.forConsole.account.listSessions()
      const sessions = sessionsResponse.sessions || []
      const currentSession = sessions.find((session) => session.current === true)

      if (currentSession) {
        await sdk.forConsole.account.deleteSession({
          sessionId: currentSession.$id,
        })
      } else if (sessions.length > 0) {
        await sdk.forConsole.account.deleteSessions()
      }
    } catch (fallbackError) {
      console.error('Error signing out (fallback):', fallbackError)
    }
  } finally {
    clearConsoleSessionLocally()
    purgeConsoleAccountCaches(queryClient)
    redirectToSignInAfterConsoleSignOut(options?.redirect)
  }
}

type ConsoleMfaNavigate = (options: {
  to: string
  search?: { redirect?: string }
}) => void

/** Purge stale guest account cache and open the MFA challenge route. */
export function redirectToConsoleMfaAfterSession(
  queryClient: QueryClient,
  navigate: ConsoleMfaNavigate,
  redirect?: string,
): void {
  void navigateToConsoleMfaAfterSession(queryClient, navigate, redirect)
}

/** Prefetch MFA factors while still on the previous auth screen. */
export async function prefetchConsoleMfaRouteData(
  queryClient: QueryClient,
): Promise<void> {
  purgeConsoleAccountCaches(queryClient)
  await queryClient.ensureQueryData(mfaFactorsQueryOptions())
}

/** Purge account cache, prefetch MFA data, then navigate once the MFA route can render. */
export async function navigateToConsoleMfaAfterSession(
  queryClient: QueryClient,
  navigate: ConsoleMfaNavigate,
  redirect?: string,
): Promise<void> {
  await prefetchConsoleMfaRouteData(queryClient)
  const redirectUrl = resolvePostAuthRedirect(redirect)
  navigate({
    to: '/mfa',
    search: redirectUrl ? { redirect: redirectUrl } : undefined,
  })
}

/** Guest 401 may be cached while a session cookie exists (e.g. right after sign-in). */
export function shouldRevalidateConsoleAccount(
  queryClient: QueryClient,
  revision: number = getConsoleAccountQueryRevision(),
): boolean {
  const cachedAccount = getConsoleAccountFromCache(queryClient)
  if (cachedAccount && isConsoleAccountUser(cachedAccount)) return false

  if (getConsoleAccountUnauthenticatedError(revision)) return true

  const { queryKey } = consoleAccountQueryOptions({ revision })
  const state = queryClient.getQueryState(queryKey)
  if (state?.status === 'error' && isHttpUnauthorizedError(state.error)) {
    return true
  }
  if (state?.status === 'error' && isConsoleMfaRequiredError(state.error)) {
    return true
  }

  return false
}

/** Auth routes: also fetch when a session exists but account is not in any cache yet. */
export function shouldRevalidateConsoleAccountOnAuthRoute(
  queryClient: QueryClient,
  revision: number = getConsoleAccountQueryRevision(),
): boolean {
  if (shouldRevalidateConsoleAccount(queryClient, revision)) return true
  if (!hasLikelyConsoleSession()) return false

  const { queryKey } = consoleAccountQueryOptions({ revision })
  const hasAccount =
    getConsoleAccountFromSingleton(revision) ??
    queryClient.getQueryData<Models.User>(queryKey)
  return !hasAccount
}

/**
 * Force a fresh `account.get`, then sync the module singleton and React Query.
 * Use after sign-in, sign-up, MFA, and when clearing stale guest cache on auth pages.
 */
export async function refreshConsoleAccountAfterAuth(
  queryClient: QueryClient,
): Promise<Models.User> {
  purgeConsoleAccountCaches(queryClient)
  const revision = getConsoleAccountQueryRevision()
  const account = await fetchConsoleAccount({ revision, force: true })
  commitConsoleAccountToCaches(queryClient, account, revision)
  return account
}

export async function ensureConsoleAccountOnAuthRoute(
  queryClient: QueryClient,
): Promise<void> {
  if (!shouldRevalidateConsoleAccountOnAuthRoute(queryClient)) return
  try {
    await refreshConsoleAccountAfterAuth(queryClient)
  } catch {
    // Guest on auth pages is expected.
  }
}

/** Prefetch or refresh console account for protected route loaders. */
export async function ensureConsoleAccountQueryData(
  queryClient: QueryClient,
): Promise<Models.User | undefined> {
  if (shouldRevalidateConsoleAccount(queryClient)) {
    try {
      return await refreshConsoleAccountAfterAuth(queryClient)
    } catch {
      return undefined
    }
  }

  try {
    return await queryClient.ensureQueryData(consoleAccountQueryOptions())
  } catch {
    return undefined
  }
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
  updateDenyCorporateEmailPolicy: (params: {
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

/**
 * Hook to update the deny corporate email policy.
 */
export function useUpdateDenyCorporateEmailPolicy(
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
      ).updateDenyCorporateEmailPolicy({ enabled })
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
  denyCorporateEmail: boolean
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
        service.updateDenyCorporateEmailPolicy({
          enabled: policies.denyCorporateEmail,
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
 *
 * Pages through the full list: the API caps each response at its default
 * limit (25), and consumers like the connected-applications page need every
 * identity to filter and group locally.
 */
export async function fetchAccountIdentities() {
  const pageSize = 100
  const maxPages = 50 // safety cap against runaway requests: 5,000 identities
  const identities: Models.Identity[] = []
  let total = 0

  for (let page = 0; page < maxPages; page++) {
    const response = await sdk.forConsole.account.listIdentities({
      queries: [
        Query.limit(pageSize),
        Query.offset(identities.length),
        Query.orderDesc('$createdAt'),
      ],
    })
    const chunk = response.identities || []
    identities.push(...chunk)
    total = response.total || 0

    if (chunk.length < pageSize || identities.length >= total) break
  }

  return { identities, total }
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
 * Diff previous account prefs against the sanitized write payload.
 * Used only for console debug logging in `updateAccountPrefs`.
 */
function diffAccountPrefs(
  previous: Record<string, unknown> | undefined,
  next: Record<string, string | number | boolean>,
): {
  added: Record<string, unknown>
  changed: Record<string, { from: unknown; to: unknown }>
  removed: string[]
} {
  const prev = previous ?? {}
  const added: Record<string, unknown> = {}
  const changed: Record<string, { from: unknown; to: unknown }> = {}
  const removed: string[] = []

  for (const key of Object.keys(next)) {
    if (!(key in prev)) {
      added[key] = next[key]
      continue
    }
    if (prev[key] !== next[key]) {
      changed[key] = { from: prev[key], to: next[key] }
    }
  }
  for (const key of Object.keys(prev)) {
    if (!(key in next)) {
      removed.push(key)
    }
  }

  return { added, changed, removed }
}

function getAccountPrefsCallerStack(): string[] {
  return (
    new Error().stack
      ?.split('\n')
      .slice(1)
      .map((line) => line.trim().replace(/^at\s+/, ''))
      .filter((line) => {
        if (!line) return false
        if (line.includes('updateAccountPrefs')) return false
        if (line.includes('getAccountPrefsCallerStack')) return false
        if (line.includes('diffAccountPrefs')) return false
        if (line.includes('node_modules')) return false
        if (line.includes('@tanstack')) return false
        return true
      })
      .slice(0, 8) ?? []
  )
}

/**
 * Mutation function to update account preferences.
 * Sanitizes nested/legacy values so Appwrite does not 400 on write.
 * Silently skips while console impersonation is active so the target user's prefs are not mutated.
 *
 * When skipping, returns `undefined` (not a partial User). Callers that sync the
 * account cache must treat a missing result as a no-op so they do not poison
 * React Query with `{ prefs }` only - that crashed account UI after impersonation.
 *
 * @param reason - Short label for debug logs (which feature/hook requested the write).
 */
export async function updateAccountPrefs(
  prefs: Record<string, unknown>,
  reason = 'unknown',
): Promise<Models.User | undefined> {
  if (hasConsoleImpersonationSessionTarget()) {
    return undefined
  }

  const sanitized = sanitizeAccountPrefsForWrite(prefs)
  const cachedAccount = getConsoleAccountSync(getConsoleAccountQueryRevision())
  const previous = cachedAccount?.prefs as Record<string, unknown> | undefined
  const diff = diffAccountPrefs(previous, sanitized)
  const hasDiff =
    Object.keys(diff.added).length > 0 ||
    Object.keys(diff.changed).length > 0 ||
    diff.removed.length > 0
  const caller = getAccountPrefsCallerStack()

  if (!hasDiff) {
    console.log('[account prefs] skip (unchanged)', {
      reason,
      keyCount: Object.keys(sanitized).length,
      caller,
    })
    // Avoid a no-op `account.updatePrefs` round-trip. Callers treat a returned
    // User like a successful write; fall back to undefined only if uncached.
    return cachedAccount
  }

  console.log('[account prefs] update', diff, {
    reason,
    keyCount: Object.keys(sanitized).length,
    caller,
  })

  return (await sdk.forConsole.account.updatePrefs({
    prefs: sanitized,
  })) as Models.User
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
  // Labels stay in localStorage; account prefs only get ID references.
  writeRecentImpersonationDetails(operatorId, list)
  const account = await fetchConsoleAccount({ force: true })
  const fromPrefs = parseRecentImpersonationUsers(
    account.prefs as UserPrefs,
    operatorId,
  )
  const merged = mergeRecentImpersonationLists(fromPrefs, list)
  writeRecentImpersonationDetails(operatorId, merged)
  const updatedPrefs = mergeRecentImpersonationIntoAccountPrefs(
    account.prefs as UserPrefs,
    merged,
  )
  const updatedAccount = await updateAccountPrefs(updatedPrefs, 'flush-recent-impersonation-users')
  setConsoleAccountCache(
    updatedAccount && isConsoleAccountUser(updatedAccount)
      ? updatedAccount
      : ({ ...account, prefs: updatedPrefs } as Models.User),
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
      const currentNotificationsRaw =
        account.prefs?.[USER_PREFS_KEY_FEATURE_NOTIFICATIONS]

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
        [USER_PREFS_KEY_FEATURE_NOTIFICATIONS]: updatedNotificationsStr,
      }

      return await updateAccountPrefs(updatedPrefs, 'feature-notifications')
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
 * Uses `console.sidebarCollapsed` in account prefs.
 *
 * Must be used within RequireAuth (or where account is available).
 */
export function useSidebarCollapsed(
  account: { prefs?: Record<string, unknown> } | undefined,
) {
  const queryClient = useQueryClient()

  const accountPrefs = account?.prefs as UserPrefs | undefined
  const collapsed = parseSidebarCollapsed(accountPrefs)

  const updateMutation = useMutation({
    mutationFn: async (value: boolean) => {
      if (!account) {
        throw new Error('Account data not available')
      }
      return await updateAccountPrefs(
        mergeSidebarCollapsedIntoPrefs(
          { ...(account.prefs ?? {}) },
          value,
        ),
        'sidebar-collapsed',
      )
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
                prefs: mergeSidebarCollapsedIntoPrefs(
                  { ...(current.prefs ?? {}) },
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

  const setCollapsed = useCallback(
    (value: boolean | ((prev: boolean) => boolean)) => {
      const nextValue = typeof value === 'function' ? value(collapsed) : value
      updateMutation.mutate(nextValue)
    },
    [collapsed, updateMutation],
  )

  return { collapsed, setCollapsed }
}

/**
 * Last selected tab in the Connect project dialog (`console.connect.tab`).
 *
 * Must be used within RequireAuth (or where account is available).
 */
export function useConnectProjectTab(
  account: { prefs?: Record<string, unknown> } | undefined,
) {
  const queryClient = useQueryClient()
  const accountPrefs = account?.prefs as UserPrefs | undefined
  const tab = parseConnectProjectTab(accountPrefs)

  const updateMutation = useMutation({
    mutationFn: async (value: ReturnType<typeof parseConnectProjectTab>) => {
      if (!account) {
        throw new Error('Account data not available')
      }
      return await updateAccountPrefs(
        mergeConnectProjectTabIntoPrefs(
          { ...(account.prefs ?? {}) },
          value,
        ),
        'connect-project-tab',
      )
    },
    onMutate: async (value) => {
      queryClient.setQueriesData<{ prefs?: Record<string, unknown> }>(
        { queryKey: ['account', 'console'] },
        (current) =>
          current
            ? {
                ...current,
                prefs: mergeConnectProjectTabIntoPrefs(
                  { ...(current.prefs ?? {}) },
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

  const setTab = useCallback(
    (
      value:
        | ReturnType<typeof parseConnectProjectTab>
        | ((
            prev: ReturnType<typeof parseConnectProjectTab>,
          ) => ReturnType<typeof parseConnectProjectTab>),
    ) => {
      const nextValue = typeof value === 'function' ? value(tab) : value
      if (nextValue === tab) return
      updateMutation.mutate(nextValue)
    },
    [tab, updateMutation],
  )

  return { tab, setTab }
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
      },
        'table-view-sidebar-width')
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
      },
        'postgres-sql-editor-height')
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

/**
 * Persisted SQL editor container height in px for the MySQL SQL workbench.
 */
export function useMysqlSqlEditorHeight(
  account: { prefs?: Record<string, unknown> } | undefined,
) {
  const queryClient = useQueryClient()

  const heightPx =
    parseMysqlSqlEditorHeightPx(account?.prefs as UserPrefs | undefined) ??
    MYSQL_SQL_EDITOR_DEFAULT_HEIGHT_PX

  const updateMutation = useMutation({
    mutationFn: async (value: number) => {
      if (!account) {
        throw new Error('Account data not available')
      }
      return await updateAccountPrefs({
        ...account.prefs,
        ...buildMysqlSqlEditorHeightPrefs(value),
      },
        'mysql-sql-editor-height')
    },
    onMutate: async (value) => {
      const patch = buildMysqlSqlEditorHeightPrefs(value)
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
        'persisted-panel-layout',
      )
    },
    onMutate: async (value) => {
      queryClient.setQueriesData<{ prefs?: Record<string, unknown> }>(
        { queryKey: ['account', 'console'] },
        (current) =>
          current
            ? {
                ...current,
                prefs: mergeIntoPrefs(
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

/** Templates | canvas | properties column split (`console.coverGenerator.columnsLayout`). */
export function useCoverGeneratorColumnsLayout(
  account: ConsoleAccountCache | undefined,
) {
  return usePersistedPanelLayoutPref(
    account,
    parseCoverGeneratorColumnsLayout,
    normalizeCoverGeneratorColumnsLayout,
    COVER_GENERATOR_COLUMNS_DEFAULT_LAYOUT,
    mergeCoverGeneratorColumnsLayoutIntoPrefs,
  )
}

/** Diagram generator: properties | layers split in diagram generator (`console.diagramGenerator.propertiesSplitLayout`). */
export function useDiagramGeneratorPropertiesSplitLayout(
  account: ConsoleAccountCache | undefined,
) {
  return usePersistedPanelLayoutPref(
    account,
    parseDiagramGeneratorPropertiesSplitLayout,
    normalizeDiagramGeneratorPropertiesSplitLayout,
    DIAGRAM_GENERATOR_PROPERTIES_SPLIT_DEFAULT_LAYOUT,
    mergeDiagramGeneratorPropertiesSplitLayoutIntoPrefs,
  )
}

/** Left/right panel visibility for the generator (`console.generator.panelVisibility`). */
export function useGeneratorPanelVisibility(
  account: ConsoleAccountCache | undefined,
) {
  const queryClient = useQueryClient()
  const hydratedRef = useRef(false)
  const visibilityRef = useRef<GeneratorPanelVisibility>(
    GENERATOR_PANEL_VISIBILITY_DEFAULT,
  )
  const [visibility, setVisibility] = useState<GeneratorPanelVisibility>(() =>
    readGeneratorPanelVisibilityFromStorage(),
  )

  useEffect(() => {
    if (!account) {
      hydratedRef.current = false
      return
    }
    if (hydratedRef.current) return
    hydratedRef.current = true
    const parsed =
      parseGeneratorPanelVisibility(account.prefs as UserPrefs | undefined) ??
      readGeneratorPanelVisibilityFromStorage()
    visibilityRef.current = parsed
    setVisibility(parsed)
  }, [account])

  const updateMutation = useMutation({
    mutationFn: async (value: GeneratorPanelVisibility) => {
      if (!account) {
        throw new Error('Account data not available')
      }
      return await updateAccountPrefs(
        mergeGeneratorPanelVisibilityIntoPrefs(
          (account.prefs ?? {}) as UserPrefs,
          value,
        ),
        'generator-panel-visibility',
      )
    },
    onMutate: async (value) => {
      queryClient.setQueriesData<{ prefs?: Record<string, unknown> }>(
        { queryKey: ['account', 'console'] },
        (current) =>
          current
            ? {
                ...current,
                prefs: mergeGeneratorPanelVisibilityIntoPrefs(
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

  const persistVisibility = useCallback(
    (value: GeneratorPanelVisibility) => {
      writeGeneratorPanelVisibilityToStorage(value)
      if (account) updateMutation.mutate(value)
    },
    [account, updateMutation],
  )

  const setLeftOpen = useCallback(
    (open: boolean | ((prev: boolean) => boolean)) => {
      setVisibility((prev) => {
        const nextLeft = typeof open === 'function' ? open(prev.left) : open
        if (nextLeft === prev.left) return prev
        const next = { ...prev, left: nextLeft }
        visibilityRef.current = next
        persistVisibility(next)
        return next
      })
    },
    [persistVisibility],
  )

  const setRightOpen = useCallback(
    (open: boolean | ((prev: boolean) => boolean)) => {
      setVisibility((prev) => {
        const nextRight = typeof open === 'function' ? open(prev.right) : open
        if (nextRight === prev.right) return prev
        const next = { ...prev, right: nextRight }
        visibilityRef.current = next
        persistVisibility(next)
        return next
      })
    },
    [persistVisibility],
  )

  const toggleLeft = useCallback(() => {
    setLeftOpen((prev) => !prev)
  }, [setLeftOpen])

  const toggleRight = useCallback(() => {
    setRightOpen((prev) => !prev)
  }, [setRightOpen])

  return {
    leftOpen: visibility.left,
    rightOpen: visibility.right,
    setLeftOpen,
    setRightOpen,
    toggleLeft,
    toggleRight,
  }
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
        'api-explorer-expanded-product-group',
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
const RIGHT_PANE_WIDTH_PERSIST_DEBOUNCE_MS = 250

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

  if (!hasRightPaneWidthPref(prefs)) {
    if (hasAIChatPanelWidthPref(next)) {
      next = mergeRightPaneWidthPxIntoPrefs(
        next,
        parseAIChatPanelWidthPx(next),
      )
      changed = true
    } else {
      const legacyWidth = readLegacyAIChatPanelWidthFromLocalStorage()
      if (legacyWidth !== null) {
        next = mergeRightPaneWidthPxIntoPrefs(next, legacyWidth)
        changed = true
      }
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

  const updatedAccount = await updateAccountPrefs(next, 'migrate-legacy-browser-prefs')
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
        'ai-chat-panel-open',
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
      if (nextValue === isOpen) return
      updateMutation.mutate(nextValue)
    },
    [account, isOpen, updateMutation],
  )

  return { isOpen, setIsOpen }
}

/**
 * AI assistant fullscreen/expanded state (`console.aiChat.expanded`).
 */
export function useAIChatExpanded(account: ConsoleAccountCache | undefined) {
  const queryClient = useQueryClient()

  const isExpanded = parseAIChatExpanded(
    account?.prefs as UserPrefs | undefined,
  )

  const updateMutation = useMutation({
    mutationFn: async (value: boolean) => {
      if (!account) {
        throw new Error('Account data not available')
      }
      return await updateAccountPrefs(
        mergeAIChatExpandedIntoPrefs(
          (account.prefs ?? {}) as UserPrefs,
          value,
        ),
        'ai-chat-expanded',
      )
    },
    onMutate: async (value) => {
      queryClient.setQueriesData<{ prefs?: Record<string, unknown> }>(
        { queryKey: ['account', 'console'] },
        (current) =>
          current
            ? {
                ...current,
                prefs: mergeAIChatExpandedIntoPrefs(
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

  const setIsExpanded = useCallback(
    (value: boolean | ((prev: boolean) => boolean)) => {
      const nextValue = typeof value === 'function' ? value(isExpanded) : value
      if (!account) return
      if (nextValue === isExpanded) return
      updateMutation.mutate(nextValue)
    },
    [account, isExpanded, updateMutation],
  )

  return { isExpanded, setIsExpanded }
}

/**
 * Last viewed AI assistant conversation (`console.aiChat.activeConversationId`).
 */
export function useAIChatActiveConversationId(
  account: ConsoleAccountCache | undefined,
) {
  const queryClient = useQueryClient()

  const activeConversationId = parseAIChatActiveConversationId(
    account?.prefs as UserPrefs | undefined,
  )
  const activeConversationIdRef = useRef(activeConversationId)
  activeConversationIdRef.current = activeConversationId
  const accountRef = useRef(account)
  accountRef.current = account

  const updateMutation = useMutation({
    mutationFn: async (value: string | null) => {
      const currentAccount =
        getConsoleAccountFromCache(queryClient) ?? accountRef.current
      if (!currentAccount) {
        throw new Error('Account data not available')
      }
      return await updateAccountPrefs(
        mergeAIChatActiveConversationIdIntoPrefs(
          (currentAccount.prefs ?? {}) as UserPrefs,
          value,
        ),
        'ai-chat-active-conversation',
      )
    },
    onMutate: async (value) => {
      queryClient.setQueriesData<{ prefs?: Record<string, unknown> }>(
        { queryKey: ['account', 'console'] },
        (current) =>
          current
            ? {
                ...current,
                prefs: mergeAIChatActiveConversationIdIntoPrefs(
                  (current.prefs ?? {}) as UserPrefs,
                  value,
                ),
              }
            : current,
      )
    },
    onSuccess: (updatedAccount, value) => {
      // A newer selection may have already been applied optimistically while
      // this request was in flight. Do not clobber it with a stale response.
      const cachedId = parseAIChatActiveConversationId(
        getConsoleAccountFromCache(queryClient)?.prefs as UserPrefs | undefined,
      )
      if (cachedId !== value) return
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
    },
  })

  const mutateActiveConversationId = updateMutation.mutate

  const setActiveConversationId = useCallback(
    (value: string | null | ((prev: string | null) => string | null)) => {
      const previous = activeConversationIdRef.current
      const nextValue = typeof value === 'function' ? value(previous) : value
      const normalized =
        typeof nextValue === 'string' && nextValue.trim()
          ? nextValue.trim()
          : null
      if (normalized === previous) return
      if (!accountRef.current) return
      activeConversationIdRef.current = normalized
      mutateActiveConversationId(normalized)
    },
    [mutateActiveConversationId],
  )

  return { activeConversationId, setActiveConversationId }
}

/**
 * Pinned AI assistant conversation ids (`console.aiChat.pinnedConversationIds`).
 * Array order is the pinned sort order.
 */
export function useAIChatPinnedConversationIds(
  account: ConsoleAccountCache | undefined,
) {
  const queryClient = useQueryClient()

  const pinnedConversationIds = parseAIChatPinnedConversationIds(
    account?.prefs as UserPrefs | undefined,
  )

  const updateMutation = useMutation({
    mutationFn: async (value: string[]) => {
      if (!account) {
        throw new Error('Account data not available')
      }
      return await updateAccountPrefs(
        mergeAIChatPinnedConversationIdsIntoPrefs(
          (account.prefs ?? {}) as UserPrefs,
          value,
        ),
        'ai-chat-pinned-conversations',
      )
    },
    onMutate: async (value) => {
      queryClient.setQueriesData<{ prefs?: Record<string, unknown> }>(
        { queryKey: ['account', 'console'] },
        (current) =>
          current
            ? {
                ...current,
                prefs: mergeAIChatPinnedConversationIdsIntoPrefs(
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

  const setPinnedConversationIds = useCallback(
    (value: string[] | ((prev: string[]) => string[])) => {
      const nextValue =
        typeof value === 'function' ? value(pinnedConversationIds) : value
      if (!account) return
      const normalized = nextValue
        .map((id) => id.trim())
        .filter((id, index, all) => !!id && all.indexOf(id) === index)
      if (
        normalized.length === pinnedConversationIds.length &&
        normalized.every((id, index) => id === pinnedConversationIds[index])
      ) {
        return
      }
      updateMutation.mutate(normalized)
    },
    [account, pinnedConversationIds, updateMutation],
  )

  const pinConversation = useCallback(
    (conversationId: string) => {
      const id = conversationId.trim()
      if (!id) return
      setPinnedConversationIds((current) =>
        current.includes(id) ? current : [id, ...current],
      )
    },
    [setPinnedConversationIds],
  )

  const unpinConversation = useCallback(
    (conversationId: string) => {
      const id = conversationId.trim()
      if (!id) return
      setPinnedConversationIds((current) =>
        current.filter((entry) => entry !== id),
      )
    },
    [setPinnedConversationIds],
  )

  return {
    pinnedConversationIds,
    setPinnedConversationIds,
    pinConversation,
    unpinConversation,
  }
}

const AI_CHAT_CONVERSATIONS_WIDTH_PERSIST_DEBOUNCE_MS = 250

/**
 * AI assistant conversations sidebar width
 * (`console.aiChat.conversationsWidthPx`). Debounces writes while resizing.
 */
export function useAIChatConversationsWidth(
  account: ConsoleAccountCache | undefined,
) {
  const queryClient = useQueryClient()

  const widthPx = parseAIChatConversationsWidthPx(
    account?.prefs as UserPrefs | undefined,
  )

  const persistTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  const updateMutation = useMutation({
    mutationFn: async (value: number) => {
      if (!account) {
        throw new Error('Account data not available')
      }
      return await updateAccountPrefs(
        mergeAIChatConversationsWidthPxIntoPrefs(
          (account.prefs ?? {}) as UserPrefs,
          value,
        ),
        'ai-chat-conversations-width',
      )
    },
    onMutate: async (value) => {
      const patch = mergeAIChatConversationsWidthPxIntoPrefs(
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
      const patch = mergeAIChatConversationsWidthPxIntoPrefs(
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
      }, AI_CHAT_CONVERSATIONS_WIDTH_PERSIST_DEBOUNCE_MS)
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

/**
 * Password strength compliance section open state
 * (`console.auth.passwordStrengthComplianceOpen`).
 */
export function useAuthPasswordStrengthComplianceOpen(
  account: ConsoleAccountCache | undefined,
) {
  const queryClient = useQueryClient()

  const isOpen = parseAuthPasswordStrengthComplianceOpen(
    account?.prefs as UserPrefs | undefined,
  )

  const updateMutation = useMutation({
    mutationFn: async (value: boolean) => {
      if (!account) {
        throw new Error('Account data not available')
      }
      return await updateAccountPrefs(
        mergeAuthPasswordStrengthComplianceOpenIntoPrefs(
          (account.prefs ?? {}) as UserPrefs,
          value,
        ),
        'auth-password-strength-compliance-open',
      )
    },
    onMutate: async (value) => {
      queryClient.setQueriesData<{ prefs?: Record<string, unknown> }>(
        { queryKey: ['account', 'console'] },
        (current) =>
          current
            ? {
                ...current,
                prefs: mergeAuthPasswordStrengthComplianceOpenIntoPrefs(
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
 * Shared console right pane width (`console.rightPane.widthPx`).
 * Debounces writes while resizing.
 */
export function useRightPaneWidth(
  account: ConsoleAccountCache | undefined,
) {
  const queryClient = useQueryClient()
  useMigrateLegacyBrowserPrefsToAccount(account)

  const widthPx = parseRightPaneWidthPx(
    account?.prefs as UserPrefs | undefined,
  )

  const persistTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  const updateMutation = useMutation({
    mutationFn: async (value: number) => {
      if (!account) {
        throw new Error('Account data not available')
      }
      return await updateAccountPrefs(
        mergeRightPaneWidthPxIntoPrefs(
          (account.prefs ?? {}) as UserPrefs,
          value,
        ),
        'right-pane-width',
      )
    },
    onMutate: async (value) => {
      const patch = mergeRightPaneWidthPxIntoPrefs(
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
      const patch = mergeRightPaneWidthPxIntoPrefs(
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
      }, RIGHT_PANE_WIDTH_PERSIST_DEBOUNCE_MS)
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

/**
 * @deprecated Use {@link useRightPaneWidth}. Kept for compatibility.
 */
export function useAIChatPanelWidth(
  account: ConsoleAccountCache | undefined,
) {
  return useRightPaneWidth(account)
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
      const currentAccount = getConsoleAccountFromCache(queryClient)
      if (!currentAccount) {
        throw new Error('Account data not available')
      }
      return await updateAccountPrefs(
        mergeCliShellOpenIntoPrefs(
          (currentAccount.prefs ?? {}) as UserPrefs,
          value,
        ),
        'cli-shell-open',
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
        'cli-shell-height',
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
        'cli-shell-sessions-sidebar-width',
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
        'cli-shell-history',
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
  const pendingPersistRef = useRef<PersistedCliShellSessionsState | null>(null)

  const isSessionsPrefsUnchanged = useCallback(
    (value: PersistedCliShellSessionsState) => {
      const key = getCliShellSessionsKey(projectId)
      const nextValue = serializeCliShellSessionsState(value)
      const singletonPrefs = getConsoleAccountSync(
        getConsoleAccountQueryRevision(),
      )?.prefs as UserPrefs | undefined
      const rqPrefs = getConsoleAccountFromCache(queryClient)?.prefs as
        | UserPrefs
        | undefined
      const accountPrefs = account?.prefs as UserPrefs | undefined

      for (const prefs of [singletonPrefs, rqPrefs, accountPrefs]) {
        if (!prefs) continue
        const existing = prefs[key]
        if (existing === nextValue) return true
        // Stored JSON may predate canonical fields (e.g. parentSessionId).
        const parsed = parseCliShellSessions(prefs, projectId)
        if (parsed && serializeCliShellSessionsState(parsed) === nextValue) {
          return true
        }
      }
      return false
    },
    [account?.prefs, projectId, queryClient],
  )

  const updateMutation = useMutation({
    mutationFn: async (value: PersistedCliShellSessionsState) => {
      if (isSessionsPrefsUnchanged(value)) {
        return getConsoleAccountSync(getConsoleAccountQueryRevision())
      }
      const currentAccount =
        getConsoleAccountFromCache(queryClient) ??
        getConsoleAccountSync(getConsoleAccountQueryRevision()) ??
        account
      if (!currentAccount) {
        throw new Error('Account data not available')
      }
      return await updateAccountPrefs(
        mergeCliShellSessionsIntoPrefs(
          (currentAccount.prefs ?? {}) as UserPrefs,
          projectId,
          value,
        ),
        'cli-shell-sessions',
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
      if (isSessionsPrefsUnchanged(value)) {
        return
      }
      const currentPrefs = (getConsoleAccountFromCache(queryClient)?.prefs ??
        getConsoleAccountSync(getConsoleAccountQueryRevision())?.prefs ??
        account.prefs ??
        {}) as UserPrefs
      const patch = mergeCliShellSessionsIntoPrefs(
        currentPrefs,
        projectId,
        value,
      )
      pendingPersistRef.current = value
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
        pendingPersistRef.current = null
        updateMutation.mutate(value)
      }, CLI_SHELL_PREFS_PERSIST_DEBOUNCE_MS)
    },
    [
      account,
      isSessionsPrefsUnchanged,
      projectId,
      queryClient,
      updateMutation,
    ],
  )

  const flushPersistSessions = useCallback(() => {
    if (persistTimerRef.current !== null) {
      clearTimeout(persistTimerRef.current)
      persistTimerRef.current = null
    }
    const pending = pendingPersistRef.current
    if (!pending) return
    pendingPersistRef.current = null
    if (isSessionsPrefsUnchanged(pending)) return
    updateMutation.mutate(pending)
  }, [isSessionsPrefsUnchanged, updateMutation])

  useEffect(() => {
    return () => {
      if (persistTimerRef.current !== null) {
        clearTimeout(persistTimerRef.current)
      }
    }
  }, [])

  return { savedSessions, persistSessions, flushPersistSessions }
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
        'build-notifications-opted-out',
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
        'storage-files-table-pane-width',
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
      },
        'saved-filters')
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
      if (!scope || !teamId) {
        throw new Error('Team or filter scope not available')
      }
      const trimmedName = name.trim().slice(0, MAX_SAVED_FILTER_NAME_LENGTH)
      if (!trimmedName) throw new Error('Name is required')
      await updateTeamPrefs.mutateAsync((freshPrefs) => {
        const current = parseSavedFilters(freshPrefs, scope)
        const newFilter: SavedFilter = {
          id: crypto.randomUUID(),
          name: trimmedName,
          query,
          ...(sort ? { sort } : {}),
        }
        return buildSavedFiltersPrefs(scope, [newFilter, ...current])
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
      },
        'saved-filters')
    },
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
    },
  })

  const deleteTeamMutation = useMutation({
    mutationFn: async (id: string) => {
      if (!scope || !teamId) {
        throw new Error('Team or filter scope not available')
      }
      await updateTeamPrefs.mutateAsync((freshPrefs) => {
        const current = parseSavedFilters(freshPrefs, scope)
        return buildSavedFiltersPrefs(
          scope,
          current.filter((f) => f.id !== id),
        )
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
      },
        'saved-filters')
    },
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
    },
  })

  const reorderTeamMutation = useMutation({
    mutationFn: async (orderedFilters: SavedFilter[]) => {
      if (!scope || !teamId) {
        throw new Error('Team or filter scope not available')
      }
      await updateTeamPrefs.mutateAsync(
        buildSavedFiltersPrefs(scope, orderedFilters),
      )
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
      },
        'saved-filters')
    },
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
    },
  })

  const updateTeamFilterMutation = useMutation({
    mutationFn: async ({ id, name }: { id: string; name: string }) => {
      if (!scope || !teamId) {
        throw new Error('Team or filter scope not available')
      }
      const trimmedName = name.trim().slice(0, MAX_SAVED_FILTER_NAME_LENGTH)
      if (!trimmedName) throw new Error('Name is required')
      await updateTeamPrefs.mutateAsync((freshPrefs) => {
        const current = parseSavedFilters(freshPrefs, scope)
        const next = current.map((f) =>
          f.id === id ? { ...f, name: trimmedName } : f,
        )
        return buildSavedFiltersPrefs(scope, next)
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
        'tablesdb-rows-list-columns',
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
      },
        'image-transform-saved-presets')
    },
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
    },
  })

  const addTeamMutation = useMutation({
    mutationFn: async ({ name, json }: { name: string; json: string }) => {
      if (!teamId) throw new Error('Team not available')
      if (json.length > MAX_SAVED_IMAGE_TRANSFORM_PRESET_JSON_CHARS) {
        throw new Error('Preset data is too large')
      }
      const trimmedName = name
        .trim()
        .slice(0, MAX_SAVED_IMAGE_TRANSFORM_PRESET_NAME_LENGTH)
      if (!trimmedName) throw new Error('Name is required')
      await updateTeamPrefs.mutateAsync((freshPrefs) => {
        const current = parseSavedImageTransformPresets(freshPrefs)
        if (current.length >= MAX_SAVED_IMAGE_TRANSFORM_PRESETS) {
          throw new Error(
            `Maximum ${MAX_SAVED_IMAGE_TRANSFORM_PRESETS} presets`,
          )
        }
        const next: SavedImageTransformPreset[] = [
          { id: crypto.randomUUID(), name: trimmedName, json },
          ...current,
        ]
        return buildSavedImageTransformPresetsPrefs(next)
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
      },
        'image-transform-saved-presets')
    },
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
    },
  })

  const deleteTeamMutation = useMutation({
    mutationFn: async (id: string) => {
      if (!teamId) throw new Error('Team not available')
      await updateTeamPrefs.mutateAsync((freshPrefs) => {
        const current = parseSavedImageTransformPresets(freshPrefs)
        return buildSavedImageTransformPresetsPrefs(
          current.filter((p) => p.id !== id),
        )
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
      },
        'image-transform-saved-presets')
    },
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
    },
  })

  const reorderTeamMutation = useMutation({
    mutationFn: async (ordered: SavedImageTransformPreset[]) => {
      if (!teamId) throw new Error('Team not available')
      await updateTeamPrefs.mutateAsync(
        buildSavedImageTransformPresetsPrefs(ordered),
      )
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
      },
        'image-transform-saved-presets')
    },
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount,
      })
    },
  })

  const updateTeamPresetNameMutation = useMutation({
    mutationFn: async ({ id, name }: { id: string; name: string }) => {
      if (!teamId) throw new Error('Team not available')
      const trimmedName = name
        .trim()
        .slice(0, MAX_SAVED_IMAGE_TRANSFORM_PRESET_NAME_LENGTH)
      if (!trimmedName) throw new Error('Name is required')
      await updateTeamPrefs.mutateAsync((freshPrefs) => {
        const current = parseSavedImageTransformPresets(freshPrefs)
        const next = current.map((p) =>
          p.id === id ? { ...p, name: trimmedName } : p,
        )
        return buildSavedImageTransformPresetsPrefs(next)
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
