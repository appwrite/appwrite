import { useState, useMemo, useEffect, useRef } from 'react'
import {
  useParams,
  useLocation,
  Link,
  useNavigate,
  useSearch,
} from '@tanstack/react-router'
import { GRID_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import {
  getSearch,
  getPage,
  getLimit,
  getQueryParam,
  getSort,
  parseSort,
  encodeSort,
  queryParamToMap,
  mapToQueryParam,
  buildListSearchParams,
  urlFromRouterLocation,
  MIN_SEARCH_LENGTH,
  usersFilterColumns,
  teamsFilterColumns,
} from '@/lib/table-filters'
import type { CompactFilterKey } from '@/lib/table-filters'
import { cn } from '@/lib/utils'
import {
  Users,
  AlertCircle,
  CheckCircle2,
  XCircle,
  Mail,
  Phone,
} from 'lucide-react'
import {
  useProjectUsers,
  useProjectTeams,
  useCreateProjectUser,
  useCreateProjectTeam,
  useProject,
  useProjectSmtpEnabled,
  useOrganizationScopes,
} from '@/lib/react-query/hooks'
import {
  USERS_DEFAULT_SORT_BY,
  USERS_DEFAULT_SORT_ORDER,
} from '@/lib/react-query/hooks/users'
import {
  canShowAuthSecuritySettings,
  canCreateUser,
  canCreateTeam,
} from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import {
  deleteProjectUser,
  deleteProjectTeam,
} from '@/lib/react-query/hooks/users'
import { Alert, AlertTitle, AlertDescription } from '@/components/ui/alert'
import { ServiceHeader, type Tab } from '../shared/ServiceHeader'
import { ServiceListViewToggle } from '../shared/ServiceListViewToggle'
import { ResourceCard, RESOURCE_CARD_GRID_CLASSNAME } from '../shared/ResourceCard'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import { Button } from '@/components/ui/button'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { Badge } from '@/components/ui/badge'
import { Checkbox } from '@/components/ui/checkbox'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { FiltersPopover } from '@/components/global/shared/FiltersPopover'
import { CreateUserDrawer } from './CreateUserDrawer'
import { CreateTeamDrawer } from './CreateTeamDrawer'
import { AuthSettings } from './Settings'
import { SocialProviders } from './SocialProviders'
import { Templates } from './Templates'
import { OAuth2ServerLayout } from './oauth2-server/Layout'
import {
  PoliciesLayout,
  type PoliciesSubTab,
} from './policies/Layout'
import { UsersPolicies } from './policies/Users'
import { SessionsPolicies } from './policies/Sessions'
import { MembershipsPolicies } from './policies/Memberships'
import { EmailsPolicies } from './policies/Emails'
import { PasswordsPolicies } from './policies/Passwords'
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'
import { formatRelativeDuration } from '@/lib/i18n/relative-time'
import { UserContextMenu } from './_components/UserContextMenu'
import { TeamContextMenu } from './_components/TeamContextMenu'
import type { AuthOAuth2SettingsInitialData } from '@/lib/react-query/hooks/oauth2-providers'
import {
  isAuthTeamDetailPath,
  isAuthUserDetailPath,
  isAuthUsersIndexPath,
} from '@/lib/auth-routes'

export type UsersListSearch = {
  search?: string
  query?: string
  page?: number
  limit?: number
  sort?: string
}

export function View({
  usersListSearch,
  authSocialProvidersInitialData,
}: {
  usersListSearch?: UsersListSearch
  authSocialProvidersInitialData?: AuthOAuth2SettingsInitialData
} = {}) {
  const t = useT()
  const { projectId } = useParams({
    strict: false,
  })
  const location = useLocation()
  const navigate = useNavigate()
  const search = useSearch({ strict: false }) as {
    create?: string
    search?: string
    page?: number
    limit?: number
    query?: string
    teamsSearch?: string
    teamsQuery?: string
    teamsPage?: number
    teamsLimit?: number
  }

  const isAuthUsersIndex = isAuthUsersIndexPath(location.pathname, projectId)

  // URL-backed list params for users tab. Prefer validated search from index route (usersListSearch) so tags and table update immediately after navigate; fallback to parsing location.
  const usersListParams = useMemo(() => {
    if (!isAuthUsersIndex) return null
    if (typeof window === 'undefined') return null
    const defaultSort = {
      sortBy: USERS_DEFAULT_SORT_BY,
      sortOrder: USERS_DEFAULT_SORT_ORDER as 'asc' | 'desc',
    }
    if (usersListSearch) {
      const parsed = parseSort(usersListSearch.sort) ?? defaultSort
      return {
        search: usersListSearch.search,
        page: usersListSearch.page ?? 1,
        limit: usersListSearch.limit ?? GRID_DEFAULT_PAGE_SIZE,
        filterMap: queryParamToMap(usersListSearch.query ?? null),
        sortBy: parsed.sortBy,
        sortOrder: parsed.sortOrder,
      }
    }
    const url = urlFromRouterLocation(location, window.location.origin)
    const parsed = getSort(url) ?? defaultSort
    return {
      search: getSearch(url),
      page: getPage(url, 1),
      limit: getLimit(url, GRID_DEFAULT_PAGE_SIZE),
      filterMap: queryParamToMap(getQueryParam(url)),
      sortBy: parsed.sortBy,
      sortOrder: parsed.sortOrder,
    }
  }, [
    isAuthUsersIndex,
    usersListSearch,
    location.pathname,
    location.search,
    projectId,
  ])

  const isAuthTeamsList =
    location.pathname.includes('/auth/teams') &&
    !location.pathname.match(/\/auth\/teams\/[^/]+/)

  // URL-backed list params for teams tab (from search when on auth/teams).
  const teamsListParams = useMemo(() => {
    if (!isAuthTeamsList || typeof search !== 'object') return null
    return {
      search: search.teamsSearch ?? undefined,
      page: search.teamsPage ?? 1,
      limit: search.teamsLimit ?? GRID_DEFAULT_PAGE_SIZE,
      filterMap: queryParamToMap(search.teamsQuery ?? null),
    }
  }, [
    isAuthTeamsList,
    search?.teamsSearch,
    search?.teamsQuery,
    search?.teamsPage,
    search?.teamsLimit,
  ])

  const isUserDetailRoute = useMemo(
    () => isAuthUserDetailPath(location.pathname),
    [location.pathname],
  )

  const isTeamDetailRoute = useMemo(
    () => isAuthTeamDetailPath(location.pathname),
    [location.pathname],
  )

  // Derive active tab from pathname
  const activeTab = useMemo(() => {
    const pathParts = location.pathname.split('/').filter(Boolean)
    const authIndex = pathParts.findIndex((part) => part === 'auth')

    if (authIndex >= 0) {
      // Check if there's a tab segment after 'auth'
      // pathParts structure: ['projects', 'projectId', 'auth', 'tab?']
      if (pathParts[authIndex + 1]) {
        const tabFromPath = pathParts[authIndex + 1]
        if (
          [
            'teams',
            'policies',
            'social-providers',
            'oauth2-server',
            'templates',
            'settings',
          ].includes(tabFromPath)
        ) {
          return tabFromPath
        }
      }
    }

    // Default to users for index route (/projects/:projectId/auth or /projects/:projectId/auth/)
    return 'users'
  }, [location.pathname])

  const policiesSubTab = useMemo((): PoliciesSubTab => {
    const pathParts = location.pathname.split('/').filter(Boolean)
    const authIndex = pathParts.findIndex((part) => part === 'auth')
    if (authIndex >= 0 && pathParts[authIndex + 1] === 'policies') {
      const subTab = pathParts[authIndex + 2]
      if (subTab === 'users') return 'users'
      if (subTab === 'emails') return 'emails'
      if (subTab === 'memberships') return 'memberships'
      if (subTab === 'passwords') return 'passwords'
      if (subTab === 'sessions') return 'sessions'
      return 'sessions'
    }
    return 'sessions'
  }, [location.pathname])

  const { project } = useProject(projectId)
  const { isSmtpEnabled } = useProjectSmtpEnabled(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const showAuthSecuritySettings = canShowAuthSecuritySettings(access, features)
  const showOAuth2Server = features.oauth2Server && showAuthSecuritySettings

  const urlPage = usersListParams?.page ?? 1
  const urlLimit = usersListParams?.limit ?? GRID_DEFAULT_PAGE_SIZE
  const urlSearch = usersListParams?.search
  const urlSortBy = usersListParams?.sortBy ?? USERS_DEFAULT_SORT_BY
  const urlSortOrder = usersListParams?.sortOrder ?? USERS_DEFAULT_SORT_ORDER
  const usersFilterMap = usersListParams?.filterMap ?? new Map()
  const usersFilterQueries =
    usersFilterMap.size > 0 ? Array.from(usersFilterMap.values()) : undefined
  const usersSortParam =
    urlSortBy !== USERS_DEFAULT_SORT_BY ||
    urlSortOrder !== USERS_DEFAULT_SORT_ORDER
      ? encodeSort(urlSortBy, urlSortOrder)
      : undefined

  const teamsUrlPage = teamsListParams?.page ?? 1
  const teamsUrlLimit = teamsListParams?.limit ?? GRID_DEFAULT_PAGE_SIZE
  const teamsUrlSearch = teamsListParams?.search
  const teamsFilterMap = teamsListParams?.filterMap ?? new Map()
  const teamsFilterQueries =
    teamsFilterMap.size > 0 ? Array.from(teamsFilterMap.values()) : undefined

  const [usersSearchInput, setUsersSearchInput] = useState('')
  const usersSearchDebounceRef = useRef<ReturnType<typeof setTimeout> | null>(
    null,
  )

  const [teamsSearchInput, setTeamsSearchInput] = useState('')
  const teamsSearchDebounceRef = useRef<ReturnType<typeof setTimeout> | null>(
    null,
  )
  const [usersViewMode, setUsersViewMode] = useState<'list' | 'grid'>('list')
  const [teamsViewMode, setTeamsViewMode] = useState<'list' | 'grid'>('grid')
  const [createUserDialogOpen, setCreateUserDialogOpen] = useState(false)
  const [createTeamDialogOpen, setCreateTeamDialogOpen] = useState(false)
  const [selectedUsers, setSelectedUsers] = useState<Set<string>>(new Set())
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [selectedTeams, setSelectedTeams] = useState<Set<string>>(new Set())
  const [deleteTeamDialogOpen, setDeleteTeamDialogOpen] = useState(false)

  // Open create user drawer when ?create=user (e.g. from header plus button)
  useEffect(() => {
    if (search?.create === 'user' && !createUserDialogOpen) {
      setCreateUserDialogOpen(true)
      navigate({
        to: location.pathname,
        search: (prev: Record<string, unknown>) => {
          if (!prev || typeof prev !== 'object') return {}
          const next = { ...prev }
          delete next.create
          return Object.keys(next).length === 0 ? {} : next
        },
        replace: true,
      })
    }
  }, [search?.create, createUserDialogOpen, navigate, location.pathname])

  // Open create team drawer when ?create=team (e.g. from command center)
  useEffect(() => {
    if (search?.create === 'team' && !createTeamDialogOpen) {
      setCreateTeamDialogOpen(true)
      navigate({
        to: location.pathname,
        search: (prev: Record<string, unknown>) => {
          if (!prev || typeof prev !== 'object') return {}
          const next = { ...prev }
          delete next.create
          return Object.keys(next).length === 0 ? {} : next
        },
        replace: true,
      })
    }
  }, [search?.create, createTeamDialogOpen, navigate, location.pathname])

  // Sync users search input from URL (e.g. back button)
  useEffect(() => {
    setUsersSearchInput(urlSearch ?? '')
  }, [urlSearch])

  const usersFilterQueryString =
    usersFilterMap.size > 0 ? mapToQueryParam(usersFilterMap) : ''

  // Debounced navigate when users search input changes
  useEffect(() => {
    if (usersSearchDebounceRef.current)
      clearTimeout(usersSearchDebounceRef.current)
    usersSearchDebounceRef.current = setTimeout(() => {
      const trimmed = usersSearchInput.trim()
      if (trimmed === (urlSearch ?? '')) return
      if (trimmed.length > 0 && trimmed.length < MIN_SEARCH_LENGTH) return
      navigate({
        to: '/projects/$projectId/auth/',
        params: { projectId: projectId! },
        search: (prev: Record<string, unknown>) => {
          const next = {
            ...prev,
            ...buildListSearchParams({
              search: trimmed || undefined,
              query: usersFilterQueryString || undefined,
              page: 1,
              limit: urlLimit,
              sort: usersSortParam,
            }),
          }
          // Remove search param when cleared so URL and results update
          if (!trimmed) delete next.search
          return next
        },
        replace: true,
      })
    }, 300)
    return () => {
      if (usersSearchDebounceRef.current)
        clearTimeout(usersSearchDebounceRef.current)
    }
  }, [
    usersSearchInput,
    projectId,
    navigate,
    urlLimit,
    urlSearch,
    usersFilterQueryString,
    usersSortParam,
  ])

  const [usersDisplayedPage, setUsersDisplayedPage] = useState(urlPage)
  const [usersDisplayedSearch, setUsersDisplayedSearch] = useState<
    string | undefined
  >(undefined)
  const [usersDisplayedFilterQueryString, setUsersDisplayedFilterQueryString] =
    useState('')
  const [usersDisplayedSortBy, setUsersDisplayedSortBy] = useState(urlSortBy)
  const [usersDisplayedSortOrder, setUsersDisplayedSortOrder] = useState<
    'asc' | 'desc'
  >(urlSortOrder)
  const usersDisplayedFilterQueries = useMemo(() => {
    if (!usersDisplayedFilterQueryString) return undefined
    const map = queryParamToMap(usersDisplayedFilterQueryString)
    return map.size > 0 ? Array.from(map.values()) : undefined
  }, [usersDisplayedFilterQueryString])
  const hasInitedUsersDisplayedRef = useRef(false)
  const [usersFiltersOpen, setUsersFiltersOpen] = useState(false)

  const [teamsFiltersOpen, setTeamsFiltersOpen] = useState(false)

  const queryClient = useQueryClient()

  // Sync teams search input from URL (e.g. back button)
  useEffect(() => {
    setTeamsSearchInput(teamsUrlSearch ?? '')
  }, [teamsUrlSearch])

  // Clear selection when navigating between pages/routes or when search/filters change
  useEffect(() => {
    setSelectedUsers(new Set())
    setDeleteDialogOpen(false)
    setSelectedTeams(new Set())
    setDeleteTeamDialogOpen(false)
  }, [
    location.pathname,
    projectId,
    urlSearch,
    teamsUrlSearch,
    usersFilterMap.size,
    teamsFilterMap.size,
  ])

  const [teamsDisplayedPage, setTeamsDisplayedPage] = useState(1)
  const [teamsDisplayedSearch, setTeamsDisplayedSearch] = useState<
    string | undefined
  >(undefined)
  const [teamsDisplayedFilterQueryString, setTeamsDisplayedFilterQueryString] =
    useState('')
  const teamsDisplayedFilterQueries = useMemo(() => {
    if (!teamsDisplayedFilterQueryString) return undefined
    const map = queryParamToMap(teamsDisplayedFilterQueryString)
    return map.size > 0 ? Array.from(map.values()) : undefined
  }, [teamsDisplayedFilterQueryString])
  const hasInitedTeamsDisplayedRef = useRef(false)

  // Fetch users for the requested page (URL page - triggers load when user changes page/sort)
  const {
    total: usersTotal,
    isLoading: usersLoading,
    isFetching: usersFetching,
    isFetched: usersFetched,
  } = useProjectUsers(
    projectId,
    urlPage - 1,
    urlLimit,
    urlSearch ?? undefined,
    usersFilterQueries,
    urlSortBy,
    urlSortOrder,
  )

  useEffect(() => {
    if (!isAuthUsersIndex || !usersListParams) return
    if (!hasInitedUsersDisplayedRef.current) {
      setUsersDisplayedPage(urlPage)
      setUsersDisplayedSearch(urlSearch ?? undefined)
      setUsersDisplayedFilterQueryString(usersFilterQueryString)
      setUsersDisplayedSortBy(urlSortBy)
      setUsersDisplayedSortOrder(urlSortOrder)
      hasInitedUsersDisplayedRef.current = true
    }
  }, [
    isAuthUsersIndex,
    usersListParams,
    urlPage,
    urlSearch,
    usersFilterQueryString,
    urlSortBy,
    urlSortOrder,
  ])

  // Fetch users for the displayed page (what we show - stays until new page is ready)
  const {
    users: apiUsers,
    total: displayedUsersTotal,
    isLoading: usersDisplayedLoading,
  } = useProjectUsers(
    projectId,
    usersDisplayedPage - 1,
    urlLimit,
    usersDisplayedSearch ?? undefined,
    usersDisplayedFilterQueries,
    usersDisplayedSortBy,
    usersDisplayedSortOrder,
  )

  useEffect(() => {
    if (!isAuthUsersIndex || usersFetching || usersLoading || !usersFetched)
      return
    const match =
      urlPage === usersDisplayedPage &&
      (urlSearch ?? '') === (usersDisplayedSearch ?? '') &&
      usersFilterQueryString === usersDisplayedFilterQueryString &&
      urlSortBy === usersDisplayedSortBy &&
      urlSortOrder === usersDisplayedSortOrder
    if (!match) {
      setUsersDisplayedPage(urlPage)
      setUsersDisplayedSearch(urlSearch ?? undefined)
      setUsersDisplayedFilterQueryString(usersFilterQueryString)
      setUsersDisplayedSortBy(urlSortBy)
      setUsersDisplayedSortOrder(urlSortOrder)
    }
  }, [
    isAuthUsersIndex,
    usersFetching,
    usersLoading,
    usersFetched,
    urlPage,
    urlSearch,
    usersFilterQueryString,
    urlSortBy,
    urlSortOrder,
    usersDisplayedPage,
    usersDisplayedSearch,
    usersDisplayedFilterQueryString,
    usersDisplayedSortBy,
    usersDisplayedSortOrder,
  ])

  const showUsersLoading = usersDisplayedLoading && apiUsers.length === 0

  // Fetch teams for the requested page (URL page - triggers load when URL changes)
  const {
    total: teamsTotal,
    isLoading: teamsLoading,
    isFetching: teamsFetching,
  } = useProjectTeams(
    projectId,
    teamsUrlPage - 1,
    teamsUrlLimit,
    teamsUrlSearch ?? undefined,
    teamsFilterQueries,
  )

  const teamsFilterQueryString =
    teamsFilterMap.size > 0 ? mapToQueryParam(teamsFilterMap) : ''

  useEffect(() => {
    if (!isAuthTeamsList || !teamsListParams) return
    if (!hasInitedTeamsDisplayedRef.current) {
      setTeamsDisplayedPage(teamsUrlPage)
      setTeamsDisplayedSearch(teamsUrlSearch ?? undefined)
      setTeamsDisplayedFilterQueryString(teamsFilterQueryString)
      hasInitedTeamsDisplayedRef.current = true
    }
  }, [
    isAuthTeamsList,
    teamsListParams,
    teamsUrlPage,
    teamsUrlSearch,
    teamsFilterQueryString,
  ])

  // Fetch teams for the displayed page (what we show - stays until new page is ready)
  const {
    teams: apiTeams,
    total: displayedTeamsTotal,
    isLoading: teamsDisplayedLoading,
  } = useProjectTeams(
    projectId,
    teamsDisplayedPage - 1,
    teamsUrlLimit,
    teamsDisplayedSearch ?? undefined,
    teamsDisplayedFilterQueries,
  )

  useEffect(() => {
    if (!isAuthTeamsList || teamsFetching || teamsLoading) return
    const match =
      teamsUrlPage === teamsDisplayedPage &&
      (teamsUrlSearch ?? '') === (teamsDisplayedSearch ?? '') &&
      teamsFilterQueryString === teamsDisplayedFilterQueryString
    if (!match) {
      setTeamsDisplayedPage(teamsUrlPage)
      setTeamsDisplayedSearch(teamsUrlSearch ?? undefined)
      setTeamsDisplayedFilterQueryString(teamsFilterQueryString)
    }
  }, [
    isAuthTeamsList,
    teamsFetching,
    teamsLoading,
    teamsUrlPage,
    teamsUrlSearch,
    teamsFilterQueryString,
    teamsDisplayedPage,
    teamsDisplayedSearch,
    teamsDisplayedFilterQueryString,
  ])

  const showTeamsLoading = teamsDisplayedLoading && apiTeams.length === 0

  // Mutation to create a user
  const createUserMutation = useCreateProjectUser(projectId)

  // Mutation to create a team
  const createTeamMutation = useCreateProjectTeam(projectId)

  // Extend users with additional metadata for display
  const extendedUsers = useMemo(() => {
    return apiUsers.map((user) => {
      // Determine provider from user data (check for OAuth providers)
      // For now, default to 'email' - this could be enhanced to check user.labels or other fields
      const provider = 'email' // TODO: Extract from user.labels or user.providers if available

      return {
        ...user,
        provider,
        phone: (user as unknown).phone || '',
        mfaEnabled: (user as unknown).mfaEnabled || false,
        emailVerification: (user as unknown).emailVerification || false,
        phoneVerification: (user as unknown).phoneVerification || false,
      }
    })
  }, [apiUsers])

  // Paginated data - users and teams are already paginated by the API
  const paginatedUsers = extendedUsers
  const paginatedTeams = apiTeams

  const getUserVerificationStatus = (user: (typeof paginatedUsers)[number]) => {
    if (user.status === 'verified') {
      return { label: 'Verified', tone: 'success' as const }
    }

    return { label: 'Unverified', tone: 'warning' as const }
  }

  const handleUsersSearchChange = (value: string) => {
    setUsersSearchInput(value)
  }

  const applyUsersFilter = (
    compactKey: CompactFilterKey,
    queryStr: string,
    replaceKey?: CompactFilterKey,
  ) => {
    const newMap = new Map(usersFilterMap)
    if (replaceKey) newMap.delete(replaceKey)
    newMap.set(compactKey, queryStr)
    const queryEncoded = mapToQueryParam(newMap)
    navigate({
      to: '/projects/$projectId/auth/',
      params: { projectId: projectId! },
      search: (prev: Record<string, unknown>) => ({
        ...prev,
        ...buildListSearchParams({
          search: urlSearch,
          query: queryEncoded,
          page: 1,
          limit: urlLimit,
          sort: usersSortParam,
        }),
      }),
      replace: true,
    })
  }

  const removeUsersFilter = (key: CompactFilterKey) => {
    const newMap = new Map(usersFilterMap)
    newMap.delete(key)
    navigate({
      to: '/projects/$projectId/auth/',
      params: { projectId: projectId! },
      search: (prev: Record<string, unknown>) => {
        const next = {
          ...prev,
          ...buildListSearchParams({
            search: urlSearch,
            query: newMap.size > 0 ? mapToQueryParam(newMap) : undefined,
            page: 1,
            limit: urlLimit,
            sort: usersSortParam,
          }),
        }
        if (newMap.size === 0) delete next.query
        return next
      },
      replace: true,
    })
  }

  const clearAllUsersFilters = () => {
    navigate({
      to: '/projects/$projectId/auth/',
      params: { projectId: projectId! },
      search: (prev: Record<string, unknown>) => {
        const next = {
          ...prev,
          ...buildListSearchParams({
            search: urlSearch,
            page: 1,
            limit: urlLimit,
            sort: usersSortParam,
          }),
        }
        delete next.query
        return next
      },
      replace: true,
    })
    setUsersFiltersOpen(false)
  }

  const handleUsersSortChange = (sortBy: string, sortOrder: 'asc' | 'desc') => {
    const sortParam = encodeSort(sortBy, sortOrder)
    navigate({
      to: '/projects/$projectId/auth/',
      params: { projectId: projectId! },
      search: (prev: Record<string, unknown>) => ({
        ...prev,
        ...buildListSearchParams({
          search: urlSearch,
          query: usersFilterQueryString || undefined,
          page: 1,
          limit: urlLimit,
          sort: sortParam,
        }),
      }),
      replace: true,
    })
  }

  const applyTeamsFilter = (
    compactKey: CompactFilterKey,
    queryStr: string,
    replaceKey?: CompactFilterKey,
  ) => {
    const newMap = new Map(teamsFilterMap)
    if (replaceKey) newMap.delete(replaceKey)
    newMap.set(compactKey, queryStr)
    const queryEncoded = mapToQueryParam(newMap)
    navigate({
      to: '/projects/$projectId/auth/teams',
      params: { projectId: projectId! },
      search: (prev: Record<string, unknown>) => {
        const next = { ...prev } as Record<string, unknown>
        next.teamsSearch = teamsUrlSearch ?? undefined
        next.teamsQuery = queryEncoded
        next.teamsPage = 1
        next.teamsLimit = teamsUrlLimit
        if (!next.teamsSearch) delete next.teamsSearch
        delete next.teamsPage
        return next
      },
      replace: true,
    })
  }

  const removeTeamsFilter = (key: CompactFilterKey) => {
    const newMap = new Map(teamsFilterMap)
    newMap.delete(key)
    navigate({
      to: '/projects/$projectId/auth/teams',
      params: { projectId: projectId! },
      search: (prev: Record<string, unknown>) => {
        const next = { ...prev } as Record<string, unknown>
        next.teamsSearch = teamsUrlSearch ?? undefined
        next.teamsQuery = newMap.size > 0 ? mapToQueryParam(newMap) : undefined
        next.teamsPage = 1
        next.teamsLimit = teamsUrlLimit
        if (!next.teamsSearch) delete next.teamsSearch
        if (newMap.size === 0) delete next.teamsQuery
        delete next.teamsPage
        return next
      },
      replace: true,
    })
  }

  const clearAllTeamsFilters = () => {
    navigate({
      to: '/projects/$projectId/auth/teams',
      params: { projectId: projectId! },
      search: (prev: Record<string, unknown>) => {
        const next = { ...prev } as Record<string, unknown>
        next.teamsSearch = teamsUrlSearch ?? undefined
        next.teamsLimit = teamsUrlLimit
        if (!next.teamsSearch) delete next.teamsSearch
        delete next.teamsQuery
        delete next.teamsPage
        return next
      },
      replace: true,
    })
    setTeamsFiltersOpen(false)
  }

  // Bulk delete mutation
  const bulkDeleteMutation = useMutation({
    mutationFn: async (userIds: string[]) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      // Delete all users in parallel
      await Promise.all(
        userIds.map((userId) => deleteProjectUser(projectId, userId)),
      )
    },
    onSuccess: async () => {
      // Refetch users list so the UI updates (list uses refetchOnMount: false)
      await queryClient.refetchQueries({
        queryKey: ['users', 'project', projectId],
      })
      toast.success(
        selectedUsers.size > 1
          ? `${selectedUsers.size} ${t('users deleted successfully')}`
          : t('User deleted successfully'),
      )
      setSelectedUsers(new Set())
      setDeleteDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to delete users'))
    },
  })

  const handleBulkDelete = () => {
    if (selectedUsers.size === 0) return
    setDeleteDialogOpen(true)
  }

  const confirmBulkDelete = () => {
    if (selectedUsers.size === 0) return
    bulkDeleteMutation.mutate(Array.from(selectedUsers))
  }

  const toggleUser = (userId: string) => {
    const newSelected = new Set(selectedUsers)
    if (newSelected.has(userId)) {
      newSelected.delete(userId)
    } else {
      newSelected.add(userId)
    }
    setSelectedUsers(newSelected)
  }

  const toggleAllUsers = () => {
    if (selectedUsers.size === paginatedUsers.length) {
      setSelectedUsers(new Set())
    } else {
      setSelectedUsers(new Set(paginatedUsers.map((u) => u.$id)))
    }
  }

  const handleUsersPageChange = (page: number) => {
    navigate({
      to: '/projects/$projectId/auth/',
      params: { projectId: projectId! },
      search: (prev: Record<string, unknown>) => {
        const next = {
          ...prev,
          ...buildListSearchParams({
            search: urlSearch,
            query:
              usersFilterMap.size > 0
                ? mapToQueryParam(usersFilterMap)
                : undefined,
            page,
            limit: urlLimit,
            sort: usersSortParam,
          }),
        }
        // When going to page 1, buildListSearchParams omits page so prev.page would persist; remove it explicitly
        if (page === 1) delete next.page
        return next
      },
      replace: true,
    })
    setSelectedUsers(new Set())
  }

  const handleUsersPageSizeChange = (newPageSize: number) => {
    navigate({
      to: '/projects/$projectId/auth/',
      params: { projectId: projectId! },
      search: (prev: Record<string, unknown>) => {
        const next = {
          ...prev,
          ...buildListSearchParams({
            search: urlSearch,
            query:
              usersFilterMap.size > 0
                ? mapToQueryParam(usersFilterMap)
                : undefined,
            page: 1,
            limit: newPageSize,
            sort: usersSortParam,
          }),
        }
        // Reset to page 1 when changing size; buildListSearchParams omits page when 1 so remove it
        delete next.page
        return next
      },
      replace: true,
    })
    setSelectedUsers(new Set())
  }

  // Format last accessed date - shows relative time but only at day resolution or higher
  // Never shows hours, minutes, or seconds - uses "today" instead
  const formatLastAccessed = (dateString: string): string => {
    const date = new Date(dateString)
    const now = new Date()
    const diffMs = now.getTime() - date.getTime()
    const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24))

    // Check if it's today (same calendar day)
    const isToday =
      date.getDate() === now.getDate() &&
      date.getMonth() === now.getMonth() &&
      date.getFullYear() === now.getFullYear()

    // Check if it's yesterday (previous calendar day)
    const yesterday = new Date(now)
    yesterday.setDate(yesterday.getDate() - 1)
    const isYesterday =
      date.getDate() === yesterday.getDate() &&
      date.getMonth() === yesterday.getMonth() &&
      date.getFullYear() === yesterday.getFullYear()

    if (isToday) {
      return t('Today')
    } else if (isYesterday) {
      return t('Yesterday')
    } else if (diffDays < 7) {
      return formatRelativeDuration(diffDays, 'day', { t })
    } else if (diffDays < 30) {
      return formatRelativeDuration(Math.floor(diffDays / 7), 'week', { t })
    } else if (diffDays < 365) {
      return formatRelativeDuration(Math.floor(diffDays / 30), 'month', { t })
    } else {
      return formatRelativeDuration(Math.floor(diffDays / 365), 'year', { t })
    }
  }

  const handleTeamsSearchChange = (value: string) => {
    setTeamsSearchInput(value)
    setSelectedTeams(new Set())
  }

  // Debounced navigate when teams search input changes
  useEffect(() => {
    if (activeTab !== 'teams') return
    if (teamsSearchDebounceRef.current)
      clearTimeout(teamsSearchDebounceRef.current)
    teamsSearchDebounceRef.current = setTimeout(() => {
      const trimmed = teamsSearchInput.trim()
      if (trimmed === (teamsUrlSearch ?? '')) return
      navigate({
        to: '/projects/$projectId/auth/teams',
        params: { projectId: projectId! },
        search: (prev: Record<string, unknown>) => {
          const next = { ...prev } as Record<string, unknown>
          next.teamsSearch = trimmed || undefined
          next.teamsQuery = teamsFilterQueryString || undefined
          next.teamsPage = 1
          next.teamsLimit = teamsUrlLimit
          if (!trimmed) delete next.teamsSearch
          if (next.teamsPage === 1) delete next.teamsPage
          return next
        },
        replace: true,
      })
    }, 300)
    return () => {
      if (teamsSearchDebounceRef.current)
        clearTimeout(teamsSearchDebounceRef.current)
    }
  }, [
    activeTab,
    teamsSearchInput,
    projectId,
    navigate,
    teamsUrlSearch,
    teamsUrlLimit,
    teamsFilterQueryString,
  ])

  // Bulk delete mutation for teams
  const bulkDeleteTeamsMutation = useMutation({
    mutationFn: async (teamIds: string[]) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      // Delete all teams in parallel
      await Promise.all(
        teamIds.map((teamId) => deleteProjectTeam(projectId, teamId)),
      )
    },
    onSuccess: async () => {
      // Refetch teams list so the UI updates (list uses refetchOnMount: false)
      await queryClient.refetchQueries({
        queryKey: ['teams', 'project', projectId],
      })
      toast.success(
        selectedTeams.size > 1
          ? `${selectedTeams.size} ${t('teams deleted successfully')}`
          : t('Team deleted successfully'),
      )
      setSelectedTeams(new Set())
      setDeleteTeamDialogOpen(false)
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to delete teams'))
    },
  })

  const handleBulkDeleteTeams = () => {
    if (selectedTeams.size === 0) return
    setDeleteTeamDialogOpen(true)
  }

  const confirmBulkDeleteTeams = () => {
    if (selectedTeams.size === 0) return
    bulkDeleteTeamsMutation.mutate(Array.from(selectedTeams))
  }

  const toggleTeam = (teamId: string) => {
    const newSelected = new Set(selectedTeams)
    if (newSelected.has(teamId)) {
      newSelected.delete(teamId)
    } else {
      newSelected.add(teamId)
    }
    setSelectedTeams(newSelected)
  }

  const toggleAllTeams = () => {
    if (selectedTeams.size === paginatedTeams.length) {
      setSelectedTeams(new Set())
    } else {
      setSelectedTeams(new Set(paginatedTeams.map((t) => t.id)))
    }
  }

  const handleTeamsPageChange = (page: number) => {
    setSelectedTeams(new Set())
    navigate({
      to: '/projects/$projectId/auth/teams',
      params: { projectId: projectId! },
      search: (prev: Record<string, unknown>) => {
        const next = { ...prev } as Record<string, unknown>
        next.teamsSearch = teamsUrlSearch ?? undefined
        next.teamsQuery = teamsFilterQueryString || undefined
        next.teamsPage = page
        next.teamsLimit = teamsUrlLimit
        if (!next.teamsSearch) delete next.teamsSearch
        if (page === 1) delete next.teamsPage
        if (next.teamsLimit === GRID_DEFAULT_PAGE_SIZE) delete next.teamsLimit
        return next
      },
      replace: true,
    })
  }

  const handleTeamsPageSizeChange = (newPageSize: number) => {
    setSelectedTeams(new Set())
    navigate({
      to: '/projects/$projectId/auth/teams',
      params: { projectId: projectId! },
      search: (prev: Record<string, unknown>) => {
        const next = { ...prev } as Record<string, unknown>
        next.teamsSearch = teamsUrlSearch ?? undefined
        next.teamsQuery = teamsFilterQueryString || undefined
        delete next.teamsPage
        next.teamsLimit = newPageSize
        if (!next.teamsSearch) delete next.teamsSearch
        return next
      },
      replace: true,
    })
  }

  // Update tabs with dynamic user and team counts and route paths
  const tabs: Tab[] = useMemo(
    () => [
      {
        id: 'users',
        label: t('Users'),
        to: '/projects/$projectId/auth/',
        params: { projectId: projectId as string },
      },
      {
        id: 'teams',
        label: t('Teams'),
        to: '/projects/$projectId/auth/teams',
        params: { projectId: projectId as string },
      },
      ...(showAuthSecuritySettings
        ? [
            {
              id: 'policies' as const,
              label: t('Policies'),
              to: '/projects/$projectId/auth/policies/sessions',
              params: { projectId: projectId as string },
            },
            {
              id: 'social-providers' as const,
              label: t('Social providers'),
              to: '/projects/$projectId/auth/social-providers',
              params: { projectId: projectId as string },
            },
            ...(showOAuth2Server
              ? [
                  {
                    id: 'oauth2-server' as const,
                    label: t('OAuth2 server'),
                    to: '/projects/$projectId/auth/oauth2-server',
                    params: { projectId: projectId as string },
                  },
                ]
              : []),
            {
              id: 'templates' as const,
              label: t('Templates'),
              to: '/projects/$projectId/auth/templates',
              params: { projectId: projectId as string },
            },
            {
              id: 'settings' as const,
              label: t('Settings'),
              to: '/projects/$projectId/auth/settings',
              params: { projectId: projectId as string },
            },
          ]
        : []),
    ],
    [projectId, showAuthSecuritySettings, showOAuth2Server, t],
  )

  // Redirect from policies/social-providers/oauth2-server/templates/settings when user lacks permission
  useEffect(() => {
    if (!projectId) return
    if (
      !showAuthSecuritySettings &&
      (activeTab === 'policies' ||
        activeTab === 'social-providers' ||
        activeTab === 'settings' ||
        activeTab === 'templates')
    ) {
      navigate({
        to: '/projects/$projectId/auth/',
        params: { projectId },
        replace: true,
      })
      return
    }
    if (activeTab === 'oauth2-server' && !showOAuth2Server) {
      navigate({
        to: '/projects/$projectId/auth/',
        params: { projectId },
        replace: true,
      })
    }
  }, [showAuthSecuritySettings, showOAuth2Server, activeTab, projectId, navigate])

  const getCreateLabel = () => {
    switch (activeTab) {
      case 'users':
        return t('Create User')
      case 'teams':
        return t('Create Team')
      case 'templates':
        return t('Create Template')
      default:
        return undefined
    }
  }

  const handleCreateUser = (userData: {
    userId?: string
    email?: string
    phone?: string
    password?: string
    name?: string
  }) => {
    createUserMutation.mutate(userData, {
      onSuccess: () => {
        toast.success(t('User created successfully'))
        setCreateUserDialogOpen(false)
      },
      onError: (error: Error) => {
        toast.error(error.message || t('Failed to create user'))
      },
    })
  }

  const handleCreateTeam = (teamData: { teamId?: string; name: string }) => {
    createTeamMutation.mutate(teamData, {
      onSuccess: () => {
        toast.success(t('Team created successfully'))
        setCreateTeamDialogOpen(false)
      },
      onError: (error: Error) => {
        toast.error(error.message || t('Failed to create team'))
      },
    })
  }

  const handleCreateClick = () => {
    if (activeTab === 'users') {
      setCreateUserDialogOpen(true)
    } else if (activeTab === 'teams') {
      setCreateTeamDialogOpen(true)
    }
  }

  const noCreatePermission =
    activeTab === 'users'
      ? !canCreateUser(access, features)
      : activeTab === 'teams'
        ? !canCreateTeam(access, features)
        : false
  const createPermissionTooltip =
    noCreatePermission && activeTab === 'users'
      ? t("You don't have permission to create users.")
      : noCreatePermission && activeTab === 'teams'
        ? t("You don't have permission to create teams.")
        : undefined

  // SMTP alert for templates tab
  const smtpAlert =
    activeTab === 'templates' && !isSmtpEnabled ? (
      <div className="border-b border-border bg-amber-500/5">
        <div className="mx-auto w-full max-w-7xl px-4 py-3 sm:px-6">
          <Alert
            variant="default"
            className="border-amber-500/30 bg-transparent"
          >
            <AlertCircle className="h-4 w-4 text-amber-500" />
            <AlertTitle className="text-[13px] font-medium text-amber-600 dark:text-amber-400">
              {t('SMTP server required')}
            </AlertTitle>
            <AlertDescription className="text-[12px] text-amber-600/80 dark:text-amber-400/80">
              <span className="inline">
                {t('Custom SMTP server is required to edit email templates.')}{' '}
                <Link
                  to="/projects/$projectId/settings/smtp"
                  params={{ projectId: projectId as string }}
                  className="font-medium underline hover:no-underline inline"
                >
                  {t('Set up SMTP server')}
                </Link>{' '}
                {t('to customize your email templates.')}
              </span>
            </AlertDescription>
          </Alert>
        </div>
      </div>
    ) : undefined

  const viewToggle =
    activeTab === 'users' ? (
      <ServiceListViewToggle
        viewMode={usersViewMode}
        onViewModeChange={setUsersViewMode}
      />
    ) : activeTab === 'teams' ? (
      <ServiceListViewToggle
        viewMode={teamsViewMode}
        onViewModeChange={setTeamsViewMode}
      />
    ) : undefined

  // Don't render if we're on a detail route (those have their own components)
  // This check is placed after all hooks to comply with React's rules of hooks
  if (isUserDetailRoute || isTeamDetailRoute) {
    return null
  }

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title={t('Auth')}
        tabs={tabs}
        activeTab={activeTab}
        searchPlaceholder={
          activeTab === 'policies' ||
          activeTab === 'social-providers' ||
          activeTab === 'oauth2-server' ||
          activeTab === 'settings' ||
          activeTab === 'templates'
            ? undefined
            : activeTab === 'teams'
              ? t('Search teams...')
              : t('Search users...')
        }
        searchValue={
          activeTab === 'users'
            ? usersSearchInput
            : activeTab === 'teams'
              ? teamsSearchInput
              : undefined
        }
        onSearchChange={
          activeTab === 'users'
            ? handleUsersSearchChange
            : activeTab === 'teams'
              ? handleTeamsSearchChange
              : undefined
        }
        createLabel={activeTab === 'templates' ? undefined : getCreateLabel()}
        createAnalyticsAction={
          activeTab === 'users'
            ? 'create-user'
            : activeTab === 'teams'
              ? 'create-team'
              : undefined
        }
        onCreate={activeTab === 'templates' ? undefined : handleCreateClick}
        createDisabled={noCreatePermission}
        createDisabledTooltip={createPermissionTooltip}
        showFilters={activeTab === 'users' || activeTab === 'teams'}
        filterTrigger={
          activeTab === 'users' ? (
            <FiltersPopover
              open={usersFiltersOpen}
              onOpenChange={setUsersFiltersOpen}
              columns={usersFilterColumns}
              filterMap={usersFilterMap}
              onRemoveFilter={removeUsersFilter}
              onClearAll={clearAllUsersFilters}
              onApplyFilter={applyUsersFilter}
              resourceLabel="users"
              filterScope="auth.users"
              onApplyQuery={(queryParam, sortParam) => {
                navigate({
                  to: '/projects/$projectId/auth/',
                  params: { projectId: projectId! },
                  search: (prev: Record<string, unknown>) => ({
                    ...prev,
                    ...buildListSearchParams({
                      search: urlSearch,
                      query: queryParam ?? undefined,
                      page: 1,
                      limit: urlLimit,
                      sort: sortParam,
                    }),
                  }),
                  replace: true,
                })
              }}
              teamId={project?.teamId}
              sortBy={urlSortBy}
              sortOrder={urlSortOrder}
              onSortChange={handleUsersSortChange}
              defaultSortParam={encodeSort(
                USERS_DEFAULT_SORT_BY,
                USERS_DEFAULT_SORT_ORDER,
              )}
              onReset={() => {
                navigate({
                  to: '/projects/$projectId/auth/',
                  params: { projectId: projectId! },
                  search: { page: 1, limit: urlLimit },
                  replace: true,
                })
              }}
            />
          ) : activeTab === 'teams' ? (
            <FiltersPopover
              open={teamsFiltersOpen}
              onOpenChange={setTeamsFiltersOpen}
              columns={teamsFilterColumns}
              filterMap={teamsFilterMap}
              onRemoveFilter={removeTeamsFilter}
              onClearAll={clearAllTeamsFilters}
              onApplyFilter={applyTeamsFilter}
              resourceLabel="teams"
              filterScope="auth.teams"
              onApplyQuery={(queryParam) => {
                navigate({
                  to: '/projects/$projectId/auth/teams',
                  params: { projectId: projectId! },
                  search: (prev: Record<string, unknown>) => {
                    const next = { ...prev } as Record<string, unknown>
                    next.teamsSearch = teamsUrlSearch ?? undefined
                    next.teamsQuery = queryParam ?? undefined
                    next.teamsPage = 1
                    next.teamsLimit = teamsUrlLimit
                    if (!next.teamsSearch) delete next.teamsSearch
                    return next
                  },
                  replace: true,
                })
              }}
              teamId={project?.teamId}
            />
          ) : undefined
        }
        fullWidthBorder
        contentAfterBorder={smtpAlert}
        rightContent={viewToggle}
      />

      <div
        className={cn(
          'mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6',
          (activeTab === 'policies' ||
            activeTab === 'social-providers' ||
            activeTab === 'oauth2-server' ||
            activeTab === 'templates' ||
            activeTab === 'settings') &&
            'pt-4 sm:pt-6',
        )}
      >
        {activeTab === 'users' && (
          <>
            {showUsersLoading ? (
              <div className="rounded-lg border border-border bg-card py-12 text-center">
                <div className="text-muted-foreground">
                  {t('Loading users...')}
                </div>
              </div>
            ) : usersViewMode === 'list' ? (
              paginatedUsers.length > 0 ? (
                <>
                  <div className="rounded-lg border border-border bg-card overflow-hidden">
                    <Table>
                      <TableHeader>
                        <TableRow className="hover:bg-transparent border-b border-border">
                          <TableHead className="w-[40px] px-4">
                            <Checkbox
                              checked={
                                paginatedUsers.length > 0 &&
                                selectedUsers.size === paginatedUsers.length
                              }
                              onCheckedChange={toggleAllUsers}
                            />
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                            {t('User')}
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                            {t('Contact')}
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-center">
                            {t('Verification')}
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-center w-[80px]">
                            {t('MFA')}
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
                            {t('Joined')}
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
                            {t('Last Active')}
                          </TableHead>
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {paginatedUsers.map((user) => {
                          const emailVerified = user.emailVerification || false
                          const phoneVerified = user.phoneVerification || false
                          const isBlocked = user.status === false
                          const hasEmail = !!user.email
                          const hasPhone = !!user.phone

                          return (
                            <UserContextMenu
                              key={user.$id}
                              projectId={projectId!}
                              user={{
                                $id: user.$id,
                                name: user.name,
                                email: user.email,
                                phone: user.phone,
                                emailVerification: user.emailVerification,
                                phoneVerification: user.phoneVerification,
                                status: user.status as boolean | null,
                              }}
                            >
                              <TableRow
                                className={cn(
                                  'cursor-pointer transition-colors border-b border-border/50',
                                  selectedUsers.has(user.$id)
                                    ? 'bg-sky-100 dark:bg-sky-950'
                                    : 'hover:bg-muted/30',
                                )}
                                onClick={(e) => {
                                  // Don't navigate if clicking on checkbox, link, or their containers
                                  const target = e.target as HTMLElement
                                  if (
                                    target.closest('button') ||
                                    target.closest('[role="checkbox"]') ||
                                    target.closest('a')
                                  ) {
                                    return
                                  }
                                  navigate({
                                    to: '/projects/$projectId/auth/users/$userId',
                                    params: {
                                      projectId: projectId!,
                                      userId: user.$id,
                                    },
                                  })
                                }}
                              >
                                <TableCell
                                  onClick={(e) => e.stopPropagation()}
                                  className="px-4 py-3"
                                >
                                  <Checkbox
                                    checked={selectedUsers.has(user.$id)}
                                    onCheckedChange={() => toggleUser(user.$id)}
                                  />
                                </TableCell>
                                <TableCell className="px-4 py-3">
                                  <Link
                                    to="/projects/$projectId/auth/users/$userId"
                                    params={{
                                      projectId: projectId!,
                                      userId: user.$id,
                                    }}
                                    className="block group"
                                  >
                                    <div className="flex items-center gap-3 min-w-0">
                                      <InitialsAvatar
                                        name={user.name || user.email || ''}
                                        size="sm"
                                        className="shrink-0"
                                      />
                                      <div className="flex-1 min-w-0">
                                        <p className="truncate text-[13px] font-medium text-foreground group-hover:text-foreground transition-colors">
                                          {user.name || t('No name')}
                                        </p>
                                        <div className="mt-0.5">
                                          <CopyableId id={user.$id} size="xs" />
                                        </div>
                                      </div>
                                    </div>
                                  </Link>
                                </TableCell>
                                <TableCell className="px-4 py-3">
                                  <Link
                                    to="/projects/$projectId/auth/users/$userId"
                                    params={{
                                      projectId: projectId!,
                                      userId: user.$id,
                                    }}
                                    className="block"
                                  >
                                    <div className="space-y-1">
                                      {hasEmail && (
                                        <div className="flex items-center gap-1.5 min-w-0">
                                          <Mail className="h-3 w-3 shrink-0 text-muted-foreground/60" />
                                          <span className="truncate text-[12px] text-foreground font-medium">
                                            {user.email}
                                          </span>
                                        </div>
                                      )}
                                      {hasPhone && (
                                        <div className="flex items-center gap-1.5 min-w-0">
                                          <Phone className="h-3 w-3 shrink-0 text-muted-foreground/60" />
                                          <span className="truncate text-[12px] text-foreground font-medium">
                                            {user.phone}
                                          </span>
                                        </div>
                                      )}
                                      {!hasEmail && !hasPhone && (
                                        <span className="text-[12px] text-muted-foreground">
                                          -
                                        </span>
                                      )}
                                    </div>
                                  </Link>
                                </TableCell>
                                <TableCell className="px-4 py-3">
                                  <Link
                                    to="/projects/$projectId/auth/users/$userId"
                                    params={{
                                      projectId: projectId!,
                                      userId: user.$id,
                                    }}
                                    className="block"
                                  >
                                    <div className="flex items-center justify-center gap-2 flex-wrap">
                                      {isBlocked ? (
                                        <Badge
                                          variant="error"
                                          className="text-[10px] shrink-0"
                                        >
                                          {t('Blocked')}
                                        </Badge>
                                      ) : (
                                        <>
                                          {hasEmail && (
                                            <Tooltip>
                                              <TooltipTrigger asChild>
                                                <Badge
                                                  variant={
                                                    emailVerified
                                                      ? 'success'
                                                      : 'warning'
                                                  }
                                                  className="text-[10px] shrink-0"
                                                >
                                                  {emailVerified ? (
                                                    <CheckCircle2 className="h-3 w-3" />
                                                  ) : (
                                                    <XCircle className="h-3 w-3" />
                                                  )}
                                                  {t('Email')}
                                                </Badge>
                                              </TooltipTrigger>
                                              <TooltipContent>
                                                <p className="text-xs">
                                                  {emailVerified
                                                    ? t('Email verified')
                                                    : t('Email unverified')}
                                                </p>
                                              </TooltipContent>
                                            </Tooltip>
                                          )}
                                          {hasPhone && (
                                            <Tooltip>
                                              <TooltipTrigger asChild>
                                                <Badge
                                                  variant={
                                                    phoneVerified
                                                      ? 'success'
                                                      : 'warning'
                                                  }
                                                  className="text-[10px] shrink-0"
                                                >
                                                  {phoneVerified ? (
                                                    <CheckCircle2 className="h-3 w-3" />
                                                  ) : (
                                                    <XCircle className="h-3 w-3" />
                                                  )}
                                                  {t('Phone')}
                                                </Badge>
                                              </TooltipTrigger>
                                              <TooltipContent>
                                                <p className="text-xs">
                                                  {phoneVerified
                                                    ? t('Phone verified')
                                                    : t('Phone unverified')}
                                                </p>
                                              </TooltipContent>
                                            </Tooltip>
                                          )}
                                          {!hasEmail && !hasPhone && (
                                            <span className="text-[11px] text-muted-foreground">
                                              -
                                            </span>
                                          )}
                                        </>
                                      )}
                                    </div>
                                  </Link>
                                </TableCell>
                                <TableCell className="px-4 py-3">
                                  <div className="flex items-center justify-center">
                                    <Link
                                      to="/projects/$projectId/auth/users/$userId"
                                      params={{
                                        projectId: projectId!,
                                        userId: user.$id,
                                      }}
                                      className="block"
                                    >
                                      {user.mfaEnabled ? (
                                        <Tooltip>
                                          <TooltipTrigger asChild>
                                            <div className="flex items-center justify-center">
                                              <CheckCircle2 className="h-4 w-4 text-green-600 dark:text-green-400" />
                                            </div>
                                          </TooltipTrigger>
                                          <TooltipContent>
                                            <p className="text-xs">
                                              {t(
                                                'Multi-factor authentication enabled',
                                              )}
                                            </p>
                                          </TooltipContent>
                                        </Tooltip>
                                      ) : (
                                        <Tooltip>
                                          <TooltipTrigger asChild>
                                            <div className="flex items-center justify-center">
                                              <XCircle className="h-4 w-4 text-muted-foreground/40" />
                                            </div>
                                          </TooltipTrigger>
                                          <TooltipContent>
                                            <p className="text-xs">
                                              {t(
                                                'Multi-factor authentication not enabled',
                                              )}
                                            </p>
                                          </TooltipContent>
                                        </Tooltip>
                                      )}
                                    </Link>
                                  </div>
                                </TableCell>
                                <TableCell className="px-4 py-3">
                                  <Link
                                    to="/projects/$projectId/auth/users/$userId"
                                    params={{
                                      projectId: projectId!,
                                      userId: user.$id,
                                    }}
                                    className="block text-end"
                                  >
                                    <DateTooltip
                                      date={new Date(user.createdAt)}
                                      className="text-[12px] text-muted-foreground font-mono"
                                    />
                                  </Link>
                                </TableCell>
                                <TableCell className="px-4 py-3">
                                  <Link
                                    to="/projects/$projectId/auth/users/$userId"
                                    params={{
                                      projectId: projectId!,
                                      userId: user.$id,
                                    }}
                                    className="block text-end"
                                  >
                                    {user.accessedAt ? (
                                      <span className="text-[12px] text-muted-foreground font-mono">
                                        {formatLastAccessed(user.accessedAt)}
                                      </span>
                                    ) : (
                                      <span className="text-[12px] text-muted-foreground/50 italic">
                                        {t('Never')}
                                      </span>
                                    )}
                                  </Link>
                                </TableCell>
                              </TableRow>
                            </UserContextMenu>
                          )
                        })}
                      </TableBody>
                    </Table>
                  </div>
                  <Pagination
                    currentPage={usersDisplayedPage}
                    totalItems={displayedUsersTotal ?? usersTotal}
                    pageSize={urlLimit}
                    pageSizeOptions={[12, 18, 36, 72]}
                    onPageChange={handleUsersPageChange}
                    onPageSizeChange={handleUsersPageSizeChange}
                    itemLabel="users"
                  />
                </>
              ) : (
                <EmptyState
                  icon={Users}
                  title={
                    urlSearch || usersFilterMap.size > 0
                      ? undefined
                      : t('No users yet')
                  }
                  description={
                    urlSearch
                      ? `${t('No results for')} "${urlSearch}". ${t('Try a different search.')}`
                      : usersFilterMap.size > 0
                        ? t('No users match your filters.')
                        : t(
                            'Create your first user to get started with authentication',
                          )
                  }
                  isEmpty={!(urlSearch || usersFilterMap.size > 0)}
                  hasFilters={!!(urlSearch || usersFilterMap.size > 0)}
                  variant="card"
                />
              )
            ) : (
              <div className="flex flex-col gap-2">
                <div className={RESOURCE_CARD_GRID_CLASSNAME}>
                  {paginatedUsers.map((user) => {
                    const verification = getUserVerificationStatus(user)
                    const cardStatus =
                      verification.tone === 'warning' ? 'warning' : 'active'

                    const subtitle =
                      [user.email, user.phone].filter(Boolean).join(' • ') ||
                      undefined

                    return (
                      <UserContextMenu
                        key={user.$id}
                        projectId={projectId!}
                        user={{
                          $id: user.$id,
                          name: user.name,
                          email: user.email,
                          phone: user.phone,
                          emailVerification: user.emailVerification,
                          phoneVerification: user.phoneVerification,
                          status: user.status as boolean | null,
                        }}
                      >
                        <Link
                          to="/projects/$projectId/auth/users/$userId"
                          params={{ projectId: projectId!, userId: user.$id }}
                        >
                          <ResourceCard
                            title={user.name || '-'}
                            subtitle={subtitle || '-'}
                            resourceId={user.$id}
                            avatar={user.name || user.email || ''}
                            status={cardStatus}
                            statusLabel={t(verification.label)}
                            metadata={[
                              {
                                label: t('Joined'),
                                value: (
                                  <DateTooltip
                                    date={user.createdAt}
                                    className="text-[11px] font-medium text-muted-foreground"
                                  />
                                ),
                              },
                            ]}
                          />
                        </Link>
                      </UserContextMenu>
                    )
                  })}

                  {paginatedUsers.length === 0 && (
                    <div className="col-span-full">
                      <EmptyState
                        icon={Users}
                        title={
                          urlSearch || usersFilterMap.size > 0
                            ? undefined
                            : t('No users yet')
                        }
                        description={
                          urlSearch
                            ? `${t('No results for')} "${urlSearch}". ${t('Try a different search.')}`
                            : usersFilterMap.size > 0
                              ? t('No users match your filters.')
                              : t(
                                  'Create your first user to get started with authentication',
                                )
                        }
                        isEmpty={!(urlSearch || usersFilterMap.size > 0)}
                        hasFilters={!!(urlSearch || usersFilterMap.size > 0)}
                        variant="card"
                      />
                    </div>
                  )}
                </div>
                {paginatedUsers.length > 0 && (
                  <Pagination
                    currentPage={usersDisplayedPage}
                    totalItems={displayedUsersTotal ?? usersTotal}
                    pageSize={urlLimit}
                    pageSizeOptions={[12, 18, 36, 72]}
                    onPageChange={handleUsersPageChange}
                    onPageSizeChange={handleUsersPageSizeChange}
                    itemLabel="users"
                  />
                )}
              </div>
            )}

            {/* Bulk Delete Action Bar */}
            {selectedUsers.size > 0 && (
              <div className="fixed bottom-4 start-1/2 z-50 -translate-x-1/2">
                <div className="mx-auto flex min-w-[400px] items-center justify-between gap-3 rounded-lg border border-border bg-background px-6 py-3">
                  <Badge variant="secondary" className="h-6 px-2.5">
                    {selectedUsers.size}{' '}
                    {selectedUsers.size > 1
                      ? t('users selected')
                      : t('user selected')}
                  </Badge>
                  <div className="flex items-center gap-2">
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() => setSelectedUsers(new Set())}
                      className="h-8 text-xs"
                    >
                      {t('Cancel')}
                    </Button>
                    <Button
                      variant="destructive"
                      size="sm"
                      onClick={handleBulkDelete}
                      disabled={bulkDeleteMutation.isPending}
                      className="h-8 gap-2"
                    >
                      {t('Delete')}
                    </Button>
                  </div>
                </div>
              </div>
            )}

            {/* Bulk Delete Confirmation Dialog */}
            <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
              <DialogContent className="sm:max-w-md p-0">
                <DialogHeader className="px-6 pt-6 text-start">
                  <DialogTitle>{t('Delete Users')}</DialogTitle>
                  <DialogDescription className="text-[13px] mt-2">
                    {t('Are you sure you want to delete')} {selectedUsers.size}{' '}
                    {selectedUsers.size > 1 ? t('users') : t('user')}?{' '}
                    {t('This action cannot be undone.')}
                  </DialogDescription>
                </DialogHeader>

                <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                  <Button
                    variant="outline"
                    onClick={() => setDeleteDialogOpen(false)}
                    disabled={bulkDeleteMutation.isPending}
                  >
                    {t('Cancel')}
                  </Button>
                  <Button
                    variant="destructive"
                    onClick={confirmBulkDelete}
                    disabled={bulkDeleteMutation.isPending}
                  >
                    {t('Delete')}
                  </Button>
                </div>
              </DialogContent>
            </Dialog>
          </>
        )}

        {activeTab === 'teams' && (
          <>
            {showTeamsLoading ? (
              <div className="rounded-lg border border-border bg-card py-12 text-center">
                <div className="text-muted-foreground">
                  {t('Loading teams...')}
                </div>
              </div>
            ) : teamsViewMode === 'list' ? (
              paginatedTeams.length > 0 ? (
                <>
                  <div className="rounded-lg border border-border bg-card overflow-hidden">
                    <Table>
                      <TableHeader>
                        <TableRow className="hover:bg-transparent border-b border-border">
                          <TableHead className="w-[40px] px-4">
                            <Checkbox
                              checked={
                                paginatedTeams.length > 0 &&
                                selectedTeams.size === paginatedTeams.length
                              }
                              onCheckedChange={toggleAllTeams}
                            />
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                            {t('Team')}
                          </TableHead>
                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
                            {t('Created')}
                          </TableHead>
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {paginatedTeams.map((team) => (
                          <TeamContextMenu
                            key={team.id}
                            projectId={projectId!}
                            team={{ id: team.id, name: team.name }}
                          >
                            <TableRow
                              className={cn(
                                'cursor-pointer transition-colors border-b border-border/50',
                                selectedTeams.has(team.id)
                                  ? 'bg-sky-100 dark:bg-sky-950'
                                  : 'hover:bg-muted/30',
                              )}
                              onClick={(e) => {
                                // Don't navigate if clicking on checkbox, link, or their containers
                                const target = e.target as HTMLElement
                                if (
                                  target.closest('button') ||
                                  target.closest('[role="checkbox"]') ||
                                  target.closest('a')
                                ) {
                                  return
                                }
                                navigate({
                                  to: '/projects/$projectId/auth/teams/$teamId',
                                  params: {
                                    projectId: projectId!,
                                    teamId: team.id,
                                  },
                                })
                              }}
                            >
                              <TableCell
                                onClick={(e) => e.stopPropagation()}
                                className="px-4 py-3"
                              >
                                <Checkbox
                                  checked={selectedTeams.has(team.id)}
                                  onCheckedChange={() => toggleTeam(team.id)}
                                />
                              </TableCell>
                              <TableCell className="px-4 py-3">
                                <Link
                                  to="/projects/$projectId/auth/teams/$teamId"
                                  params={{
                                    projectId: projectId!,
                                    teamId: team.id,
                                  }}
                                  className="block group"
                                >
                                  <div className="flex items-center gap-3 min-w-0">
                                    <InitialsAvatar
                                      name={team.name || ''}
                                      size="sm"
                                      className="shrink-0"
                                    />
                                    <div className="flex-1 min-w-0">
                                      <p className="truncate text-[13px] font-medium text-foreground group-hover:text-foreground transition-colors">
                                        {team.name || t('No name')}
                                      </p>
                                      <div className="mt-0.5">
                                        <CopyableId id={team.id} size="xs" />
                                      </div>
                                    </div>
                                  </div>
                                </Link>
                              </TableCell>
                              <TableCell className="px-4 py-3">
                                <Link
                                  to="/projects/$projectId/auth/teams/$teamId"
                                  params={{
                                    projectId: projectId!,
                                    teamId: team.id,
                                  }}
                                  className="block text-end"
                                >
                                  <DateTooltip
                                    date={new Date(team.createdAt)}
                                    className="text-[12px] text-muted-foreground font-mono"
                                  />
                                </Link>
                              </TableCell>
                            </TableRow>
                          </TeamContextMenu>
                        ))}
                      </TableBody>
                    </Table>
                  </div>
                  <Pagination
                    currentPage={teamsDisplayedPage}
                    totalItems={displayedTeamsTotal ?? teamsTotal}
                    pageSize={teamsUrlLimit}
                    pageSizeOptions={[12, 18, 36, 72]}
                    onPageChange={handleTeamsPageChange}
                    onPageSizeChange={handleTeamsPageSizeChange}
                    itemLabel="teams"
                  />
                </>
              ) : (
                <EmptyState
                  icon={Users}
                  title={
                    teamsUrlSearch || teamsFilterMap.size > 0
                      ? undefined
                      : t('No teams yet')
                  }
                  description={
                    teamsUrlSearch || teamsFilterMap.size > 0
                      ? undefined
                      : t('Create your first team to organize users into groups')
                  }
                  isEmpty={!teamsUrlSearch && teamsFilterMap.size === 0}
                  hasFilters={!!teamsUrlSearch || teamsFilterMap.size > 0}
                  variant="card"
                />
              )
            ) : (
              <div className="flex flex-col gap-2">
                <div className={RESOURCE_CARD_GRID_CLASSNAME}>
                  {paginatedTeams.map((team) => (
                    <TeamContextMenu
                      key={team.id}
                      projectId={projectId!}
                      team={{ id: team.id, name: team.name }}
                    >
                      <Link
                        to="/projects/$projectId/auth/teams/$teamId"
                        params={{ projectId: projectId!, teamId: team.id }}
                      >
                        <ResourceCard
                          title={team.name || '-'}
                          resourceId={team.id}
                          avatar={team.name || '-'}
                          metadata={[
                            {
                              label: t('Created'),
                              value: (
                                <DateTooltip
                                  date={team.createdAt}
                                  className="text-[11px] font-medium text-muted-foreground"
                                />
                              ),
                            },
                          ]}
                        />
                      </Link>
                    </TeamContextMenu>
                  ))}

                  {paginatedTeams.length === 0 && (
                    <div className="col-span-full">
                      <EmptyState
                        icon={Users}
                        title={
                          teamsUrlSearch || teamsFilterMap.size > 0
                            ? undefined
                            : t('No teams yet')
                        }
                        description={
                          teamsUrlSearch || teamsFilterMap.size > 0
                            ? undefined
                            : t(
                                'Create your first team to organize users into groups',
                              )
                        }
                        isEmpty={!teamsUrlSearch && teamsFilterMap.size === 0}
                        hasFilters={!!teamsUrlSearch || teamsFilterMap.size > 0}
                        variant="card"
                      />
                    </div>
                  )}
                </div>
                {paginatedTeams.length > 0 && (
                  <Pagination
                    currentPage={teamsDisplayedPage}
                    totalItems={displayedTeamsTotal ?? teamsTotal}
                    pageSize={teamsUrlLimit}
                    pageSizeOptions={[12, 18, 36, 72]}
                    onPageChange={handleTeamsPageChange}
                    onPageSizeChange={handleTeamsPageSizeChange}
                    itemLabel="teams"
                  />
                )}
              </div>
            )}

            {/* Bulk Delete Teams Action Bar */}
            {selectedTeams.size > 0 && (
              <div className="fixed bottom-4 start-1/2 z-50 -translate-x-1/2">
                <div className="mx-auto flex min-w-[400px] items-center justify-between gap-3 rounded-lg border border-border bg-background px-6 py-3">
                  <Badge variant="secondary" className="h-6 px-2.5">
                    {selectedTeams.size}{' '}
                    {selectedTeams.size > 1
                      ? t('teams selected')
                      : t('team selected')}
                  </Badge>
                  <div className="flex items-center gap-2">
                    <Button
                      variant="ghost"
                      size="sm"
                      onClick={() => setSelectedTeams(new Set())}
                      className="h-8 text-xs"
                    >
                      {t('Cancel')}
                    </Button>
                    <Button
                      variant="destructive"
                      size="sm"
                      onClick={handleBulkDeleteTeams}
                      disabled={bulkDeleteTeamsMutation.isPending}
                      className="h-8 gap-2"
                    >
                      {t('Delete')}
                    </Button>
                  </div>
                </div>
              </div>
            )}

            {/* Bulk Delete Teams Confirmation Dialog */}
            <Dialog
              open={deleteTeamDialogOpen}
              onOpenChange={setDeleteTeamDialogOpen}
            >
              <DialogContent className="sm:max-w-md p-0">
                <DialogHeader className="px-6 pt-6 text-start">
                  <DialogTitle>{t('Delete Teams')}</DialogTitle>
                  <DialogDescription className="text-[13px] mt-2">
                    {t('Are you sure you want to delete')} {selectedTeams.size}{' '}
                    {selectedTeams.size > 1 ? t('teams') : t('team')}?{' '}
                    {t('This action cannot be undone.')}
                  </DialogDescription>
                </DialogHeader>

                <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                  <Button
                    variant="outline"
                    onClick={() => setDeleteTeamDialogOpen(false)}
                    disabled={bulkDeleteTeamsMutation.isPending}
                  >
                    {t('Cancel')}
                  </Button>
                  <Button
                    variant="destructive"
                    onClick={confirmBulkDeleteTeams}
                    disabled={bulkDeleteTeamsMutation.isPending}
                  >
                    {t('Delete')}
                  </Button>
                </div>
              </DialogContent>
            </Dialog>
          </>
        )}

        {activeTab === 'policies' && projectId && (
          <PoliciesLayout projectId={projectId} activeSubTab={policiesSubTab}>
            {policiesSubTab === 'users' ? (
              <UsersPolicies projectId={projectId} />
            ) : policiesSubTab === 'emails' ? (
              <EmailsPolicies projectId={projectId} />
            ) : policiesSubTab === 'memberships' ? (
              <MembershipsPolicies projectId={projectId} />
            ) : policiesSubTab === 'passwords' ? (
              <PasswordsPolicies projectId={projectId} />
            ) : (
              <SessionsPolicies projectId={projectId} />
            )}
          </PoliciesLayout>
        )}

        {activeTab === 'social-providers' && projectId && (
          <SocialProviders
            projectId={projectId}
            initialData={authSocialProvidersInitialData}
          />
        )}

        {activeTab === 'oauth2-server' && projectId && (
          <OAuth2ServerLayout projectId={projectId} />
        )}

        {activeTab === 'templates' && projectId && (
          <Templates projectId={projectId} />
        )}

        {activeTab === 'settings' && projectId && (
          <AuthSettings projectId={projectId} />
        )}
      </div>

      <CreateUserDrawer
        open={createUserDialogOpen}
        onOpenChange={setCreateUserDialogOpen}
        onCreate={handleCreateUser}
        isLoading={createUserMutation.isPending}
      />

      <CreateTeamDrawer
        open={createTeamDialogOpen}
        onOpenChange={setCreateTeamDialogOpen}
        onCreate={handleCreateTeam}
        isLoading={createTeamMutation.isPending}
      />
    </div>
  )
}
