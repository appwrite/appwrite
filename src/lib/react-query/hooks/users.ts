/**
 * React Query hooks for Users and Teams
 *
 * Handles project users and project teams.
 */

import {
  useQuery,
  useMutation,
  useQueryClient,
  queryOptions,
  keepPreviousData,
} from '@tanstack/react-query'
import { useMemo } from 'react'
import { Query, ID } from '@appwrite.io/console'
import type { User } from '@/lib/utils/mock-data'
import { buildAttributePrefixSearchQueries } from '@/lib/appwrite-id'
import { sdk } from '@/lib/appwrite/sdk'
import { DEFAULT_STALE_TIME, DEFAULT_PAGE_SIZE } from './constants'

// ============================================================================
// QUERY FUNCTIONS
// ============================================================================

/** Default sort for users list. */
export const USERS_DEFAULT_SORT_BY = '$createdAt'
export const USERS_DEFAULT_SORT_ORDER = 'desc' as const

/**
 * Query function to fetch paginated users for a project.
 *
 * Extracted for reuse in hooks and route loaders. Search uses list `queries`
 * (`startsWith` on name, email, phone, and `$id`) so ID paste works without a
 * separate `users.get`.
 *
 * @param projectId - The project ID
 * @param page - Page number (0-indexed)
 * @param limit - Number of items per page
 * @param search - Optional search query (name, email, phone, or user ID)
 * @param filterQueries - Optional filter query strings
 * @param sortBy - Sort attribute (e.g. $createdAt, name, email)
 * @param sortOrder - asc or desc
 * @returns Paginated users with total count
 */
export async function fetchProjectUsers(
  projectId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
  filterQueries?: string[],
  sortBy: string = USERS_DEFAULT_SORT_BY,
  sortOrder: 'asc' | 'desc' = USERS_DEFAULT_SORT_ORDER,
) {
  if (!projectId) {
    return { users: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const orderQuery =
    sortOrder === 'asc' ? Query.orderAsc(sortBy) : Query.orderDesc(sortBy)
  const queries = [
    ...(filterQueries ?? []),
    ...buildAttributePrefixSearchQueries(
      ['name', 'email', 'phone', '$id'],
      search,
    ),
    orderQuery,
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  const response = await projectSdk.users.list({ queries })

  return {
    users: response.users || [],
    total: response.total || 0,
  }
}

/**
 * Query function to fetch teams for a project
 *
 * This is extracted so it can be reused in both hooks and route loaders.
 *
 * @param projectId - The project ID
 * @param page - Page number (0-indexed)
 * @param limit - Number of items per page
 * @param search - Optional search query
 * @param filterQueries - Optional list of Appwrite Query condition strings (from table filters)
 * @returns Paginated teams with total count
 */
export async function fetchProjectTeams(
  projectId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
  filterQueries?: string[],
) {
  if (!projectId) {
    return { teams: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const queries = [
    ...(filterQueries ?? []),
    ...buildAttributePrefixSearchQueries(['name', '$id'], search),
    Query.orderDesc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  const response = await projectSdk.teams.list({ queries })

  return {
    teams: response.teams || [],
    total: response.total || 0,
  }
}

// ============================================================================
// MUTATION FUNCTIONS
// ============================================================================

/**
 * Mutation function to create a user in a project
 *
 * @param projectId - The project ID
 * @param userData - User data (userId, email, phone, password, name)
 * @returns Created user
 */
export async function createProjectUser(
  projectId: string,
  userData: {
    userId?: string
    email?: string
    phone?: string
    password?: string
    name?: string
  },
) {
  if (!projectId) {
    throw new Error('Project ID is required')
  }

  const projectSdk = sdk.forProject(projectId)
  const userId = userData.userId || ID.unique()

  return await projectSdk.users.create({
    userId,
    email: userData.email,
    phone: userData.phone,
    password: userData.password,
    name: userData.name,
  })
}

/**
 * Mutation function to create a team in a project
 *
 * @param projectId - The project ID
 * @param teamData - Team data (teamId, name)
 * @returns Created team
 */
export async function createProjectTeam(
  projectId: string,
  teamData: {
    teamId?: string
    name: string
  },
) {
  if (!projectId) {
    throw new Error('Project ID is required')
  }

  if (!teamData.name.trim()) {
    throw new Error('Team name is required')
  }

  const projectSdk = sdk.forProject(projectId)
  const teamId = teamData.teamId || ID.unique()

  return await projectSdk.teams.create({
    teamId,
    name: teamData.name.trim(),
  })
}

/**
 * Mutation function to delete a user from a project
 *
 * @param projectId - The project ID
 * @param userId - The user ID to delete
 */
export async function deleteProjectUser(projectId: string, userId: string) {
  if (!projectId) {
    throw new Error('Project ID is required')
  }

  if (!userId) {
    throw new Error('User ID is required')
  }

  const projectSdk = sdk.forProject(projectId)
  await projectSdk.users.delete({ userId })
}

/**
 * Mutation function to delete a team from a project
 *
 * @param projectId - The project ID
 * @param teamId - The team ID to delete
 */
export async function deleteProjectTeam(projectId: string, teamId: string) {
  if (!projectId) {
    throw new Error('Project ID is required')
  }

  if (!teamId) {
    throw new Error('Team ID is required')
  }

  const projectSdk = sdk.forProject(projectId)
  await projectSdk.teams.delete({ teamId })
}

// ============================================================================
// QUERY OPTIONS
// ============================================================================

/**
 * Query options for fetching paginated users for a project
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function usersQueryOptions(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
  filterQueries?: string[],
  sortBy: string = USERS_DEFAULT_SORT_BY,
  sortOrder: 'asc' | 'desc' = USERS_DEFAULT_SORT_ORDER,
) {
  return queryOptions({
    queryKey: [
      'users',
      'project',
      projectId,
      page,
      limit,
      search,
      filterQueries,
      sortBy,
      sortOrder,
    ],
    queryFn: () =>
      fetchProjectUsers(
        projectId!,
        page,
        limit,
        search,
        filterQueries,
        sortBy,
        sortOrder,
      ),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false, // Don't retry on error
    refetchOnMount: false, // Data is prefetched in route loader, no need to refetch on mount
    refetchOnWindowFocus: false, // Prevent refetch when switching tabs/windows
    refetchOnReconnect: false, // Prevent refetch on network reconnect
    // Don't keep disabled queries in cache
    gcTime: projectId ? 5 * 60 * 1000 : 0,
    // Keep previous results visible until new data loads (avoids flash when search/filters/page change)
    placeholderData: keepPreviousData,
  })
}

/**
 * Query options for fetching paginated teams for a project
 *
 * This can be used in both route loaders and hooks to ensure consistent query configuration.
 */
export function teamsQueryOptions(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
  filterQueries?: string[],
) {
  return queryOptions({
    queryKey: [
      'teams',
      'project',
      projectId,
      page,
      limit,
      search,
      filterQueries,
    ],
    queryFn: () =>
      fetchProjectTeams(projectId!, page, limit, search, filterQueries),
    enabled: !!projectId,
    staleTime: DEFAULT_STALE_TIME,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

// ============================================================================
// HOOKS
// ============================================================================

/**
 * Hook to fetch paginated users for a project
 *
 * This is useful for displaying project users with pagination and search.
 *
 * @param projectId - The project ID
 * @param page - Page number (0-indexed)
 * @param limit - Number of items per page
 * @param search - Optional search query
 * @param filterQueries - Optional list of Appwrite Query condition strings (from table filters)
 * @param sortBy - Sort attribute (default $createdAt)
 * @param sortOrder - asc or desc (default desc)
 * @returns Paginated users with loading state
 */
export function useProjectUsers(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
  filterQueries?: string[],
  sortBy: string = USERS_DEFAULT_SORT_BY,
  sortOrder: 'asc' | 'desc' = USERS_DEFAULT_SORT_ORDER,
) {
  const {
    data: usersData,
    isLoading,
    isFetching,
    isFetched,
    error,
    refetch,
  } = useQuery(
    usersQueryOptions(
      projectId,
      page,
      limit,
      search,
      filterQueries,
      sortBy,
      sortOrder,
    ),
  )

  // Map users to our User type
  const users = useMemo(() => {
    if (!usersData?.users) return []

    return usersData.users.map((user: unknown) => {
      return {
        $id: user.$id,
        name: user.name || '',
        email: user.email || '',
        phone: user.phone || '',
        avatar: user.avatar || undefined,
        emailVerification: !!user.emailVerification,
        phoneVerification: !!user.phoneVerification,
        status: user.status,
        createdAt: user.$createdAt || new Date().toISOString(),
        mfaEnabled:
          user.mfa === true ||
          user.twoFactorAuthenticatorEnabled === true ||
          false,
        accessedAt: user.accessedAt || undefined,
      } as User & { phone?: string; mfaEnabled?: boolean; accessedAt?: string }
    })
  }, [usersData])

  const totalPages = useMemo(() => {
    if (!usersData?.total) return 0
    return Math.ceil(usersData.total / limit)
  }, [usersData?.total, limit])

  return {
    users,
    total: usersData?.total || 0,
    totalPages,
    isLoading,
    isFetching,
    isFetched,
    error,
    refetch,
  }
}

/**
 * Hook to create a user in a project
 *
 * @param projectId - The project ID
 * @returns Mutation object with mutate function
 */
export function useCreateProjectUser(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (userData: {
      userId?: string
      email?: string
      phone?: string
      password?: string
      name?: string
    }) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      return createProjectUser(projectId, userData)
    },
    onSuccess: () => {
      // Invalidate users queries to refetch the list
      queryClient.invalidateQueries({
        queryKey: ['users', 'project', projectId],
      })
    },
  })
}

/**
 * Hook to fetch teams for a project
 *
 * This is useful for displaying project teams with pagination and search.
 *
 * @param projectId - The project ID
 * @param page - Page number (0-indexed)
 * @param limit - Number of items per page
 * @param search - Optional search query
 * @param filterQueries - Optional list of Appwrite Query condition strings (from table filters)
 * @returns Paginated teams with loading state
 */
export function useProjectTeams(
  projectId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
  filterQueries?: string[],
) {
  const {
    data: teamsData,
    isLoading,
    isFetching,
    error,
    refetch,
  } = useQuery(teamsQueryOptions(projectId, page, limit, search, filterQueries))

  // Map teams to our extended team type with additional metadata
  const teams = useMemo(() => {
    if (!teamsData?.teams) return []

    return teamsData.teams.map((team: unknown) => {
      // Get member counts from memberships if available
      // For now, use total as members count
      const members = team.total || 0

      // Placeholder values for owners and admins
      // These would need to be calculated from memberships with roles
      const owners = 0 // TODO: Calculate from memberships with owner role
      const admins = 0 // TODO: Calculate from memberships with admin role
      const regularMembers = members - owners - admins

      return {
        id: team.$id,
        name: team.name,
        members,
        createdAt: team.$createdAt || new Date().toISOString(),
        owners,
        admins,
        regularMembers,
        lastActivity: team.$createdAt || new Date().toISOString(), // Use createdAt as placeholder
      }
    })
  }, [teamsData])

  const totalPages = useMemo(() => {
    if (!teamsData?.total) return 0
    return Math.ceil(teamsData.total / limit)
  }, [teamsData?.total, limit])

  return {
    teams,
    total: teamsData?.total || 0,
    totalPages,
    isLoading,
    isFetching,
    error,
    refetch,
  }
}

/**
 * Hook to create a team in a project
 *
 * @param projectId - The project ID
 * @returns Mutation object with mutate function
 */
export function useCreateProjectTeam(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (teamData: { teamId?: string; name: string }) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      return createProjectTeam(projectId, teamData)
    },
    onSuccess: () => {
      // Invalidate teams queries to refetch the list
      queryClient.invalidateQueries({
        queryKey: ['teams', 'project', projectId],
      })
    },
  })
}

/**
 * Hook to delete a user from a project
 *
 * @param projectId - The project ID
 * @returns Mutation object with mutate function
 */
export function useDeleteProjectUser(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (userId: string) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      return deleteProjectUser(projectId, userId)
    },
    onSuccess: async () => {
      // Refetch users list so the UI updates (list uses refetchOnMount: false)
      await queryClient.refetchQueries({
        queryKey: ['users', 'project', projectId],
      })
    },
  })
}

/**
 * Hook to delete a team from a project
 *
 * @param projectId - The project ID
 * @returns Mutation object with mutate function
 */
export function useDeleteProjectTeam(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (teamId: string) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      return deleteProjectTeam(projectId, teamId)
    },
    onSuccess: async () => {
      // Refetch teams list so the UI updates (list uses refetchOnMount: false)
      await queryClient.refetchQueries({
        queryKey: ['teams', 'project', projectId],
      })
    },
  })
}

// ============================================================================
// USER DETAIL QUERY FUNCTIONS
// ============================================================================

/**
 * Query function to fetch a single user by ID
 */
export async function fetchUser(projectId: string, userId: string) {
  if (!projectId || !userId) {
    throw new Error('Project ID and User ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.users.get({ userId })
}

/**
 * Query function to fetch user memberships
 */
export async function fetchUserMemberships(projectId: string, userId: string) {
  if (!projectId || !userId) {
    return { memberships: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const response = await projectSdk.users.listMemberships({ userId })

  return {
    memberships: response.memberships || [],
    total: response.total || 0,
  }
}

/**
 * Query function to fetch user identities
 */
export async function fetchUserIdentities(
  projectId: string,
  userId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  if (!projectId || !userId) {
    return { identities: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const queries = [
    Query.equal('userId', userId),
    Query.orderDesc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  const response = await projectSdk.users.listIdentities({
    queries,
    search: search?.trim() || undefined,
  })

  return {
    identities: response.identities || [],
    total: response.total || 0,
  }
}

/**
 * Query function to fetch user targets
 */
export async function fetchUserTargets(
  projectId: string,
  userId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
) {
  if (!projectId || !userId) {
    return { targets: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const queries = [
    Query.orderDesc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  const response = await projectSdk.users.listTargets({
    userId,
    queries,
  })

  return {
    targets: response.targets || [],
    total: response.total || 0,
  }
}

/**
 * Query function to fetch user sessions
 */
export async function fetchUserSessions(projectId: string, userId: string) {
  if (!projectId || !userId) {
    return { sessions: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const response = await projectSdk.users.listSessions({ userId })

  return {
    sessions: response.sessions || [],
    total: response.total || 0,
  }
}

/**
 * Query function to fetch user MFA factors
 */
export async function fetchUserMFAFactors(projectId: string, userId: string) {
  if (!projectId || !userId) {
    return { totp: false, authenticators: [] }
  }

  const projectSdk = sdk.forProject(projectId)
  const response = await projectSdk.users.listMFAFactors({ userId })

  return {
    totp: response.totp || false,
    authenticators: response.authenticators || [],
  }
}

// ============================================================================
// USER DETAIL MUTATION FUNCTIONS
// ============================================================================

/**
 * Mutation function to update user name
 */
export async function updateUserName(
  projectId: string,
  userId: string,
  name: string,
) {
  if (!projectId || !userId) {
    throw new Error('Project ID and User ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.users.updateName({ userId, name })
}

/**
 * Mutation function to update user email
 */
export async function updateUserEmail(
  projectId: string,
  userId: string,
  email: string,
) {
  if (!projectId || !userId) {
    throw new Error('Project ID and User ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.users.updateEmail({ userId, email })
}

/**
 * Mutation function to update user phone
 */
export async function updateUserPhone(
  projectId: string,
  userId: string,
  phone: string,
) {
  if (!projectId || !userId) {
    throw new Error('Project ID and User ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.users.updatePhone({ userId, number: phone })
}

/**
 * Mutation function to update user password
 */
export async function updateUserPassword(
  projectId: string,
  userId: string,
  password: string,
) {
  if (!projectId || !userId) {
    throw new Error('Project ID and User ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.users.updatePassword({ userId, password })
}

/**
 * Mutation function to update user labels
 */
export async function updateUserLabels(
  projectId: string,
  userId: string,
  labels: string[],
) {
  if (!projectId || !userId) {
    throw new Error('Project ID and User ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.users.updateLabels({ userId, labels })
}

/**
 * Mutation function to update user preferences
 */
export async function updateUserPrefs(
  projectId: string,
  userId: string,
  prefs: Record<string, string>,
) {
  if (!projectId || !userId) {
    throw new Error('Project ID and User ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.users.updatePrefs({ userId, prefs })
}

/**
 * Mutation function to update user status (block/unblock)
 */
export async function updateUserStatus(
  projectId: string,
  userId: string,
  status: boolean,
) {
  if (!projectId || !userId) {
    throw new Error('Project ID and User ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.users.updateStatus({ userId, status })
}

/**
 * Enable or disable whether the user may impersonate other project users.
 */
export async function updateUserImpersonator(
  projectId: string,
  userId: string,
  impersonator: boolean,
) {
  if (!projectId || !userId) {
    throw new Error('Project ID and User ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.users.updateImpersonator({ userId, impersonator })
}

/**
 * Mutation function to update user email verification
 */
export async function updateUserEmailVerification(
  projectId: string,
  userId: string,
  emailVerification: boolean,
) {
  if (!projectId || !userId) {
    throw new Error('Project ID and User ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.users.updateEmailVerification({
    userId,
    emailVerification,
  })
}

/**
 * Mutation function to update user phone verification
 */
export async function updateUserPhoneVerification(
  projectId: string,
  userId: string,
  phoneVerification: boolean,
) {
  if (!projectId || !userId) {
    throw new Error('Project ID and User ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.users.updatePhoneVerification({
    userId,
    phoneVerification,
  })
}

/**
 * Mutation function to update user MFA
 */
export async function updateUserMFA(
  projectId: string,
  userId: string,
  mfa: boolean,
) {
  if (!projectId || !userId) {
    throw new Error('Project ID and User ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.users.updateMFA({ userId, mfa })
}

/**
 * Mutation function to delete user MFA authenticator
 */
export async function deleteUserMFAAuthenticator(
  projectId: string,
  userId: string,
  type: string,
) {
  if (!projectId || !userId) {
    throw new Error('Project ID and User ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.users.deleteMFAAuthenticator({ userId, type })
}

/**
 * Mutation function to delete user membership
 */
export async function deleteUserMembership(
  projectId: string,
  teamId: string,
  membershipId: string,
) {
  if (!projectId || !teamId || !membershipId) {
    throw new Error('Project ID, Team ID, and Membership ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.teams.deleteMembership({ teamId, membershipId })
}

/**
 * Mutation function to delete user identity
 */
export async function deleteUserIdentity(
  projectId: string,
  identityId: string,
) {
  if (!projectId || !identityId) {
    throw new Error('Project ID and Identity ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.users.deleteIdentity({ identityId })
}

/**
 * Mutation function to create user target
 */
export async function createUserTarget(
  projectId: string,
  userId: string,
  targetData: {
    targetId?: string
    providerType: string
    identifier: string
    providerId?: string
    name?: string
  },
) {
  if (!projectId || !userId) {
    throw new Error('Project ID and User ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  const targetId = targetData.targetId || ID.unique()

  return await projectSdk.users.createTarget({
    userId,
    targetId,
    providerType: targetData.providerType,
    identifier: targetData.identifier,
    providerId: targetData.providerId,
    name: targetData.name,
  })
}

/**
 * Mutation function to delete user target
 */
export async function deleteUserTarget(
  projectId: string,
  userId: string,
  targetId: string,
) {
  if (!projectId || !userId || !targetId) {
    throw new Error('Project ID, User ID, and Target ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.users.deleteTarget({ userId, targetId })
}

/**
 * Mutation function to delete user session
 */
export async function deleteUserSession(
  projectId: string,
  userId: string,
  sessionId: string,
) {
  if (!projectId || !userId || !sessionId) {
    throw new Error('Project ID, User ID, and Session ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.users.deleteSession({ userId, sessionId })
}

/**
 * Mutation function to delete all user sessions
 */
export async function deleteAllUserSessions(projectId: string, userId: string) {
  if (!projectId || !userId) {
    throw new Error('Project ID and User ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.users.deleteSessions({ userId })
}

// ============================================================================
// USER DETAIL HOOKS
// ============================================================================

/**
 * Hook to fetch a single user by ID
 */
export function useUser(
  projectId: string | null | undefined,
  userId: string | null | undefined,
) {
  return useQuery({
    queryKey: ['user', 'project', projectId, userId],
    queryFn: () => fetchUser(projectId!, userId!),
    enabled: !!projectId && !!userId,
    staleTime: DEFAULT_STALE_TIME,
  })
}

/**
 * Hook to fetch user memberships
 */
export function useUserMemberships(
  projectId: string | null | undefined,
  userId: string | null | undefined,
) {
  return useQuery({
    queryKey: ['user', 'memberships', 'project', projectId, userId],
    queryFn: () => fetchUserMemberships(projectId!, userId!),
    enabled: !!projectId && !!userId,
    staleTime: DEFAULT_STALE_TIME,
  })
}

/**
 * Hook to fetch user identities
 */
export function useUserIdentities(
  projectId: string | null | undefined,
  userId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  return useQuery({
    queryKey: [
      'user',
      'identities',
      'project',
      projectId,
      userId,
      page,
      limit,
      search,
    ],
    queryFn: () =>
      fetchUserIdentities(projectId!, userId!, page, limit, search),
    enabled: !!projectId && !!userId,
    staleTime: DEFAULT_STALE_TIME,
  })
}

/**
 * Hook to fetch user targets
 */
export function useUserTargets(
  projectId: string | null | undefined,
  userId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
) {
  return useQuery({
    queryKey: ['user', 'targets', 'project', projectId, userId, page, limit],
    queryFn: () => fetchUserTargets(projectId!, userId!, page, limit),
    enabled: !!projectId && !!userId,
    staleTime: DEFAULT_STALE_TIME,
  })
}

/**
 * Hook to fetch user sessions
 */
export function useUserSessions(
  projectId: string | null | undefined,
  userId: string | null | undefined,
) {
  return useQuery({
    queryKey: ['user', 'sessions', 'project', projectId, userId],
    queryFn: () => fetchUserSessions(projectId!, userId!),
    enabled: !!projectId && !!userId,
    staleTime: DEFAULT_STALE_TIME,
  })
}

/**
 * Hook to fetch user MFA factors
 */
export function useUserMFAFactors(
  projectId: string | null | undefined,
  userId: string | null | undefined,
) {
  return useQuery({
    queryKey: ['user', 'mfa-factors', 'project', projectId, userId],
    queryFn: () => fetchUserMFAFactors(projectId!, userId!),
    enabled: !!projectId && !!userId,
    staleTime: DEFAULT_STALE_TIME,
  })
}

/**
 * Hook to update user name
 */
export function useUpdateUserName(
  projectId: string | null | undefined,
  userId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (name: string) => {
      if (!projectId || !userId) {
        throw new Error('Project ID and User ID are required')
      }
      return updateUserName(projectId, userId, name)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['user', 'project', projectId, userId],
      })
      queryClient.invalidateQueries({
        queryKey: ['users', 'project', projectId],
      })
    },
  })
}

/**
 * Hook to update user email
 */
export function useUpdateUserEmail(
  projectId: string | null | undefined,
  userId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (email: string) => {
      if (!projectId || !userId) {
        throw new Error('Project ID and User ID are required')
      }
      return updateUserEmail(projectId, userId, email)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['user', 'project', projectId, userId],
      })
      queryClient.invalidateQueries({
        queryKey: ['users', 'project', projectId],
      })
    },
  })
}

/**
 * Hook to update user phone
 */
export function useUpdateUserPhone(
  projectId: string | null | undefined,
  userId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (phone: string) => {
      if (!projectId || !userId) {
        throw new Error('Project ID and User ID are required')
      }
      return updateUserPhone(projectId, userId, phone)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['user', 'project', projectId, userId],
      })
      queryClient.invalidateQueries({
        queryKey: ['users', 'project', projectId],
      })
    },
  })
}

/**
 * Hook to update user password
 */
export function useUpdateUserPassword(
  projectId: string | null | undefined,
  userId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (password: string) => {
      if (!projectId || !userId) {
        throw new Error('Project ID and User ID are required')
      }
      return updateUserPassword(projectId, userId, password)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['user', 'project', projectId, userId],
      })
    },
  })
}

/**
 * Hook to update user labels
 */
export function useUpdateUserLabels(
  projectId: string | null | undefined,
  userId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (labels: string[]) => {
      if (!projectId || !userId) {
        throw new Error('Project ID and User ID are required')
      }
      return updateUserLabels(projectId, userId, labels)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['user', 'project', projectId, userId],
      })
      queryClient.invalidateQueries({
        queryKey: ['users', 'project', projectId],
      })
    },
  })
}

/**
 * Hook to update user preferences
 */
export function useUpdateUserPrefs(
  projectId: string | null | undefined,
  userId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (prefs: Record<string, string>) => {
      if (!projectId || !userId) {
        throw new Error('Project ID and User ID are required')
      }
      return updateUserPrefs(projectId, userId, prefs)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['user', 'project', projectId, userId],
      })
      queryClient.invalidateQueries({
        queryKey: ['users', 'project', projectId],
      })
    },
  })
}

/**
 * Hook to update user status
 */
export function useUpdateUserStatus(
  projectId: string | null | undefined,
  userId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (status: boolean) => {
      if (!projectId || !userId) {
        throw new Error('Project ID and User ID are required')
      }
      return updateUserStatus(projectId, userId, status)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['user', 'project', projectId, userId],
      })
      queryClient.invalidateQueries({
        queryKey: ['users', 'project', projectId],
      })
    },
  })
}

/**
 * Hook to update whether the user may impersonate other project users.
 */
export function useUpdateUserImpersonator(
  projectId: string | null | undefined,
  userId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (impersonator: boolean) => {
      if (!projectId || !userId) {
        throw new Error('Project ID and User ID are required')
      }
      return updateUserImpersonator(projectId, userId, impersonator)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['user', 'project', projectId, userId],
      })
      queryClient.invalidateQueries({
        queryKey: ['users', 'project', projectId],
      })
    },
  })
}

/**
 * Hook to update user email verification
 */
export function useUpdateUserEmailVerification(
  projectId: string | null | undefined,
  userId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (emailVerification: boolean) => {
      if (!projectId || !userId) {
        throw new Error('Project ID and User ID are required')
      }
      return updateUserEmailVerification(projectId, userId, emailVerification)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['user', 'project', projectId, userId],
      })
      queryClient.invalidateQueries({
        queryKey: ['users', 'project', projectId],
      })
    },
  })
}

/**
 * Hook to update user phone verification
 */
export function useUpdateUserPhoneVerification(
  projectId: string | null | undefined,
  userId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (phoneVerification: boolean) => {
      if (!projectId || !userId) {
        throw new Error('Project ID and User ID are required')
      }
      return updateUserPhoneVerification(projectId, userId, phoneVerification)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['user', 'project', projectId, userId],
      })
      queryClient.invalidateQueries({
        queryKey: ['users', 'project', projectId],
      })
    },
  })
}

/**
 * Hook to update user MFA
 */
export function useUpdateUserMFA(
  projectId: string | null | undefined,
  userId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (mfa: boolean) => {
      if (!projectId || !userId) {
        throw new Error('Project ID and User ID are required')
      }
      return updateUserMFA(projectId, userId, mfa)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['user', 'project', projectId, userId],
      })
      queryClient.invalidateQueries({
        queryKey: ['user', 'mfa-factors', 'project', projectId, userId],
      })
      queryClient.invalidateQueries({
        queryKey: ['users', 'project', projectId],
      })
    },
  })
}

/**
 * Hook to delete user MFA authenticator
 */
export function useDeleteUserMFAAuthenticator(
  projectId: string | null | undefined,
  userId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (type: string) => {
      if (!projectId || !userId) {
        throw new Error('Project ID and User ID are required')
      }
      return deleteUserMFAAuthenticator(projectId, userId, type)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['user', 'mfa-factors', 'project', projectId, userId],
      })
      queryClient.invalidateQueries({
        queryKey: ['user', 'project', projectId, userId],
      })
    },
  })
}

/**
 * Hook to delete user membership
 */
export function useDeleteUserMembership(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({
      teamId,
      membershipId,
    }: {
      teamId: string
      membershipId: string
    }) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      return deleteUserMembership(projectId, teamId, membershipId)
    },
    onSuccess: () => {
      // Invalidate memberships for all users (since we don't know which user)
      queryClient.invalidateQueries({
        queryKey: ['user', 'memberships'],
      })
    },
  })
}

/**
 * Hook to delete user identity
 */
export function useDeleteUserIdentity(
  projectId: string | null | undefined,
  userId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (identityId: string) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      return deleteUserIdentity(projectId, identityId)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['user', 'identities', 'project', projectId, userId],
      })
      queryClient.invalidateQueries({
        queryKey: ['user', 'project', projectId, userId],
      })
    },
  })
}

/**
 * Hook to create user target
 */
export function useCreateUserTarget(
  projectId: string | null | undefined,
  userId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (targetData: {
      targetId?: string
      providerType: string
      identifier: string
      providerId?: string
      name?: string
    }) => {
      if (!projectId || !userId) {
        throw new Error('Project ID and User ID are required')
      }
      return createUserTarget(projectId, userId, targetData)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['user', 'targets', 'project', projectId, userId],
      })
    },
  })
}

/**
 * Hook to delete user target
 */
export function useDeleteUserTarget(
  projectId: string | null | undefined,
  userId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (targetId: string) => {
      if (!projectId || !userId) {
        throw new Error('Project ID and User ID are required')
      }
      return deleteUserTarget(projectId, userId, targetId)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['user', 'targets', 'project', projectId, userId],
      })
    },
  })
}

/**
 * Hook to delete user session
 */
export function useDeleteUserSession(
  projectId: string | null | undefined,
  userId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (sessionId: string) => {
      if (!projectId || !userId) {
        throw new Error('Project ID and User ID are required')
      }
      return deleteUserSession(projectId, userId, sessionId)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['user', 'sessions', 'project', projectId, userId],
      })
    },
  })
}

/**
 * Hook to delete all user sessions
 */
export function useDeleteAllUserSessions(
  projectId: string | null | undefined,
  userId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: () => {
      if (!projectId || !userId) {
        throw new Error('Project ID and User ID are required')
      }
      return deleteAllUserSessions(projectId, userId)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['user', 'sessions', 'project', projectId, userId],
      })
    },
  })
}

// ============================================================================
// TEAM DETAIL QUERY FUNCTIONS
// ============================================================================

/**
 * Query function to fetch a single team by ID
 */
export async function fetchTeam(projectId: string, teamId: string) {
  if (!projectId || !teamId) {
    throw new Error('Project ID and Team ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.teams.get({ teamId })
}

/**
 * Query function to fetch team memberships
 */
export async function fetchTeamMemberships(
  projectId: string,
  teamId: string,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  if (!projectId || !teamId) {
    return { memberships: [], total: 0 }
  }

  const projectSdk = sdk.forProject(projectId)
  const queries = [
    Query.orderDesc('$createdAt'),
    Query.limit(limit),
    Query.offset(page * limit),
  ]

  const response = await projectSdk.teams.listMemberships({
    teamId,
    queries,
    search: search?.trim() || undefined,
  })

  return {
    memberships: response.memberships || [],
    total: response.total || 0,
  }
}

// ============================================================================
// TEAM DETAIL MUTATION FUNCTIONS
// ============================================================================

/**
 * Mutation function to update team name
 */
export async function updateTeamName(
  projectId: string,
  teamId: string,
  name: string,
) {
  if (!projectId || !teamId) {
    throw new Error('Project ID and Team ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.teams.updateName({ teamId, name })
}

/**
 * Mutation function to update team preferences
 */
export async function updateTeamPrefs(
  projectId: string,
  teamId: string,
  prefs: Record<string, string>,
) {
  if (!projectId || !teamId) {
    throw new Error('Project ID and Team ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.teams.updatePrefs({ teamId, prefs })
}

/**
 * Mutation function to create team membership
 */
export async function createTeamMembership(
  projectId: string,
  teamId: string,
  membershipData: {
    roles: string[]
    email?: string
    userId?: string
    phone?: string
    url?: string
    name?: string
  },
) {
  if (!projectId || !teamId) {
    throw new Error('Project ID and Team ID are required')
  }
  if (
    !membershipData.userId &&
    !membershipData.email &&
    !membershipData.phone
  ) {
    throw new Error('User ID, email, or phone is required')
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.teams.createMembership({
    teamId,
    roles: membershipData.roles,
    email: membershipData.email,
    userId: membershipData.userId,
    phone: membershipData.phone,
    url: membershipData.url,
    name: membershipData.name,
  })
}

/**
 * Mutation function to delete team membership
 */
export async function deleteTeamMembership(
  projectId: string,
  teamId: string,
  membershipId: string,
) {
  if (!projectId || !teamId || !membershipId) {
    throw new Error('Project ID, Team ID, and Membership ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.teams.deleteMembership({ teamId, membershipId })
}

/**
 * Mutation function to update team membership roles
 */
export async function updateTeamMembership(
  projectId: string,
  teamId: string,
  membershipId: string,
  roles: string[],
) {
  if (!projectId || !teamId || !membershipId) {
    throw new Error('Project ID, Team ID, and Membership ID are required')
  }

  const projectSdk = sdk.forProject(projectId)
  return await projectSdk.teams.updateMembership({
    teamId,
    membershipId,
    roles,
  })
}

// ============================================================================
// TEAM DETAIL HOOKS
// ============================================================================

/**
 * Hook to fetch a single team by ID
 */
export function useTeam(
  projectId: string | null | undefined,
  teamId: string | null | undefined,
) {
  return useQuery({
    queryKey: ['team', 'project', projectId, teamId],
    queryFn: () => fetchTeam(projectId!, teamId!),
    enabled: !!projectId && !!teamId,
    staleTime: DEFAULT_STALE_TIME,
  })
}

/**
 * Hook to fetch team memberships
 */
export function useTeamMemberships(
  projectId: string | null | undefined,
  teamId: string | null | undefined,
  page: number = 0,
  limit: number = DEFAULT_PAGE_SIZE,
  search?: string,
) {
  return useQuery({
    queryKey: [
      'team',
      'memberships',
      'project',
      projectId,
      teamId,
      page,
      limit,
      search,
    ],
    queryFn: () =>
      fetchTeamMemberships(projectId!, teamId!, page, limit, search),
    enabled: !!projectId && !!teamId,
    staleTime: DEFAULT_STALE_TIME,
  })
}

/**
 * Hook to update team name
 */
export function useUpdateTeamName(
  projectId: string | null | undefined,
  teamId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (name: string) => {
      if (!projectId || !teamId) {
        throw new Error('Project ID and Team ID are required')
      }
      return updateTeamName(projectId, teamId, name)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['team', 'project', projectId, teamId],
      })
      queryClient.invalidateQueries({
        queryKey: ['teams', 'project', projectId],
      })
    },
  })
}

/**
 * Hook to update team preferences
 */
export function useUpdateTeamPrefs(
  projectId: string | null | undefined,
  teamId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (prefs: Record<string, string>) => {
      if (!projectId || !teamId) {
        throw new Error('Project ID and Team ID are required')
      }
      return updateTeamPrefs(projectId, teamId, prefs)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['team', 'project', projectId, teamId],
      })
      queryClient.invalidateQueries({
        queryKey: ['teams', 'project', projectId],
      })
    },
  })
}

/**
 * Hook to create team membership
 */
export function useCreateTeamMembership(
  projectId: string | null | undefined,
  teamId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (membershipData: {
      roles: string[]
      email?: string
      userId?: string
      phone?: string
      url?: string
      name?: string
      teamId?: string
    }) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      const resolvedTeamId = teamId || membershipData.teamId
      if (!resolvedTeamId) {
        throw new Error('Team ID is required')
      }
      return createTeamMembership(projectId, resolvedTeamId, membershipData)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['team', 'memberships', 'project', projectId],
      })
      queryClient.invalidateQueries({
        queryKey: ['team', 'project', projectId],
      })
      queryClient.invalidateQueries({ queryKey: ['user', 'memberships'] })
    },
  })
}

/**
 * Hook to delete team membership
 */
export function useDeleteTeamMembership(
  projectId: string | null | undefined,
  teamId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (membershipId: string) => {
      if (!projectId || !teamId) {
        throw new Error('Project ID and Team ID are required')
      }
      return deleteTeamMembership(projectId, teamId, membershipId)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['team', 'memberships', 'project', projectId, teamId],
      })
      queryClient.invalidateQueries({
        queryKey: ['team', 'project', projectId, teamId],
      })
      // Invalidate user memberships so user detail tab refreshes when a membership is deleted
      queryClient.invalidateQueries({ queryKey: ['user', 'memberships'] })
    },
  })
}

/**
 * Hook to update team membership roles
 */
export function useUpdateTeamMembership(
  projectId: string | null | undefined,
  teamId: string | null | undefined,
) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: ({
      membershipId,
      roles,
    }: {
      membershipId: string
      roles: string[]
    }) => {
      if (!projectId || !teamId) {
        throw new Error('Project ID and Team ID are required')
      }
      return updateTeamMembership(projectId, teamId, membershipId, roles)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['team', 'memberships', 'project', projectId, teamId],
      })
      queryClient.invalidateQueries({
        queryKey: ['team', 'project', projectId, teamId],
      })
      queryClient.invalidateQueries({ queryKey: ['user', 'memberships'] })
    },
  })
}
