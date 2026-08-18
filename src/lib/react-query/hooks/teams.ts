/**
 * React Query hooks for Teams and Memberships
 *
 * Handles teams (derived from organizations), memberships, and team member management.
 */

import {
  useQuery,
  useMutation,
  useQueryClient,
  queryOptions,
} from '@tanstack/react-query'
import { useMemo } from 'react'
import { Query } from '@appwrite.io/console'
import type { Team, TeamMember } from '@/lib/utils/mock-data'
import { sdk } from '@/lib/appwrite/sdk'
import { hasConsoleImpersonationSessionTarget } from '@/lib/console-impersonation'
import { hasProjectSpecificRoles } from '@/lib/console-project-roles'
import { useOrganizations } from './organizations'
import { DEFAULT_STALE_TIME, DEFAULT_PAGE_SIZE } from './constants'

// ============================================================================
// QUERY FUNCTIONS
// ============================================================================

/**
 * Query function to fetch paginated memberships for an organization
 *
 * This is extracted so it can be reused in both hooks and route loaders.
 *
 * @param organizationId - The organization ID
 * @param page - Page number (0-indexed)
 * @param limit - Number of items per page
 * @param search - Optional search query
 * @returns Paginated memberships with total count
 */
export async function fetchOrganizationMemberships(
  organizationId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  if (!organizationId) {
    return { memberships: [], total: 0 }
  }

  // Try to use organizations.listMemberships if available
  try {
    const response = await (
      sdk.forConsole.organizations as unknown
    ).listMemberships(organizationId, {
      queries: [
        Query.orderAsc('$createdAt'),
        Query.limit(limit),
        Query.offset(page * limit),
      ],
      search: search?.trim() || undefined,
      total: true,
    })

    return {
      memberships: response.memberships || response || [],
      total: response.total || 0,
    }
  } catch {
    // Fallback: try teams.listMemberships if organizations method doesn't exist
    try {
      const response = await sdk.forConsole.teams.listMemberships(
        organizationId,
        [
          Query.orderAsc('$createdAt'),
          Query.limit(limit),
          Query.offset(page * limit),
        ],
      )

      return {
        memberships: response.memberships || [],
        total: response.total || 0,
      }
    } catch {
      return { memberships: [], total: 0 }
    }
  }
}

/** Map memberships API payload to TeamMember rows (shared by hooks and org overview queries). */
export function mapOrganizationMembershipsToTeamMembers(
  membershipsData:
    | Awaited<ReturnType<typeof fetchOrganizationMemberships>>
    | undefined,
  organizationId: string | null | undefined,
): TeamMember[] {
  if (!membershipsData?.memberships) return []

  return membershipsData.memberships.map((membership: unknown) => {
    const m = membership as {
      userName?: string
      name?: string
      userEmail?: string
      email?: string
      roles?: string[]
      role?: string
      user?: {
        avatar?: string
        mfa?: boolean
        twoFactorAuthenticatorEnabled?: boolean
      }
      avatar?: string
      confirm?: boolean
      mfa?: boolean
      $id?: string
      id?: string
      $createdAt?: string
      joinedAt?: string
    }

    const name = m.userName || m.name || ''
    const email = m.userEmail || m.email || ''

    let role:
      | 'owner'
      | 'admin'
      | 'member'
      | 'developer'
      | 'editor'
      | 'analyst'
      | 'billing' = 'member'
    if (m.roles && m.roles.length > 0) {
      const firstRole = m.roles[0]
      if (
        [
          'owner',
          'admin',
          'member',
          'developer',
          'editor',
          'analyst',
          'billing',
        ].includes(firstRole)
      ) {
        role = firstRole as typeof role
      } else if (firstRole === 'owner') role = 'owner'
      else if (firstRole === 'admin') role = 'admin'
      else if (hasProjectSpecificRoles(m.roles)) {
        // Project-scoped members have no single org-wide role. The backend
        // reports them as analyst outside a project, so mirror that here
        // rather than falling through to the generic 'member'.
        role = 'analyst'
      }
    } else if (m.role) {
      const roleValue = m.role
      if (
        [
          'owner',
          'admin',
          'member',
          'developer',
          'editor',
          'analyst',
          'billing',
        ].includes(roleValue)
      ) {
        role = roleValue as typeof role
      }
    }

    const avatar = m.user?.avatar || m.avatar || undefined
    const status: 'pending' | 'active' =
      m.confirm === false ? 'pending' : 'active'
    const roles = m.roles || (m.role ? [m.role] : [])
    const mfaEnabled =
      m.user?.mfa === true ||
      m.user?.twoFactorAuthenticatorEnabled === true ||
      m.mfa === true ||
      false

    return {
      $id: m.$id || m.id,
      userName: name,
      userEmail: email,
      avatar,
      role,
      roles,
      orgId: organizationId || '',
      joinedAt: m.$createdAt || m.joinedAt || new Date().toISOString(),
      status,
      membershipId: m.$id || m.id,
      mfaEnabled,
    } as TeamMember
  })
}

/**
 * Fetch a single console team (organization) by ID.
 * Used to read team.prefs (e.g. pinned project IDs).
 */
export async function fetchConsoleTeam(teamId: string) {
  if (!teamId) {
    throw new Error('Team ID is required')
  }
  return await sdk.forConsole.teams.get({ teamId })
}

/**
 * Patch or full replace for console team prefs.
 * Prefer a patch object (only keys you change) or an updater that receives
 * freshly fetched prefs. Appwrite `teams.updatePrefs` replaces the entire
 * prefs blob, so stale full-object writes can drop unrelated keys (e.g. pins).
 */
export type ConsoleTeamPrefsInput =
  | Record<string, unknown>
  | ((freshPrefs: Record<string, unknown>) => Record<string, unknown>)

export type UpdateConsoleTeamPrefsOptions = {
  /**
   * - `merge` (default): fetch latest prefs, shallow-merge the patch, then write
   * - `replace`: write the provided object as-is (debug clear / delete key)
   */
  mode?: 'merge' | 'replace'
}

/** Serialize team-pref writes per org so concurrent patches cannot clobber each other. */
const teamPrefsWriteChains = new Map<string, Promise<unknown>>()

function enqueueConsoleTeamPrefsWrite<T>(
  teamId: string,
  task: () => Promise<T>,
): Promise<T> {
  const previous = teamPrefsWriteChains.get(teamId) ?? Promise.resolve()
  const next = previous.then(task, task)
  teamPrefsWriteChains.set(
    teamId,
    next.then(
      () => undefined,
      () => undefined,
    ),
  )
  return next
}

/**
 * Update console team (organization) preferences.
 *
 * Default `merge` mode always re-fetches prefs before writing so concurrent
 * features (pins, saved filters, presets, SQL) do not wipe each other's keys.
 * Pass `{ mode: 'replace' }` only when intentionally replacing the full object.
 *
 * Silently skips while console impersonation is active so the org's prefs are not mutated.
 */
export async function updateConsoleTeamPrefs(
  teamId: string,
  prefs: ConsoleTeamPrefsInput,
  options?: UpdateConsoleTeamPrefsOptions,
) {
  if (!teamId) {
    throw new Error('Team ID is required')
  }
  if (hasConsoleImpersonationSessionTarget()) {
    return undefined
  }

  const mode = options?.mode ?? 'merge'

  return enqueueConsoleTeamPrefsWrite(teamId, async () => {
    if (mode === 'replace' && typeof prefs !== 'function') {
      return await sdk.forConsole.teams.updatePrefs({ teamId, prefs })
    }

    const team = await fetchConsoleTeam(teamId)
    const freshPrefs = {
      ...((team.prefs as Record<string, unknown> | undefined) || {}),
    }
    const patch = typeof prefs === 'function' ? prefs(freshPrefs) : prefs
    const nextPrefs =
      mode === 'replace' ? patch : { ...freshPrefs, ...patch }

    return await sdk.forConsole.teams.updatePrefs({
      teamId,
      prefs: nextPrefs,
    })
  })
}

// ============================================================================
// QUERY OPTIONS
// ============================================================================

/**
 * Query options for fetching a console team (for prefs, etc.)
 */
export function consoleTeamQueryOptions(teamId: string | null | undefined) {
  return queryOptions({
    queryKey: ['team', 'console', teamId],
    queryFn: () => fetchConsoleTeam(teamId!),
    enabled: !!teamId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    gcTime: teamId ? 5 * 60 * 1000 : 0,
  })
}

/**
 * Query options for fetching organization memberships
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function organizationMembershipsQueryOptions(
  organizationId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  return queryOptions({
    queryKey: [
      'memberships',
      'organization',
      organizationId,
      page,
      limit,
      search,
    ],
    queryFn: () =>
      fetchOrganizationMemberships(organizationId!, page, limit, search),
    enabled: !!organizationId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false, // Don't retry on error
    refetchOnMount: false, // Data is prefetched in route loader, no need to refetch on mount
    refetchOnWindowFocus: false, // Prevent refetch when switching tabs/windows
    refetchOnReconnect: false, // Prevent refetch on network reconnect
    // Don't keep disabled queries in cache
    gcTime: organizationId ? 5 * 60 * 1000 : 0,
  })
}

// ============================================================================
// HOOKS
// ============================================================================

/**
 * Hook to get teams derived from organizations
 *
 * In Appwrite, organizations ARE teams, so this creates Team objects
 * from the organizations list. This does NOT fetch projects.
 *
 * @returns Teams and organizations with loading state
 */
export function useTeams() {
  const { organizations, isLoading, error, refetch } = useOrganizations()

  // Create teams from organizations (since in Appwrite, organizations ARE teams)
  const teams = useMemo(() => {
    return organizations.map((org) => ({
      $id: org.$id,
      name: org.name,
      color: `from-${['orange', 'pink', 'blue', 'violet', 'green', 'purple'][organizations.indexOf(org) % 6]}-400 to-${['pink', 'red', 'violet', 'purple', 'emerald', 'indigo'][organizations.indexOf(org) % 6]}-500`,
      members: org.members,
      orgId: org.$id, // In Appwrite, orgId = teamId
    })) as Team[]
  }, [organizations])

  return {
    teams,
    organizations,
    isLoading,
    error,
    refetch,
  }
}

/**
 * Hook to fetch a console team by ID (e.g. for reading team.prefs).
 */
export function useConsoleTeam(teamId: string | null | undefined) {
  return useQuery(consoleTeamQueryOptions(teamId))
}

/**
 * Hook to update console team preferences (merge mode).
 * Writes are serialized and merged against a fresh server fetch.
 * Invalidates the console team query on success.
 */
export function useUpdateConsoleTeamPrefs(teamId: string | null | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (prefs: ConsoleTeamPrefsInput) =>
      updateConsoleTeamPrefs(teamId!, prefs),
    onSuccess: (prefs) => {
      if (prefs && teamId) {
        queryClient.setQueryData(
          ['team', 'console', teamId],
          (current: { prefs?: Record<string, unknown> } | undefined) =>
            current
              ? {
                  ...current,
                  prefs: prefs as Record<string, unknown>,
                }
              : current,
        )
      }
      queryClient.invalidateQueries({
        queryKey: ['team', 'console', teamId],
      })
      queryClient.invalidateQueries({
        queryKey: ['organizations', 'console'],
      })
    },
  })
}

/**
 * Hook to fetch paginated memberships for an organization
 *
 * This is useful for displaying organization members with pagination.
 *
 * @param organizationId - The organization ID
 * @param page - Page number (0-indexed)
 * @param limit - Number of items per page
 * @param search - Optional search query
 * @param initialData - Optional data from route loader to avoid layout shift on first paint
 * @param options - Optional query options (e.g. placeholderData for no-flash pagination)
 * @returns Paginated memberships with loading state
 */
export function useOrganizationMemberships(
  organizationId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
  initialData?: Awaited<ReturnType<typeof fetchOrganizationMemberships>>,
  options?: { placeholderData?: unknown },
) {
  const {
    data: membershipsData,
    isLoading,
    isFetching,
    error,
    refetch,
  } = useQuery({
    ...organizationMembershipsQueryOptions(organizationId, page, limit, search),
    initialData,
    initialDataUpdatedAt: initialData ? 1 : 0,
    ...(options?.placeholderData !== undefined && {
      placeholderData: options.placeholderData,
    }),
  })

  const memberships = useMemo(
    () =>
      mapOrganizationMembershipsToTeamMembers(membershipsData, organizationId),
    [membershipsData, organizationId],
  )

  const totalPages = useMemo(() => {
    if (!membershipsData?.total) return 0
    return Math.ceil(membershipsData.total / limit)
  }, [membershipsData?.total, limit])

  return {
    memberships,
    total: membershipsData?.total || 0,
    totalPages,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

/**
 * Hook to resend a team membership invitation
 *
 * This deletes the existing pending membership and creates a new one to resend the invitation.
 *
 * @param organizationId - The organization ID
 * @returns Mutation object with mutate function
 */
export function useResendMembershipInvite(
  organizationId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({
      membershipId,
      email,
      roles,
    }: {
      membershipId: string
      email: string
      roles: string[]
    }) => {
      if (!organizationId) {
        throw new Error('Organization ID is required')
      }

      // Delete the existing pending membership
      await sdk.forConsole.teams.deleteMembership(organizationId, membershipId)

      // Create a new membership invitation
      const acceptUrl = `${window.location.origin}/join`
      return await sdk.forConsole.teams.createMembership({
        teamId: organizationId,
        email,
        roles,
        url: acceptUrl,
      })
    },
    onSuccess: () => {
      // Invalidate memberships query to refresh the list
      queryClient.invalidateQueries({
        queryKey: ['memberships', 'organization', organizationId],
      })
    },
  })
}

/**
 * Hook to update a team membership role
 *
 * @param organizationId - The organization ID
 * @returns Mutation object with mutate function
 */
export function useUpdateMembershipRole(
  organizationId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({
      membershipId,
      roles,
    }: {
      membershipId: string
      roles: string[]
    }) => {
      if (!organizationId) {
        throw new Error('Organization ID is required')
      }

      return await sdk.forConsole.teams.updateMembership({
        teamId: organizationId,
        membershipId,
        roles,
      })
    },
    onSuccess: () => {
      // Invalidate memberships query to refresh the list
      queryClient.invalidateQueries({
        queryKey: ['memberships', 'organization', organizationId],
      })
    },
  })
}

/**
 * Hook to remove a member from a team
 *
 * @param organizationId - The organization ID
 * @returns Mutation object with mutate function
 */
export function useRemoveTeamMember(organizationId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (membershipId: string) => {
      if (!organizationId) {
        throw new Error('Organization ID is required')
      }

      return await sdk.forConsole.teams.deleteMembership(
        organizationId,
        membershipId,
      )
    },
    onSuccess: () => {
      // Invalidate memberships query to refresh the list
      queryClient.invalidateQueries({
        queryKey: ['memberships', 'organization', organizationId],
      })
    },
  })
}
