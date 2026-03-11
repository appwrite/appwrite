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
 * Update console team (organization) preferences.
 * Merge your keys into existing team.prefs before calling.
 */
export async function updateConsoleTeamPrefs(
  teamId: string,
  prefs: Record<string, unknown>,
) {
  if (!teamId) {
    throw new Error('Team ID is required')
  }
  await sdk.forConsole.teams.updatePrefs({ teamId, prefs })
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
 * Hook to update console team preferences.
 * Invalidates the console team query on success.
 */
export function useUpdateConsoleTeamPrefs(
  teamId: string | null | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (prefs: Record<string, unknown>) =>
      updateConsoleTeamPrefs(teamId!, prefs),
    onSuccess: () => {
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
 * @returns Paginated memberships with loading state
 */
export function useOrganizationMemberships(
  organizationId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
  initialData?: Awaited<
    ReturnType<typeof fetchOrganizationMemberships>
  >,
) {
  const {
    data: membershipsData,
    isLoading,
    isFetching,
    error,
    refetch,
  } = useQuery({
    ...organizationMembershipsQueryOptions(
      organizationId,
      page,
      limit,
      search,
    ),
    initialData,
    initialDataUpdatedAt: initialData ? 1 : 0,
  })

  // Map memberships to our TeamMember type
  const memberships = useMemo(() => {
    if (!membershipsData?.memberships) return []

    return membershipsData.memberships.map((membership: unknown) => {
      // Extract user info from membership attributes
      // The API provides userName and userEmail as attributes
      const name = membership.userName || membership.name || ''
      const email = membership.userEmail || membership.email || ''

      // Map role - Appwrite uses roles like 'owner', 'admin', 'member', 'developer', 'editor', 'analyst', 'billing'
      // Use the first role from the roles array, or fallback to the role property
      let role:
        | 'owner'
        | 'admin'
        | 'member'
        | 'developer'
        | 'editor'
        | 'analyst'
        | 'billing' = 'member'
      if (membership.roles && membership.roles.length > 0) {
        // Use the first role from the array
        const firstRole = membership.roles[0]
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
      } else if (membership.role) {
        const roleValue = membership.role
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

      // Try to get avatar from user object if it exists, otherwise undefined
      const avatar = membership.user?.avatar || membership.avatar || undefined

      // Determine membership status - pending if confirm is false
      const status: 'pending' | 'active' =
        membership.confirm === false ? 'pending' : 'active'

      // Get roles array from membership (for resending invitations)
      const roles =
        membership.roles || (membership.role ? [membership.role] : [])

      // Extract MFA status from user object or membership
      const mfaEnabled =
        membership.user?.mfa === true ||
        membership.user?.twoFactorAuthenticatorEnabled === true ||
        membership.mfa === true ||
        false

      return {
        $id: membership.$id || membership.id,
        userName: name,
        userEmail: email,
        avatar,
        role,
        roles,
        orgId: organizationId || '',
        joinedAt:
          membership.$createdAt ||
          membership.joinedAt ||
          new Date().toISOString(),
        status,
        membershipId: membership.$id || membership.id,
        mfaEnabled,
      } as TeamMember
    })
  }, [membershipsData, organizationId])

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
