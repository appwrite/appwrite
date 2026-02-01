import {
  Link,
  useParams,
  useNavigate,
  useLocation,
  useSearch,
  Outlet,
  useMatches,
} from '@tanstack/react-router'
import {
  Plus,
  Globe,
  Search,
  ChevronDown,
  Check,
  Filter,
  UserPlus,
  Users,
  Shield,
  MoreHorizontal,
  Download,
  FileText,
  ShieldCheck,
  Mail,
  Trash2,
  CheckCircle2,
  XCircle,
  ExternalLink,
  Key,
  AlertTriangle,
  AlertCircle,
  UserCog,
  Code,
  Edit,
  Eye,
  CreditCard,
} from 'lucide-react'
import { useKeyboardShortcut } from '@/hooks/use-keyboard-shortcuts'
import { RegionFlag } from '@/components/global/shared/RegionFlag'
import { type Organization, type TeamMember } from '@/lib/utils/mock-data'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import { PlatformIcon } from '@/components/global/shared/Icon'
import {
  useOrganizationMemberships,
  organizationsQueryOptions,
  activeProjectsQueryOptions,
  useOrganizationPlan,
  useResendMembershipInvite,
  useUpdateMembershipRole,
  useRemoveTeamMember,
} from '@/lib/react-query/hooks'

import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
  DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { Label } from '@/components/ui/label'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useState, useEffect, useMemo, useRef } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { sdk } from '@/lib/appwrite/sdk'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { toast } from 'sonner'
import { ConsoleLayout } from '@/components/global/layout/ConsoleLayout'
import { CommandCenter } from '@/components/global/shared/CommandCenter'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import { cn } from '@/lib/utils'
import { formatDate } from '@/lib/date-utils'
import { getPlanBadgeColor } from '@/lib/utils/plan-badge'
import { getPlanNameFromTier } from '@/lib/utils/plan-filter'
import { BillingTab } from '../billing/BillingTab'
import { View as DomainsView } from '../domains/View'
import { EnterpriseSuccessManager } from '@/components/pages/projects/$projectId/shared/EnterpriseSuccessManager'
import { Pagination } from '@/components/global/shared/Pagination'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { InviteMembersDialog } from './InviteMembers'
import { CreateOrganizationDialog } from './CreateOrganization'
import { CreateProjectDialog } from './CreateProjectDialog'
import { useCreateOrganization } from '@/lib/react-query/hooks'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'

// Environment variables for whitelabeling
const COMPANY_NAME = import.meta.env.VITE_COMPANY_NAME || 'Appwrite'
const CONTACT_SALES_URL =
  import.meta.env.VITE_CONTACT_SALES_URL ||
  'https://appwrite.io/contact-us/enterprise'
const LEGAL_EMAIL = import.meta.env.VITE_LEGAL_EMAIL || 'legal@appwrite.io'

// Pagination constants
const PROJECTS_PER_PAGE = 25
const MEMBERSHIPS_PER_PAGE = 25

// Role options with descriptions (matching InviteMembersDialog)
const ROLE_OPTIONS = [
  {
    value: 'owner',
    label: 'Owner',
    icon: Shield,
    description: 'Full control over all aspects including team and billing.',
  },
  {
    value: 'developer',
    label: 'Developer',
    icon: Code,
    description: 'All resources except team management and billing writes.',
  },
  {
    value: 'editor',
    label: 'Editor',
    icon: Edit,
    description: 'Can modify most resources but not critical backend.',
  },
  {
    value: 'analyst',
    label: 'Analyst',
    icon: Eye,
    description: 'Read-only access across all resources.',
  },
  {
    value: 'billing',
    label: 'Billing',
    icon: CreditCard,
    description: 'Billing and payment management only.',
  },
] as const

// Component to display project platforms and API keys
function ProjectCardFooter({
  platformsCount,
  apiKeysCount,
}: {
  platformsCount: number
  apiKeysCount: number
}) {
  return (
    <div className="mt-3.5 flex items-center gap-2 border-t border-border/60 pt-3.5">
      {/* Platforms Label */}
      {platformsCount > 0 ? (
        <Badge
          variant="secondary"
          className="h-6 gap-1.5 px-2 text-[11px] font-medium border-0"
        >
          <Globe className="h-3 w-3" />
          {platformsCount} app{platformsCount !== 1 ? 's' : ''}
        </Badge>
      ) : (
        <Badge
          variant="warning"
          className="h-6 gap-1.5 px-2 text-[11px] font-medium border-0"
        >
          <AlertTriangle className="h-3 w-3" />
          No apps
        </Badge>
      )}

      {/* API Keys Label */}
      {apiKeysCount > 0 ? (
        <Badge
          variant="secondary"
          className="h-6 gap-1.5 px-2 text-[11px] font-medium border-0"
        >
          <Key className="h-3 w-3" />
          {apiKeysCount} API key{apiKeysCount !== 1 ? 's' : ''}
        </Badge>
      ) : (
        <Badge
          variant="warning"
          className="h-6 gap-1.5 px-2 text-[11px] font-medium border-0"
        >
          <AlertTriangle className="h-3 w-3" />
          No API keys
        </Badge>
      )}
    </div>
  )
}

interface OrgOverviewProps {
  tab?: 'projects' | 'members' | 'domains' | 'billing' | 'settings'
  children?: React.ReactNode
}

export function OrgOverview({ tab: tabProp, children }: OrgOverviewProps) {
  const { account } = useAuth()
  const queryClient = useQueryClient()
  const { orgId } = useParams({ from: '/_public/organizations/$orgId' })
  const location = useLocation()
  const navigate = useNavigate()
  const search = useSearch({ strict: false })
  const matches = useMatches()
  const [searchQuery, setSearchQuery] = useState('')

  // Check if we're on a domain detail route using route matches and pathname (for navigation transitions)
  const isDomainDetailRoute = useMemo(() => {
    // First check route matches (most reliable)
    const isDetailRouteByMatch = matches.some(
      (match) =>
        match.routeId.includes('/domains/$domainId') ||
        match.routeId === '/_public/organizations/$orgId/domains/$domainId' ||
        match.routeId.startsWith(
          '/_public/organizations/$orgId/domains/$domainId',
        ),
    )

    if (isDetailRouteByMatch) {
      return true
    }

    // Fallback: check pathname for detail route pattern (helps during navigation transitions)
    // Pattern: /organizations/:orgId/domains/:domainId
    const pathParts = location.pathname.split('/').filter(Boolean)
    const orgIndex = pathParts.findIndex((part) => part === 'organizations')

    if (
      orgIndex >= 0 &&
      pathParts[orgIndex + 2] === 'domains' &&
      pathParts[orgIndex + 3]
    ) {
      const domainId = pathParts[orgIndex + 3]
      // If the domainId looks like an ID (long alphanumeric), we're on a detail route
      if (domainId && domainId.length > 10) {
        return true
      }
    }

    return false
  }, [matches, location.pathname])

  // Check if we should render children (domains list) vs tab content
  const shouldRenderChildren = useMemo(() => {
    // If we're on a domain detail route, definitely don't render children
    // (though the organization layout should bypass OrgOverview entirely for detail routes)
    if (isDomainDetailRoute) {
      return false
    }

    // Check if we're on the domains index route by looking for the index route match
    const isDomainsIndexRoute = matches.some(
      (match) => match.routeId === '/_public/organizations/$orgId/domains/',
    )

    // Also check pathname as fallback (helps during navigation transitions)
    const pathParts = location.pathname.split('/').filter(Boolean)
    const orgIndex = pathParts.findIndex((part) => part === 'organizations')
    const isDomainsRouteByPath =
      orgIndex >= 0 &&
      pathParts[orgIndex + 2] === 'domains' &&
      !pathParts[orgIndex + 3] // No domainId means we're on the index route

    // Only render children if we're on the domains index route
    // The organization layout will handle detail routes by bypassing OrgOverview entirely
    return isDomainsIndexRoute || isDomainsRouteByPath
  }, [matches, location.pathname, isDomainDetailRoute])

  // Derive active tab from pathname if prop is not provided
  const activeTab = useMemo(() => {
    if (tabProp) return tabProp

    // If we're on a detail route, don't set active tab (let child route handle it)
    if (isDomainDetailRoute) {
      return null
    }

    // Extract tab from pathname
    // Pattern: /organizations/:orgId or /organizations/:orgId/:tab
    const pathParts = location.pathname.split('/').filter(Boolean)
    const orgIndex = pathParts.findIndex((part) => part === 'organizations')

    if (orgIndex >= 0) {
      // Check if there's a tab segment after orgId
      // pathParts structure: ['organizations', 'orgId', 'tab?']
      if (pathParts[orgIndex + 2]) {
        const tabFromPath = pathParts[orgIndex + 2]
        if (
          ['projects', 'members', 'domains', 'billing', 'settings'].includes(
            tabFromPath,
          )
        ) {
          return tabFromPath as
            | 'projects'
            | 'members'
            | 'domains'
            | 'billing'
            | 'settings'
        }
      }
    }

    // Default to projects for index route (/organizations/:orgId or /organizations/:orgId/)
    return 'projects'
  }, [tabProp, location.pathname, isDomainDetailRoute])
  const [commandCenterOpen, setCommandCenterOpen] = useState(false)
  const [orgSwitcherOpen, setOrgSwitcherOpen] = useState(false)
  const [deleteConfirmation, setDeleteConfirmation] = useState('')
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [inviteDialogOpen, setInviteDialogOpen] = useState(false)
  const [updateRoleDialogOpen, setUpdateRoleDialogOpen] = useState(false)
  const [removeMemberDialogOpen, setRemoveMemberDialogOpen] = useState(false)
  const [selectedMember, setSelectedMember] = useState<TeamMember | null>(null)
  const [selectedRole, setSelectedRole] = useState<
    'owner' | 'developer' | 'editor' | 'analyst' | 'billing'
  >('developer')
  const [createOrgDialogOpen, setCreateOrgDialogOpen] = useState(false)
  const [createProjectDialogOpen, setCreateProjectDialogOpen] = useState(false)

  // Command center shortcut (Cmd+K / Ctrl+K)
  useKeyboardShortcut('meta+k', () => {
    setCommandCenterOpen(true)
  })

  useKeyboardShortcut('control+k', () => {
    setCommandCenterOpen(true)
  })

  // Focus search shortcut (/)
  useKeyboardShortcut('/', (e) => {
    e.preventDefault()
    setCommandCenterOpen(true)
  })

  // Pagination state (1-indexed for projects, like storage view)
  const [requestedPage, setRequestedPage] = useState(1)
  const [displayedPage, setDisplayedPage] = useState(1)
  // Alias for projects current page (used in pagination UI; matches activeMembershipsPage pattern)
  const activeProjectsPage = displayedPage
  const [activeMembershipsPage, setActiveMembershipsPage] = useState(0)
  const [membershipsSearchQuery, setMembershipsSearchQuery] = useState('')

  // Fetch organizations from Console SDK (prefetched by route loader)
  const { data: organizationsData, isLoading: organizationsLoading } = useQuery(
    organizationsQueryOptions(),
  )

  // Get organizations list and map to our Organization type
  // Note: The API returns "teams" but they are actually organizations
  const organizations = useMemo(() => {
    if (!organizationsData?.teams) return []

    return organizationsData.teams.map((org: any) => {
      // Use plan filter utility to normalize plan name from tier or billingPlan
      const planName = getPlanNameFromTier(
        org.billingPlan || org.tier || 'free',
      )
      // Map 'custom' to 'enterprise' for compatibility with Organization type
      const plan = (
        planName === 'custom' ? 'enterprise' : planName
      ) as Organization['plan']

      return {
        $id: org.$id,
        name: org.name,
        slug: org.name.toLowerCase().replace(/\s+/g, '-'),
        avatar: undefined, // Organizations from SDK don't have avatar
        plan,
        members: org.total || 0,
      }
    })
  }, [organizationsData])

  // Get selected organization from URL param (orgId)
  const selectedOrg = useMemo(() => {
    if (!orgId || !organizations.length) return null
    return organizations.find((org: Organization) => org.$id === orgId) || null
  }, [orgId, organizations])

  const [orgName, setOrgName] = useState('')

  // Update orgName when selectedOrg changes
  useEffect(() => {
    if (selectedOrg) {
      setOrgName(selectedOrg.name)
      setDeleteConfirmation('')
      // Reset pagination when org changes
      setRequestedPage(1)
      setDisplayedPage(1)
      setActiveMembershipsPage(0)
      setMembershipsSearchQuery('')
    }
  }, [selectedOrg])

  // Check for createOrg search param and open dialog
  useEffect(() => {
    const shouldCreateOrg =
      typeof search === 'object' &&
      'createOrg' in search &&
      search.createOrg === true
    if (shouldCreateOrg && !organizationsLoading && !createOrgDialogOpen) {
      setCreateOrgDialogOpen(true)
      // Remove the search param from URL
      navigate({
        to: location.pathname,
        search: (prev: any) => {
          if (!prev || typeof prev !== 'object') return {}
          const newSearch = { ...prev }
          delete newSearch.createOrg
          // Return empty object if no other params, otherwise return the cleaned object
          return Object.keys(newSearch).length === 0 ? {} : newSearch
        },
        replace: true,
      })
    }
  }, [
    search,
    organizationsLoading,
    createOrgDialogOpen,
    navigate,
    location.pathname,
  ])

  // Handle missing organization: redirect to next org or open creation wizard
  useEffect(() => {
    // Only act if organizations have finished loading and we have an orgId in the URL
    if (organizationsLoading || !orgId) return

    // If the selected org is not found
    if (!selectedOrg) {
      // If there are other organizations, redirect to the first one
      if (organizations.length > 0) {
        navigate({
          to: '/organizations/$orgId',
          params: { orgId: organizations[0].$id },
          replace: true,
        })
      } else if (!createOrgDialogOpen) {
        // No organizations at all, open creation wizard (only if not already open)
        setCreateOrgDialogOpen(true)
      }
    }
  }, [
    selectedOrg,
    organizations,
    organizationsLoading,
    orgId,
    navigate,
    createOrgDialogOpen,
  ])

  // Mutation to update user prefs when switching organizations
  const updateOrgPrefsMutation = useMutation({
    mutationFn: async (orgId: string) => {
      await sdk.forConsole.account.updatePrefs({
        prefs: {
          ...account?.prefs,
          organization: orgId,
        },
      })
    },
    onSuccess: () => {
      // Invalidate account query to refetch with new prefs
      queryClient.invalidateQueries({ queryKey: ['account', 'console'] })
    },
  })

  // Mutation to update organization name
  const updateOrgNameMutation = useMutation({
    mutationFn: async ({ orgId, name }: { orgId: string; name: string }) => {
      await sdk.forConsole.teams.updateName(orgId, name)
    },
    onSuccess: () => {
      // Invalidate organizations query to refetch with updated name
      queryClient.invalidateQueries({ queryKey: ['organizations', 'console'] })
      toast.success('Organization name updated successfully')
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to update organization name')
    },
  })

  // Mutation to delete organization
  const deleteOrgMutation = useMutation({
    mutationFn: async (orgId: string) => {
      await sdk.forConsole.organizations.delete(orgId)
    },
    onSuccess: () => {
      // Invalidate organizations query to refetch the list
      queryClient.invalidateQueries({ queryKey: ['organizations', 'console'] })
      toast.success('Organization deleted successfully')

      // Close the dialog and reset confirmation
      setDeleteDialogOpen(false)
      setDeleteConfirmation('')

      // Navigate to the first available organization or home
      const remainingOrgs = organizations.filter(
        (org: Organization) => org.$id !== orgId,
      )
      if (remainingOrgs.length > 0) {
        navigate({
          to: '/organizations/$orgId',
          params: { orgId: remainingOrgs[0].$id },
          replace: true,
        })
      } else {
        // No organizations left, navigate to home or projects
        navigate({
          to: '/',
          replace: true,
        })
      }
    },
    onError: (error: Error) => {
      toast.error(error.message || 'Failed to delete organization')
    },
  })

  // Mutation to create organization
  const createOrgMutation = useCreateOrganization()

  // Get team ID from URL param (orgId) - no need to wait for selectedOrg state
  // In Appwrite, organizations ARE teams, so we use the organization ID directly as the team ID
  const orgTeamId = orgId || null

  // Fetch data for the requested page (triggers load when user changes page)
  const {
    isLoading: activeProjectsLoading,
    isFetching: activeProjectsFetching,
    error: activeProjectsError,
  } = useQuery(
    activeProjectsQueryOptions(
      orgTeamId,
      requestedPage - 1,
      PROJECTS_PER_PAGE,
      searchQuery,
    ),
  )

  // Fetch data for the displayed page (what we show - stays until new page is ready)
  const {
    data: activeProjectsData,
    isLoading: displayedProjectsLoading,
  } = useQuery(
    activeProjectsQueryOptions(
      orgTeamId,
      displayedPage - 1,
      PROJECTS_PER_PAGE,
      searchQuery,
    ),
  )

  // Only show full loading when we have no data to display (initial load)
  const displayedProjects = activeProjectsData?.projects ?? []
  const showProjectsLoading =
    displayedProjectsLoading && displayedProjects.length === 0

  // Update displayed page only when requested page data is ready (no flash)
  useEffect(() => {
    if (
      !activeProjectsFetching &&
      requestedPage !== displayedPage &&
      !activeProjectsLoading
    ) {
      setDisplayedPage(requestedPage)
    }
  }, [activeProjectsFetching, activeProjectsLoading, requestedPage, displayedPage])

  // Get total count from the first page query (no search) - already fetched in route loader
  // This is used for limit checking and doesn't change when searching
  const { data: totalProjectsData } = useQuery(
    activeProjectsQueryOptions(orgTeamId, 0, PROJECTS_PER_PAGE, ''),
  )

  // Reset pagination when search query changes
  useEffect(() => {
    setRequestedPage(1)
    setDisplayedPage(1)
  }, [searchQuery])

  // Track when projects are actually rendered in the DOM (for controlling full-screen loader)
  const [projectsRendered, setProjectsRendered] = useState(false)
  const projectsContainerRef = useRef<HTMLDivElement>(null)

  // Check if projects are rendered in the DOM and update state
  useEffect(() => {
    // Only check on initial load (not when paginating or searching)
    if (
      !activeProjectsLoading &&
      !activeProjectsFetching &&
      activeProjectsData &&
      displayedPage === 1 &&
      !searchQuery.trim()
    ) {
      // Wait for projects to actually be in the DOM
      const checkProjectsRendered = () => {
        // Check if projects container exists and has project elements
        const container = projectsContainerRef.current
        if (container) {
          const projectElements = container.querySelectorAll(
            '[data-project-card]',
          )
          if (projectElements.length > 0) {
            setProjectsRendered(true)
            return true
          }
        }
        return false
      }

      // Try immediately
      if (!checkProjectsRendered()) {
        // If not rendered yet, use requestAnimationFrame to wait for next paint
        requestAnimationFrame(() => {
          if (!checkProjectsRendered()) {
            // Fallback: wait a bit more for slower renders
            setTimeout(() => {
              checkProjectsRendered()
            }, 100)
          }
        })
      }
    } else {
      // Reset when conditions change (e.g., pagination, search, or new org)
      setProjectsRendered(false)
    }
  }, [
    activeProjectsLoading,
    activeProjectsFetching,
    activeProjectsData,
    displayedPage,
    searchQuery,
    orgId,
  ])

  // Get active projects from API (already filtered by team server-side)
  // Extract platforms and API keys count from raw project data
  const activeProjects = useMemo(() => {
    if (!activeProjectsData?.projects) return []

    return activeProjectsData.projects.map((project: any) => {
      // Extract platforms count from raw project data
      // platforms is an array in the project document
      const platforms = project.platforms || []
      const platformsCount = Array.isArray(platforms) ? platforms.length : 0

      // Extract API keys count from raw project data
      // keys is an array in the project document
      const keys = project.keys || []
      const apiKeysCount = Array.isArray(keys) ? keys.length : 0

      return {
        $id: project.$id,
        name: project.name,
        teamId: project.teamId,
        region: project.region || 'unknown',
        createdAt: project.$createdAt || new Date().toISOString(),
        icon: project.name.charAt(0).toUpperCase(),
        archived: project.status === 'archived',
        platformsCount,
        apiKeysCount,
      }
    })
  }, [activeProjectsData])

  // Group active projects by team
  // Since organizations are teams in Appwrite, we group all projects under the organization
  const projectsByTeam = useMemo(() => {
    if (!selectedOrg || activeProjects.length === 0) return []

    return [
      {
        team: {
          $id: selectedOrg.$id,
          name: selectedOrg.name,
          color: 'from-blue-400 to-violet-500',
          members: selectedOrg.members,
          orgId: selectedOrg.$id,
        },
        projects: activeProjects,
      },
    ]
  }, [selectedOrg, activeProjects])

  // Count total projects in this org
  const totalOrgProjects = useMemo(() => {
    return projectsByTeam.reduce(
      (sum, { projects }) => sum + projects.length,
      0,
    )
  }, [projectsByTeam])

  // Pagination info (from search results)
  const activeProjectsTotal = activeProjectsData?.total || 0

  // Total count of all projects (without search) - for limit checking
  const totalProjectsCount = totalProjectsData?.total || 0

  // Fetch organization plan to check if additional members are supported
  const { plan: organizationPlan } = useOrganizationPlan(orgId)

  // Check if the plan supports additional members
  // Only disable if seats addon is explicitly disabled with supported = false
  const supportsAdditionalMembers = useMemo(() => {
    return organizationPlan?.addons?.seats?.supported !== false
  }, [organizationPlan])

  // Calculate member limit
  // Check both addons.seats and plan.members field
  const memberLimit = useMemo(() => {
    if (!organizationPlan) return null

    // First check for seats addon
    const seatsLimit = organizationPlan?.addons?.seats?.limit
    const seatsPlanIncluded = organizationPlan?.addons?.seats?.planIncluded

    // Then check plan.members field (base plan members)
    const planMembers = (organizationPlan as any)?.members

    // Priority: seats addon limit > seats plan included > plan members
    let limitNum: number | null = null
    if (seatsLimit !== undefined && seatsLimit !== null) {
      limitNum = Number(seatsLimit)
    } else if (seatsPlanIncluded !== undefined && seatsPlanIncluded !== null) {
      limitNum = Number(seatsPlanIncluded)
    } else if (planMembers !== undefined && planMembers !== null) {
      limitNum = Number(planMembers)
    }

    // If limit is 0, it might mean unlimited (check if seats addon is supported)
    if (limitNum === 0 && organizationPlan?.addons?.seats?.supported === true) {
      // If seats addon is supported, 0 might mean unlimited or need to purchase addon
      // For now, treat 0 as unlimited if seats addon is supported
      return null
    }

    return isNaN(limitNum as number) ? null : limitNum
  }, [organizationPlan])

  // Check if the plan supports success team (enterprise plans)
  const supportsSuccessTeam = useMemo(() => {
    if (!organizationPlan) return false
    // Check if plan name is enterprise or if it's a custom/enterprise tier
    const planName = organizationPlan.name?.toLowerCase() || ''
    return planName === 'enterprise' || selectedOrg?.plan === 'enterprise'
  }, [organizationPlan, selectedOrg])

  // Fetch memberships for the selected organization
  const {
    memberships,
    total: membershipsTotal,
    isLoading: membershipsLoading,
    error: membershipsError,
  } = useOrganizationMemberships(
    orgId,
    activeMembershipsPage,
    MEMBERSHIPS_PER_PAGE,
    membershipsSearchQuery,
  )

  // Resend invitation mutation
  const resendInviteMutation = useResendMembershipInvite(orgId)

  // Update membership role mutation
  const updateRoleMutation = useUpdateMembershipRole(orgId)

  // Remove team member mutation
  const removeMemberMutation = useRemoveTeamMember(orgId)

  // Reset memberships pagination when search query changes
  useEffect(() => {
    setActiveMembershipsPage(0)
  }, [membershipsSearchQuery])

  // Org tabs
  const orgTabs = useMemo(() => {
    if (!selectedOrg) return []

    return [
      { id: 'projects', label: 'Projects', to: '/organizations/$orgId' },
      {
        id: 'members',
        label: 'Members',
        to: '/organizations/$orgId/members',
      },
      { id: 'domains', label: 'Domains', to: '/organizations/$orgId/domains/' },
      { id: 'billing', label: 'Billing', to: '/organizations/$orgId/billing' },
      {
        id: 'settings',
        label: 'Settings',
        to: '/organizations/$orgId/settings',
      },
    ]
  }, [selectedOrg, orgId])

  const filteredProjectsByTeam = projectsByTeam
    .map(({ team, projects }) => ({
      team,
      projects: projects.filter((p) =>
        p.name?.toLowerCase().includes(searchQuery.toLowerCase()),
      ),
    }))
    .filter(({ projects }) => projects.length > 0)

  const handleOrgNavigate = (tab: string) => {
    const tabRoutes: Record<string, string> = {
      projects: '/organizations/$orgId',
      members: '/organizations/$orgId/members',
      domains: '/organizations/$orgId/domains/',
      billing: '/organizations/$orgId/billing',
      settings: '/organizations/$orgId/settings',
    }

    const route = tabRoutes[tab]
    if (route) {
      navigate({
        to: route as any,
        params: { orgId: orgId! } as any,
        replace: true,
      })
    }
  }

  const handleSelectOrg = async (org: Organization) => {
    setOrgSwitcherOpen(false)

    // Navigate to the new organization route, preserving the current tab
    const tabRoutes: Record<string, string> = {
      projects: '/organizations/$orgId',
      members: '/organizations/$orgId/members',
      domains: '/organizations/$orgId/domains/',
      billing: '/organizations/$orgId/billing',
      settings: '/organizations/$orgId/settings',
    }

    const route =
      (activeTab && tabRoutes[activeTab as keyof typeof tabRoutes]) ||
      '/organizations/$orgId'
    navigate({
      to: route as any,
      params: { orgId: org.$id } as any,
      replace: true,
    })

    // Update user prefs with the selected organization
    try {
      await updateOrgPrefsMutation.mutateAsync(org.$id)
    } catch (error) {
      console.error('Failed to update organization preference:', error)
      // Continue anyway - the org switch still works
    }
  }

  return (
    <>
      <ConsoleLayout
        header={{
          onCommandCenterOpen: () => setCommandCenterOpen(true),
          onCreateOrganization: () => setCreateOrgDialogOpen(true),
        }}
        showFooter
        containerClassName="org-layout-container"
      >
        {/* Org Header with Switcher */}
        <div>
          {/* Title Row with Org Switcher */}
          <div className="mx-auto flex w-full max-w-7xl items-center justify-between gap-3 px-4 py-4 sm:px-6">
            {/* Left: Org Switcher */}
            <div className="flex items-center gap-2">
              {selectedOrg && (
                <Popover
                  open={orgSwitcherOpen}
                  onOpenChange={setOrgSwitcherOpen}
                >
                  <PopoverTrigger asChild>
                    <button className="group flex min-w-0 h-8 items-center gap-2 rounded-lg px-2 -ml-2 transition-colors hover:bg-accent">
                      <InitialsAvatar name={selectedOrg.name} size="sm" />
                      <h1 className="truncate text-[13px] font-semibold text-foreground">
                        {selectedOrg.name}
                      </h1>
                      <Badge
                        className={cn(
                          'rounded px-1.5 py-0.5 text-[10px] font-medium capitalize shrink-0',
                          getPlanBadgeColor(selectedOrg.plan),
                        )}
                      >
                        {selectedOrg.plan}
                      </Badge>
                      <ChevronDown className="h-3.5 w-3.5 shrink-0 text-muted-foreground transition-transform group-data-[state=open]:rotate-180" />
                    </button>
                  </PopoverTrigger>
                  <PopoverContent
                    align="start"
                    className="w-72 border-border bg-popover p-0"
                  >
                    <div className="border-b border-border px-3 py-2">
                      <p className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                        Switch organization
                      </p>
                    </div>
                    <div className="max-h-64 overflow-y-auto py-1">
                      {organizations.map((org) => (
                        <button
                          key={org.$id}
                          onClick={() => handleSelectOrg(org)}
                          className={cn(
                            'flex w-full items-center gap-3 px-3 py-2 text-left transition-colors hover:bg-accent',
                            selectedOrg.$id === org.$id && 'bg-accent',
                          )}
                        >
                          <InitialsAvatar name={org.name} size="md" />
                          <div className="flex-1 min-w-0">
                            <p className="truncate text-[13px] font-medium text-foreground">
                              {org.name}
                            </p>
                            <div className="flex items-center gap-2">
                              <span
                                className={cn(
                                  'rounded px-1.5 py-0.5 text-[10px] font-medium capitalize',
                                  getPlanBadgeColor(org.plan),
                                )}
                              >
                                {org.plan}
                              </span>
                              <span className="text-[11px] text-muted-foreground">
                                {org.members} member
                                {org.members !== 1 ? 's' : ''}
                              </span>
                            </div>
                          </div>
                          {selectedOrg.$id === org.$id && (
                            <Check className="h-4 w-4 text-muted-foreground" />
                          )}
                        </button>
                      ))}
                    </div>
                    <div className="border-t border-border p-2">
                      <button
                        onClick={() => {
                          setOrgSwitcherOpen(false)
                          setCreateOrgDialogOpen(true)
                        }}
                        className="flex w-full items-center gap-2 rounded-md px-2 py-2 text-[13px] text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                      >
                        <Plus className="h-4 w-4" />
                        Create organization
                      </button>
                    </div>
                  </PopoverContent>
                </Popover>
              )}
              <Button
                variant="ghost"
                size="sm"
                className="h-8 w-8 p-0 rounded-lg hover:bg-accent"
                onClick={() => setCreateOrgDialogOpen(true)}
                title="Create organization"
              >
                <Plus className="h-4 w-4" />
              </Button>
            </div>

            {/* Right: Team Avatars + Invite Button */}
            <div className="flex shrink-0 items-center gap-3">
              {/* Stacked Team Member Avatars - Reserve space even when loading */}
              {selectedOrg && (
                <div className="flex items-center">
                  {membershipsLoading ? (
                    // Placeholder skeleton to reserve space while loading - match exact structure of actual avatars
                    <div className="flex -space-x-2">
                      <div
                        className="relative rounded-full border-2 border-background"
                        style={{ zIndex: 2 }}
                      >
                        <div className="h-8 w-8 rounded-full bg-muted animate-pulse" />
                      </div>
                      <div
                        className="relative rounded-full border-2 border-background"
                        style={{ zIndex: 1 }}
                      >
                        <div className="h-8 w-8 rounded-full bg-muted animate-pulse" />
                      </div>
                    </div>
                  ) : memberships.length > 0 ? (
                    (() => {
                      const displayMembers = memberships.slice(0, 2)
                      const totalCount = membershipsTotal

                      return (
                        <div className="flex -space-x-2">
                          {displayMembers.map(
                            (member: TeamMember, index: number) => (
                              <div
                                key={member.$id}
                                className="relative rounded-full border-2 border-background"
                                style={{
                                  zIndex: displayMembers.length - index,
                                }}
                                title={member.userName}
                              >
                                <InitialsAvatar
                                  name={member.userName}
                                  size="md"
                                />
                              </div>
                            ),
                          )}
                          {totalCount > 2 && (
                            <div
                              className="relative flex h-8 w-8 items-center justify-center rounded-full border-2 border-background bg-muted text-[11px] font-medium text-muted-foreground"
                              style={{ zIndex: 0 }}
                            >
                              +{totalCount - 2}
                            </div>
                          )}
                        </div>
                      )
                    })()
                  ) : (
                    // Empty state - still reserve space with invisible placeholder
                    <div className="flex -space-x-2">
                      <div className="relative h-8 w-8 rounded-full border-2 border-transparent" />
                    </div>
                  )}
                </div>
              )}

              {/* Invite Button */}
              {supportsAdditionalMembers ? (
                <Button
                  size="sm"
                  variant="outline"
                  className="h-8 gap-2 border-border text-[13px] text-muted-foreground hover:bg-accent hover:text-foreground"
                  onClick={() => setInviteDialogOpen(true)}
                >
                  <UserPlus className="h-3.5 w-3.5" />
                  Invite
                </Button>
              ) : (
                <Tooltip>
                  <TooltipTrigger asChild>
                    <span>
                      <Button
                        size="sm"
                        variant="outline"
                        disabled
                        className="h-8 gap-2 border-border text-[13px] text-muted-foreground cursor-not-allowed opacity-50"
                      >
                        <UserPlus className="h-3.5 w-3.5" />
                        Invite
                      </Button>
                    </span>
                  </TooltipTrigger>
                  <TooltipContent>
                    <p className="text-xs">
                      Your current plan does not support additional members.
                      Upgrade your plan to invite team members.
                    </p>
                  </TooltipContent>
                </Tooltip>
              )}
            </div>
          </div>

          {/* Tabs Row */}
          <div className="border-b border-border">
            <div
              className="mx-auto flex w-full max-w-7xl gap-0 overflow-x-auto px-4 sm:px-6"
              role="tablist"
            >
              {orgTabs.map((tab) => (
                <Link
                  key={tab.id}
                  to={tab.to as any}
                  params={{ orgId: orgId! } as any}
                  replace
                  role="tab"
                  aria-selected={activeTab === tab.id}
                  className={cn(
                    'relative flex shrink-0 items-center gap-1.5 px-3 py-2.5 text-[13px] font-medium transition-colors rounded-sm',
                    'focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset',
                    activeTab === tab.id
                      ? 'text-foreground'
                      : 'text-muted-foreground hover:text-foreground/80',
                  )}
                >
                  {tab.label}
                  {activeTab === tab.id && (
                    <div className="absolute bottom-0 left-0 right-0 h-[2px] bg-foreground" />
                  )}
                </Link>
              ))}
            </div>
          </div>
        </div>

        {/* Plan Limit Alert - After Tabs */}
        {activeTab === 'members' &&
          (() => {
            if (!organizationPlan) return null

            const seatsLimit = organizationPlan?.addons?.seats?.limit
            const planIncluded = organizationPlan?.addons?.seats?.planIncluded
            const limitNum = Number(seatsLimit ?? planIncluded)
            const limit = isNaN(limitNum) ? null : limitNum
            const planName = organizationPlan?.name || 'plan'

            // Only show if limit exists and is greater than 0
            if (limit !== null && limit > 0) {
              const isAtLimit = membershipsTotal >= limit
              const isApproachingLimit = membershipsTotal >= limit * 0.5 // Show alert when at 50% of limit

              // Only show alert if at limit or approaching limit (50%+)
              if (!isAtLimit && !isApproachingLimit) {
                return null
              }

              const remaining = Math.max(0, limit - membershipsTotal)

              return (
                <div className="border-b border-border bg-amber-500/5">
                  <div className="mx-auto w-full max-w-7xl px-4 py-3 sm:px-6">
                    <Alert
                      variant="default"
                      className="border-amber-500/30 bg-transparent"
                    >
                      <AlertCircle className="h-4 w-4 text-amber-500" />
                      <div className="flex flex-1 items-start justify-between gap-4">
                        <div className="flex-1 min-w-0">
                          <AlertTitle className="text-[13px] font-medium text-amber-600 dark:text-amber-400">
                            {isAtLimit
                              ? `You've reached the limit of ${limit} member${limit !== 1 ? 's' : ''}`
                              : `Approaching member limit`}
                          </AlertTitle>
                          <AlertDescription className="text-[12px] text-amber-600/80 dark:text-amber-400/80">
                            <span className="inline">
                              {isAtLimit ? (
                                <>
                                  Your {planName} plan includes up to {limit}{' '}
                                  member{limit !== 1 ? 's' : ''}.{' '}
                                  <Link
                                    to="/organizations/$orgId/change-plan"
                                    params={{ orgId: orgId! } as any}
                                    className="font-medium underline hover:no-underline"
                                  >
                                    Upgrade
                                  </Link>{' '}
                                  to unlock more capacity.
                                </>
                              ) : (
                                <>
                                  Your {planName} plan includes up to {limit}{' '}
                                  member{limit !== 1 ? 's' : ''}. You have{' '}
                                  {remaining} remaining.{' '}
                                  <Link
                                    to="/organizations/$orgId/change-plan"
                                    params={{ orgId: orgId! } as any}
                                    className="font-medium underline hover:no-underline"
                                  >
                                    Upgrade
                                  </Link>{' '}
                                  to unlock more capacity.
                                </>
                              )}
                            </span>
                          </AlertDescription>
                        </div>
                        <Button
                          asChild
                          size="sm"
                          className="h-8 shrink-0 bg-amber-500 px-3 text-[12px] font-medium text-amber-950 hover:bg-amber-400 dark:bg-amber-500 dark:text-amber-950 dark:hover:bg-amber-400"
                        >
                          <Link
                            to="/organizations/$orgId/change-plan"
                            params={{ orgId: orgId! } as any}
                          >
                            Upgrade
                          </Link>
                        </Button>
                      </div>
                    </Alert>
                  </div>
                </div>
              )
            }
            return null
          })()}

        {/* Plan Limit Alert - After Tabs */}
        {activeTab === 'projects' &&
          (() => {
            if (!organizationPlan) return null

            const projectLimit = (organizationPlan?.addons as any)?.projects
              ?.limit
            const planIncluded = (organizationPlan?.addons as any)?.projects
              ?.planIncluded
            const limitNum = Number(projectLimit ?? planIncluded)
            const limit = isNaN(limitNum) ? null : limitNum
            const planName = organizationPlan?.name || 'plan'

            // Only show if limit exists and is greater than 0
            if (limit !== null && limit > 0) {
              const isAtLimit = totalProjectsCount >= limit
              const isApproachingLimit = totalProjectsCount >= limit * 0.5 // Show alert when at 50% of limit

              // Only show alert if at limit or approaching limit (50%+)
              if (!isAtLimit && !isApproachingLimit) {
                return null
              }

              const remaining = Math.max(0, limit - totalProjectsCount)

              return (
                <div className="border-b border-border bg-amber-500/5">
                  <div className="mx-auto w-full max-w-7xl px-4 py-3 sm:px-6">
                    <Alert
                      variant="default"
                      className="border-amber-500/30 bg-transparent"
                    >
                      <AlertCircle className="h-4 w-4 text-amber-500" />
                      <div className="flex flex-1 items-start justify-between gap-4">
                        <div className="flex-1 min-w-0">
                          <AlertTitle className="text-[13px] font-medium text-amber-600 dark:text-amber-400">
                            {isAtLimit
                              ? `You've reached the limit of ${limit} project${limit !== 1 ? 's' : ''}`
                              : `Approaching project limit`}
                          </AlertTitle>
                          <AlertDescription className="text-[12px] text-amber-600/80 dark:text-amber-400/80">
                            <span className="inline">
                              {isAtLimit ? (
                                <>
                                  Your {planName} plan includes up to {limit}{' '}
                                  project{limit !== 1 ? 's' : ''}.{' '}
                                  <Link
                                    to="/organizations/$orgId/change-plan"
                                    params={{ orgId: orgId! } as any}
                                    className="font-medium underline hover:no-underline"
                                  >
                                    Upgrade
                                  </Link>{' '}
                                  to unlock more capacity.
                                </>
                              ) : (
                                <>
                                  Your {planName} plan includes up to {limit}{' '}
                                  project{limit !== 1 ? 's' : ''}. You have{' '}
                                  {remaining} remaining.{' '}
                                  <Link
                                    to="/organizations/$orgId/change-plan"
                                    params={{ orgId: orgId! } as any}
                                    className="font-medium underline hover:no-underline"
                                  >
                                    Upgrade
                                  </Link>{' '}
                                  to unlock more capacity.
                                </>
                              )}
                            </span>
                          </AlertDescription>
                        </div>
                        <Button
                          asChild
                          size="sm"
                          className="h-8 shrink-0 bg-amber-500 px-3 text-[12px] font-medium text-amber-950 hover:bg-amber-400 dark:bg-amber-500 dark:text-amber-950 dark:hover:bg-amber-400"
                        >
                          <Link
                            to="/organizations/$orgId/change-plan"
                            params={{ orgId: orgId! } as any}
                          >
                            Upgrade
                          </Link>
                        </Button>
                      </div>
                    </Alert>
                  </div>
                </div>
              )
            }
            return null
          })()}

        {/* Main Content */}
        <div className="flex-1">
          <div className="mx-auto max-w-7xl px-4 py-4 sm:px-6">
            {/* Render child routes (domains list) when on domains route */}
            {shouldRenderChildren && children ? (
              <div className="h-full">{children}</div>
            ) : (
              <>
                {activeTab === 'projects' && (
                  <>
                    {/* Error State */}
                    {activeProjectsError && (
                      <div className="flex flex-col items-center justify-center py-16 text-center">
                        <div className="mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-muted">
                          <Search className="h-6 w-6 text-muted-foreground" />
                        </div>
                        <h3 className="text-[15px] font-medium text-foreground">
                          Failed to load projects
                        </h3>
                        <p className="mt-1 text-[13px] text-muted-foreground">
                          {activeProjectsError instanceof Error
                            ? activeProjectsError.message
                            : 'An error occurred'}
                        </p>
                      </div>
                    )}

                    {/* Projects Content */}
                    {!activeProjectsError && (
                      <>
                        {/* Toolbar: Search + Filters + Create */}
                        <div className="mb-4 flex items-center gap-3">
                          <div className="relative w-64">
                            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                              placeholder="Search by name or ID..."
                              value={searchQuery}
                              onChange={(e) => setSearchQuery(e.target.value)}
                              className="h-9 border-border bg-accent/50 pl-10 text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                            />
                          </div>

                          {(() => {
                            if (!organizationPlan) {
                              return (
                                <Button
                                  className="ml-auto h-9 gap-2 text-[13px] font-medium text-white hover:opacity-90"
                                  style={{ backgroundColor: '#f02e65' }}
                                  onClick={() =>
                                    setCreateProjectDialogOpen(true)
                                  }
                                >
                                  <Plus className="h-4 w-4" />
                                  Create project
                                </Button>
                              )
                            }

                            const projectLimit = (
                              organizationPlan?.addons as any
                            )?.projects?.limit
                            const planIncluded = (
                              organizationPlan?.addons as any
                            )?.projects?.planIncluded
                            const limitNum = Number(
                              projectLimit ?? planIncluded,
                            )
                            const limit = isNaN(limitNum) ? null : limitNum
                            const isAtLimit =
                              limit !== null &&
                              limit > 0 &&
                              totalProjectsCount >= limit

                            return (
                              <TooltipProvider delayDuration={0}>
                                <Tooltip>
                                  <TooltipTrigger asChild>
                                    <div className="ml-auto">
                                      <Button
                                        className="h-9 gap-2 text-[13px] font-medium text-white hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed"
                                        style={{ backgroundColor: '#f02e65' }}
                                        disabled={isAtLimit}
                                        onClick={() =>
                                          setCreateProjectDialogOpen(true)
                                        }
                                      >
                                        <Plus className="h-4 w-4" />
                                        Create project
                                      </Button>
                                    </div>
                                  </TooltipTrigger>
                                  {isAtLimit && (
                                    <TooltipContent side="bottom">
                                      <p>
                                        You've reached the limit for projects on
                                        your plan
                                      </p>
                                    </TooltipContent>
                                  )}
                                </Tooltip>
                              </TooltipProvider>
                            )
                          })()}
                        </div>

                        {/* Loading placeholder - same layout as grid to prevent shift */}
                        {showProjectsLoading ? (
                          <div className="rounded-lg border border-border bg-card py-12 text-center">
                            <p className="text-[13px] text-muted-foreground">
                              Loading projects...
                            </p>
                          </div>
                        ) : (
                          <>
                            {/* Projects by Team */}
                            <div className="space-y-8" ref={projectsContainerRef}>
                              {filteredProjectsByTeam.map(({ team, projects }) => (
                            <div key={team.$id}>
                              {/* Project Cards Grid */}
                              <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                {projects.map((project) => {
                                  return (
                                    <Link
                                      key={project.$id}
                                      to="/projects/$projectId"
                                      params={{ projectId: project.$id }}
                                      data-project-card
                                      className="group relative rounded-xl border border-border bg-card/50 p-4 transition-all hover:border-border hover:bg-card"
                                    >
                                      <div>
                                        <h3 className="text-[14px] font-medium text-foreground group-hover:text-foreground">
                                          {project.name}
                                        </h3>
                                        {project.region && (
                                          <div className="mt-0.5 flex items-center gap-1.5 text-[12px] text-muted-foreground">
                                            <RegionFlag
                                              region={project.region}
                                            />
                                            {project.region}
                                          </div>
                                        )}
                                      </div>

                                      {/* Platforms and API Keys */}
                                      <ProjectCardFooter
                                        platformsCount={
                                          project.platformsCount || 0
                                        }
                                        apiKeysCount={project.apiKeysCount || 0}
                                      />
                                    </Link>
                                  )
                                })}
                              </div>
                            </div>
                          ))}
                        </div>

                            {/* Empty State */}
                            {filteredProjectsByTeam.length === 0 && (
                              <EmptyState
                                icon={Search}
                                title="No projects found"
                                description={
                                  searchQuery
                                    ? undefined
                                    : 'Create your first project to get started'
                                }
                                isEmpty={!searchQuery}
                                hasFilters={!!searchQuery}
                                variant="centered"
                                iconSize="md"
                              />
                            )}

                            {/* Pagination for Active Projects */}
                            {activeProjectsTotal > PROJECTS_PER_PAGE && (
                              <Pagination
                                currentPage={activeProjectsPage}
                                totalItems={activeProjectsTotal}
                                pageSize={PROJECTS_PER_PAGE}
                                onPageChange={(page: number) =>
                                  setRequestedPage(page)
                                }
                                onPageSizeChange={() => {}} // Page size is fixed
                                itemLabel="projects"
                              />
                            )}

                            {/* Enterprise Success Manager - Only show if plan supports it */}
                            {supportsSuccessTeam && <EnterpriseSuccessManager />}
                          </>
                        )}
                      </>
                    )}
                  </>
                )}

                {activeTab === 'members' && (
                  <>
                    {/* Error State */}
                    {membershipsError && (
                      <div className="flex flex-col items-center justify-center py-16 text-center">
                        <div className="mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-muted">
                          <Users className="h-6 w-6 text-muted-foreground" />
                        </div>
                        <h3 className="text-[15px] font-medium text-foreground">
                          Failed to load members
                        </h3>
                        <p className="mt-1 text-[13px] text-muted-foreground">
                          {membershipsError instanceof Error
                            ? membershipsError.message
                            : 'An error occurred'}
                        </p>
                      </div>
                    )}

                    {/* Members Content */}
                    {!membershipsError && (
                      <>
                        {/* Toolbar: Search + Invite */}
                        <div className="mb-4 flex items-center gap-3">
                          <div className="relative w-64">
                            <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                              placeholder="Search members..."
                              value={membershipsSearchQuery}
                              onChange={(e) =>
                                setMembershipsSearchQuery(e.target.value)
                              }
                              className="h-9 border-border bg-accent/50 pl-10 text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                            />
                          </div>

                          {supportsAdditionalMembers ? (
                            <Button
                              className="ml-auto h-9 gap-2 text-[13px] font-medium text-white hover:opacity-90"
                              style={{ backgroundColor: '#f02e65' }}
                              onClick={() => setInviteDialogOpen(true)}
                            >
                              <Plus className="h-4 w-4" />
                              Invite
                            </Button>
                          ) : (
                            <Tooltip>
                              <TooltipTrigger asChild>
                                <span>
                                  <Button
                                    className="ml-auto h-9 gap-2 text-[13px] font-medium text-white cursor-not-allowed opacity-50"
                                    style={{ backgroundColor: '#f02e65' }}
                                    disabled
                                  >
                                    <UserPlus className="h-4 w-4" />
                                    Invite member
                                  </Button>
                                </span>
                              </TooltipTrigger>
                              <TooltipContent>
                                <p className="text-xs">
                                  Upgrade your plan to invite team members.
                                </p>
                              </TooltipContent>
                            </Tooltip>
                          )}
                        </div>

                        {/* Members List or Empty State */}
                        {membershipsLoading ? (
                          <div className="flex flex-col items-center justify-center py-16 text-center">
                            <div className="mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-muted">
                              <Users className="h-6 w-6 text-muted-foreground" />
                            </div>
                            <p className="text-[13px] text-muted-foreground">
                              Loading members...
                            </p>
                          </div>
                        ) : memberships.length > 0 ? (
                          <>
                            <div className="rounded-lg border border-border bg-card overflow-hidden">
                              <Table>
                                <TableHeader>
                                  <TableRow className="hover:bg-transparent border-b border-border">
                                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                                      Member
                                    </TableHead>
                                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-center">
                                      Role
                                    </TableHead>
                                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-center hidden sm:table-cell">
                                      MFA
                                    </TableHead>
                                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right hidden sm:table-cell">
                                      Joined
                                    </TableHead>
                                    <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[40px]"></TableHead>
                                  </TableRow>
                                </TableHeader>
                                <TableBody>
                                  {memberships.map((member: TeamMember) => (
                                    <TableRow
                                      key={member.$id}
                                      className="border-b border-border/50 hover:bg-muted/30 transition-colors"
                                    >
                                      <TableCell className="px-4 py-3">
                                        <div className="flex items-center gap-3 min-w-0">
                                          <InitialsAvatar
                                            name={
                                              member.userName ||
                                              member.userEmail
                                            }
                                            size="sm"
                                            className="shrink-0"
                                          />
                                          <div className="flex-1 min-w-0">
                                            <div className="flex items-center gap-2 flex-wrap">
                                              <p className="truncate text-[13px] font-medium text-foreground">
                                                {member.userName ||
                                                  member.userEmail}
                                              </p>
                                              {member.status === 'pending' && (
                                                <Badge
                                                  variant="secondary"
                                                  className="text-[11px] font-medium border px-2 py-0.5 shrink-0"
                                                >
                                                  Pending
                                                </Badge>
                                              )}
                                            </div>
                                            <div className="mt-0.5">
                                              <p className="truncate text-[12px] text-muted-foreground">
                                                {member.userEmail}
                                              </p>
                                            </div>
                                          </div>
                                        </div>
                                      </TableCell>
                                      <TableCell className="px-4 py-3">
                                        <div className="flex items-center justify-center">
                                          <Badge
                                            variant="secondary"
                                            className={cn(
                                              'inline-flex items-center gap-1 text-[11px] font-medium border px-2 py-0.5',
                                            )}
                                          >
                                            {member.role === 'owner' && (
                                              <Shield className="h-3 w-3" />
                                            )}
                                            {member.role
                                              .charAt(0)
                                              .toUpperCase() +
                                              member.role.slice(1)}
                                          </Badge>
                                        </div>
                                      </TableCell>
                                      <TableCell className="px-4 py-3 hidden sm:table-cell">
                                        <div className="flex items-center justify-center">
                                          {member.status === 'pending' ? (
                                            <span className="text-muted-foreground/50 text-[12px]">
                                              —
                                            </span>
                                          ) : member.mfaEnabled ? (
                                            <Tooltip>
                                              <TooltipTrigger asChild>
                                                <div className="flex items-center justify-center">
                                                  <CheckCircle2 className="h-4 w-4 text-green-600 dark:text-green-400" />
                                                </div>
                                              </TooltipTrigger>
                                              <TooltipContent>
                                                <p className="text-xs">
                                                  Multi-factor authentication
                                                  enabled
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
                                                  Multi-factor authentication
                                                  not enabled
                                                </p>
                                              </TooltipContent>
                                            </Tooltip>
                                          )}
                                        </div>
                                      </TableCell>
                                      <TableCell className="px-4 py-3 hidden sm:table-cell">
                                        <div className="text-right">
                                          {member.status === 'pending' ? (
                                            <span className="text-[12px] text-muted-foreground/70 italic">
                                              Invited
                                            </span>
                                          ) : (
                                            <DateTooltip
                                              date={new Date(member.joinedAt)}
                                              className="text-[12px] text-muted-foreground font-mono"
                                            />
                                          )}
                                        </div>
                                      </TableCell>
                                      <TableCell className="px-4 py-3">
                                        <div className="flex items-center justify-end">
                                          {member.status === 'pending' ? (
                                            <DropdownMenu>
                                              <DropdownMenuTrigger asChild>
                                                <button className="rounded p-1.5 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground">
                                                  <MoreHorizontal className="h-4 w-4" />
                                                </button>
                                              </DropdownMenuTrigger>
                                              <DropdownMenuContent
                                                align="end"
                                                className="w-48"
                                              >
                                                <DropdownMenuItem
                                                  onClick={async () => {
                                                    try {
                                                      // Use the roles array from the member object, or fallback to single role
                                                      const roles =
                                                        member.roles &&
                                                        member.roles.length > 0
                                                          ? member.roles
                                                          : [member.role]
                                                      await resendInviteMutation.mutateAsync(
                                                        {
                                                          membershipId:
                                                            member.membershipId ||
                                                            member.$id,
                                                          email:
                                                            member.userEmail,
                                                          roles,
                                                        },
                                                      )
                                                      toast.success(
                                                        'Invitation resent successfully',
                                                      )
                                                    } catch (error: any) {
                                                      toast.error(
                                                        error?.message ||
                                                          'Failed to resend invitation',
                                                      )
                                                    }
                                                  }}
                                                  disabled={
                                                    resendInviteMutation.isPending
                                                  }
                                                >
                                                  <Mail className="mr-2 h-4 w-4" />
                                                  {resendInviteMutation.isPending
                                                    ? 'Resending...'
                                                    : 'Resend invitation'}
                                                </DropdownMenuItem>
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                  onClick={() => {
                                                    setSelectedMember(member)
                                                    setRemoveMemberDialogOpen(
                                                      true,
                                                    )
                                                  }}
                                                  className="text-red-600 focus:text-red-600 dark:text-red-400 dark:focus:text-red-400"
                                                >
                                                  <Trash2 className="mr-2 h-4 w-4" />
                                                  Remove from team
                                                </DropdownMenuItem>
                                              </DropdownMenuContent>
                                            </DropdownMenu>
                                          ) : (
                                            <DropdownMenu>
                                              <DropdownMenuTrigger asChild>
                                                <button className="rounded p-1.5 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground">
                                                  <MoreHorizontal className="h-4 w-4" />
                                                </button>
                                              </DropdownMenuTrigger>
                                              <DropdownMenuContent
                                                align="end"
                                                className="w-48"
                                              >
                                                <DropdownMenuItem
                                                  onClick={() => {
                                                    setSelectedMember(member)
                                                    const role = member.role as
                                                      | 'owner'
                                                      | 'developer'
                                                      | 'editor'
                                                      | 'analyst'
                                                      | 'billing'
                                                    setSelectedRole(role)
                                                    setUpdateRoleDialogOpen(
                                                      true,
                                                    )
                                                  }}
                                                >
                                                  <UserCog className="mr-2 h-4 w-4" />
                                                  Update role
                                                </DropdownMenuItem>
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                  onClick={() => {
                                                    setSelectedMember(member)
                                                    setRemoveMemberDialogOpen(
                                                      true,
                                                    )
                                                  }}
                                                  className="text-red-600 focus:text-red-600 dark:text-red-400 dark:focus:text-red-400"
                                                >
                                                  <Trash2 className="mr-2 h-4 w-4" />
                                                  Remove from team
                                                </DropdownMenuItem>
                                              </DropdownMenuContent>
                                            </DropdownMenu>
                                          )}
                                        </div>
                                      </TableCell>
                                    </TableRow>
                                  ))}
                                </TableBody>
                              </Table>
                            </div>

                            {/* Pagination for Memberships */}
                            {membershipsTotal > MEMBERSHIPS_PER_PAGE && (
                              <Pagination
                                currentPage={activeMembershipsPage + 1}
                                totalItems={membershipsTotal}
                                pageSize={MEMBERSHIPS_PER_PAGE}
                                onPageChange={(page: number) =>
                                  setActiveMembershipsPage(page - 1)
                                }
                                onPageSizeChange={() => {}} // Page size is fixed
                                itemLabel="members"
                              />
                            )}
                          </>
                        ) : (
                          <EmptyState
                            icon={Users}
                            title="No members found"
                            description={
                              membershipsSearchQuery
                                ? undefined
                                : 'Invite team members to collaborate on your projects'
                            }
                            isEmpty={!membershipsSearchQuery}
                            hasFilters={!!membershipsSearchQuery}
                            variant="centered"
                            iconSize="md"
                          />
                        )}
                      </>
                    )}
                  </>
                )}

                {activeTab === 'settings' && (
                  <div className="space-y-6">
                    {/* Update Organization Name */}
                    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
                      <div className="px-6 py-4">
                        <h3 className="text-[15px] font-semibold text-foreground">
                          Organization Name
                        </h3>
                      </div>
                      <div className="border-t border-border" />
                      <div className="px-6 py-4">
                        <p className="text-[13px] text-muted-foreground">
                          Update your organization's display name. This will be
                          visible to all team members.
                        </p>
                        <Input
                          value={orgName}
                          onChange={(e) => setOrgName(e.target.value)}
                          placeholder="Organization name"
                          className="mt-3 h-9 max-w-sm border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                        />
                      </div>
                      <div className="px-6 py-4 border-t border-border bg-muted/30">
                        <Button
                          size="sm"
                          className="h-9 text-[13px]"
                          disabled={
                            !selectedOrg ||
                            orgName === selectedOrg.name ||
                            !orgName.trim() ||
                            updateOrgNameMutation.isPending
                          }
                          onClick={() => {
                            if (
                              selectedOrg &&
                              orgName.trim() &&
                              orgName !== selectedOrg.name
                            ) {
                              updateOrgNameMutation.mutate({
                                orgId: selectedOrg.$id,
                                name: orgName.trim(),
                              })
                            }
                          }}
                        >
                          Update
                        </Button>
                      </div>
                    </div>

                    {/* DPA - Data Processing Agreement */}
                    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
                      <div className="px-6 py-4">
                        <h3 className="text-[15px] font-semibold text-foreground">
                          Data Processing Agreement (DPA)
                        </h3>
                      </div>
                      <div className="border-t border-border" />
                      <div className="px-6 py-4">
                        <p className="text-[13px] text-muted-foreground">
                          A DPA is a legally binding document that outlines how
                          {COMPANY_NAME} processes personal data on your behalf.
                          It's required for GDPR compliance when handling EU
                          residents' data.
                        </p>
                        <div className="flex items-start gap-3 mt-3">
                          <FileText className="h-4 w-4 text-muted-foreground mt-0.5 shrink-0" />
                          <p className="text-[13px] text-muted-foreground">
                            Download the DPA, review it with your legal team,
                            sign it, and send a copy to{' '}
                            <span className="font-medium text-foreground">
                              {LEGAL_EMAIL}
                            </span>
                            . We'll countersign and return a fully executed copy
                            within 5 business days.
                          </p>
                        </div>
                      </div>
                      <div className="px-6 py-4 border-t border-border bg-muted/30">
                        <Button
                          variant="outline"
                          size="sm"
                          className="h-9 text-[13px]"
                          onClick={() => {
                            window.open(
                              '/legal/dpa.pdf',
                              '_blank',
                              'noopener,noreferrer',
                            )
                          }}
                        >
                          Download DPA
                        </Button>
                      </div>
                    </div>

                    {/* BAA - Business Associate Agreement */}
                    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
                      <div className="px-6 py-4">
                        <h3 className="text-[15px] font-semibold text-foreground">
                          Business Associate Agreement (BAA)
                        </h3>
                      </div>
                      <div className="border-t border-border" />
                      <div className="px-6 py-4">
                        <p className="text-[13px] text-muted-foreground">
                          A BAA is required under HIPAA when a service provider
                          handles Protected Health Information (PHI) on behalf
                          of a covered entity. If your application processes,
                          stores, or transmits health-related data of US
                          patients, you'll need a BAA in place.
                        </p>
                        <div className="flex items-start gap-3 mt-3">
                          <ShieldCheck className="h-4 w-4 text-muted-foreground mt-0.5 shrink-0" />
                          <p className="text-[13px] text-muted-foreground">
                            <span className="font-medium text-foreground">
                              Who needs this:
                            </span>{' '}
                            Healthcare providers, health plans, healthcare
                            clearinghouses, and their business associates
                            building HIPAA-compliant applications.
                          </p>
                        </div>
                      </div>
                      <div className="px-6 py-4 border-t border-border bg-muted/30">
                        <Button
                          variant="outline"
                          size="sm"
                          className="h-9 text-[13px]"
                          onClick={() => {
                            window.open(
                              CONTACT_SALES_URL,
                              '_blank',
                              'noopener,noreferrer',
                            )
                          }}
                        >
                          Contact Sales
                        </Button>
                      </div>
                    </div>

                    {/* SOC-2 Compliance */}
                    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
                      <div className="px-6 py-4">
                        <div className="flex items-center gap-2">
                          <h3 className="text-[15px] font-semibold text-foreground">
                            SOC 2 Type II Report
                          </h3>
                          <span className="rounded-full bg-muted px-2 py-0.5 text-[10px] font-medium text-muted-foreground">
                            Enterprise
                          </span>
                        </div>
                      </div>
                      <div className="border-t border-border" />
                      <div className="px-6 py-4">
                        <p className="text-[13px] text-muted-foreground">
                          SOC 2 Type II is an auditing standard that verifies a
                          service provider's security controls over an extended
                          period. It demonstrates that {COMPANY_NAME} maintains
                          rigorous security practices for data protection,
                          availability, and confidentiality.
                        </p>
                        <div className="flex items-start gap-3 mt-3">
                          <Shield className="h-4 w-4 text-muted-foreground mt-0.5 shrink-0" />
                          <p className="text-[13px] text-muted-foreground">
                            <span className="font-medium text-foreground">
                              Why it matters:
                            </span>{' '}
                            Many enterprise customers and regulated industries
                            require SOC 2 compliance from their vendors. Access
                            to our SOC 2 report is available on Enterprise
                            plans.
                          </p>
                        </div>
                      </div>
                      <div className="px-6 py-4 border-t border-border bg-muted/30">
                        <Button
                          variant="outline"
                          size="sm"
                          className="h-9 text-[13px]"
                          onClick={() => {
                            window.open(
                              CONTACT_SALES_URL,
                              '_blank',
                              'noopener,noreferrer',
                            )
                          }}
                        >
                          Contact Sales
                        </Button>
                      </div>
                    </div>

                    {/* Delete Organization */}
                    <div className="rounded-xl border border-destructive/50 bg-card/50 overflow-hidden">
                      <div className="px-6 py-4">
                        <h3 className="text-[15px] font-semibold text-foreground">
                          Delete Organization
                        </h3>
                      </div>
                      <div className="border-t border-destructive/20" />
                      <div className="px-6 py-4">
                        <p className="text-[13px] text-muted-foreground">
                          Permanently delete this organization and all
                          associated data. This action cannot be undone.
                        </p>

                        {/* Organization Info Summary */}
                        {selectedOrg && (
                          <div className="flex items-center gap-3 mt-4">
                            <InitialsAvatar name={selectedOrg.name} size="md" />
                            <div className="flex-1 min-w-0">
                              <p className="text-[14px] font-medium text-foreground truncate">
                                {selectedOrg.name}
                              </p>
                              <p className="text-[12px] text-muted-foreground">
                                {membershipsTotal} member
                                {membershipsTotal !== 1 ? 's' : ''} •{' '}
                                {totalOrgProjects} project
                                {totalOrgProjects !== 1 ? 's' : ''}
                              </p>
                            </div>

                            {/* Member Avatars */}
                            {memberships.length > 0 && (
                              <div className="flex items-center gap-2">
                                <div className="flex -space-x-2">
                                  {memberships
                                    .slice(0, 4)
                                    .map(
                                      (member: TeamMember, index: number) => (
                                        <div
                                          key={member.$id}
                                          className="relative rounded-full border-2 border-background"
                                          style={{ zIndex: 4 - index }}
                                          title={member.userName}
                                        >
                                          <InitialsAvatar
                                            name={member.userName}
                                            size="sm"
                                          />
                                        </div>
                                      ),
                                    )}
                                  {membershipsTotal > 4 && (
                                    <div
                                      className="relative flex h-6 w-6 items-center justify-center rounded-full border-2 border-background bg-muted text-[10px] font-medium text-muted-foreground"
                                      style={{ zIndex: 0 }}
                                    >
                                      +{membershipsTotal - 4}
                                    </div>
                                  )}
                                </div>
                              </div>
                            )}
                          </div>
                        )}
                      </div>

                      <div className="px-6 py-4 border-t border-destructive/20 bg-destructive/5">
                        <Dialog
                          open={deleteDialogOpen}
                          onOpenChange={setDeleteDialogOpen}
                        >
                          <DialogTrigger asChild>
                            <Button
                              variant="destructive"
                              size="sm"
                              className="h-9 text-[13px]"
                            >
                              Delete organization
                            </Button>
                          </DialogTrigger>
                          <DialogContent className="sm:max-w-md p-0">
                            <DialogHeader className="px-6 pt-6 text-left">
                              <DialogTitle>Delete Organization</DialogTitle>
                              <DialogDescription className="text-[13px] mt-2">
                                Are you sure you want to delete{' '}
                                {selectedOrg && (
                                  <span className="font-medium text-foreground">
                                    {selectedOrg.name}
                                  </span>
                                )}{' '}
                                and all its projects, databases, and files? This
                                action cannot be undone.
                              </DialogDescription>
                            </DialogHeader>
                            <div className="border-t border-border" />
                            <div className="px-6 pb-4 pt-0">
                              <div className="rounded-lg border border-border bg-muted/50 p-3 mb-4 mt-2">
                                {selectedOrg && (
                                  <div className="flex items-center gap-3">
                                    <InitialsAvatar
                                      name={selectedOrg.name}
                                      size="sm"
                                    />
                                    <div>
                                      <p className="text-[13px] font-medium text-foreground">
                                        {selectedOrg.name}
                                      </p>
                                      <p className="text-[11px] text-muted-foreground">
                                        {membershipsTotal} member
                                        {membershipsTotal !== 1 ? 's' : ''} will
                                        lose access • {activeProjectsTotal}{' '}
                                        project
                                        {activeProjectsTotal !== 1
                                          ? 's'
                                          : ''}{' '}
                                        will be deleted
                                      </p>
                                    </div>
                                  </div>
                                )}
                              </div>
                              {activeProjects.length > 0 && (
                                <div className="mb-4">
                                  <p className="text-[12px] text-muted-foreground">
                                    Projects that will be deleted:{' '}
                                    {activeProjects
                                      .slice(0, 5)
                                      .map((project, index) => (
                                        <span key={project.$id}>
                                          {index > 0 && ', '}
                                          <span className="font-medium text-foreground">
                                            {project.name}
                                          </span>
                                        </span>
                                      ))}
                                    {activeProjectsTotal > 5 && (
                                      <span>
                                        {' '}
                                        and {activeProjectsTotal - 5} more
                                      </span>
                                    )}
                                  </p>
                                </div>
                              )}

                              <label className="text-[13px] text-muted-foreground">
                                Type{' '}
                                {selectedOrg && (
                                  <span className="font-mono font-medium text-foreground bg-muted px-1.5 py-0.5 rounded">
                                    {selectedOrg.name}
                                  </span>
                                )}{' '}
                                to confirm
                              </label>
                              <Input
                                value={deleteConfirmation}
                                onChange={(e) =>
                                  setDeleteConfirmation(e.target.value)
                                }
                                placeholder="Enter organization name"
                                className="mt-2 h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-red-500/50 focus:ring-0"
                              />
                            </div>

                            <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                              <Button
                                variant="outline"
                                size="sm"
                                className="h-9 text-[13px]"
                                onClick={() => {
                                  setDeleteDialogOpen(false)
                                  setDeleteConfirmation('')
                                }}
                              >
                                Cancel
                              </Button>
                              <Button
                                variant="destructive"
                                size="sm"
                                className="h-9 text-[13px]"
                                disabled={
                                  !selectedOrg ||
                                  deleteConfirmation !== selectedOrg.name ||
                                  deleteOrgMutation.isPending
                                }
                                onClick={() => {
                                  if (
                                    selectedOrg &&
                                    deleteConfirmation === selectedOrg.name
                                  ) {
                                    deleteOrgMutation.mutate(selectedOrg.$id)
                                  }
                                }}
                              >
                                Delete
                              </Button>
                            </div>
                          </DialogContent>
                        </Dialog>
                      </div>
                    </div>
                  </div>
                )}

                {activeTab === 'billing' && <BillingTab />}

                {activeTab === 'domains' && <DomainsView />}
              </>
            )}
          </div>
        </div>
      </ConsoleLayout>

      {/* Command Center */}
      <CommandCenter
        open={commandCenterOpen}
        onOpenChange={setCommandCenterOpen}
        context="org"
        onOrgNavigate={handleOrgNavigate}
        orgId={orgId}
      />

      {/* Invite Members Dialog */}
      {orgId && (
        <InviteMembersDialog
          open={inviteDialogOpen}
          onOpenChange={setInviteDialogOpen}
          organizationId={orgId}
          currentMemberCount={membershipsTotal}
          memberLimit={memberLimit}
        />
      )}

      {/* Update Role Dialog */}
      <Dialog
        open={updateRoleDialogOpen}
        onOpenChange={(open) => {
          setUpdateRoleDialogOpen(open)
          if (!open) {
            setSelectedMember(null)
          }
        }}
      >
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-left">
            <DialogTitle>Update Role</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Update the role for{' '}
              {selectedMember?.userName ||
                selectedMember?.userEmail ||
                'this member'}
              .
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 pb-4 pt-0">
            <div className="space-y-1.5">
              <label className="text-[13px] font-medium text-foreground mb-1.5 block">
                Role
              </label>
              <RadioGroup
                value={selectedRole}
                onValueChange={(
                  value:
                    | 'owner'
                    | 'developer'
                    | 'editor'
                    | 'analyst'
                    | 'billing',
                ) => setSelectedRole(value)}
                className="rounded-lg border border-border bg-card/50 overflow-hidden divide-y divide-border gap-0"
              >
                {ROLE_OPTIONS.map((role) => {
                  const Icon = role.icon
                  const isSelected = selectedRole === role.value
                  return (
                    <div
                      key={role.value}
                      className="first:rounded-t-lg last:rounded-b-lg [&:not(:first-child)]:border-t-0"
                    >
                      <RadioGroupItem
                        value={role.value}
                        id={`role-${role.value}`}
                        className="peer sr-only"
                      />
                      <Label
                        htmlFor={`role-${role.value}`}
                        className={cn(
                          'flex cursor-pointer items-start gap-2.5 px-3 py-2.5 transition-colors',
                          'hover:bg-accent',
                          isSelected && 'bg-accent',
                        )}
                      >
                        <div className="mt-0.5 shrink-0">
                          <div
                            className={cn(
                              'h-3.5 w-3.5 rounded-full border-2 flex items-center justify-center transition-colors',
                              isSelected
                                ? 'border-foreground'
                                : 'border-muted-foreground',
                            )}
                          >
                            {isSelected && (
                              <div className="h-1.5 w-1.5 rounded-full bg-foreground" />
                            )}
                          </div>
                        </div>
                        <Icon className="h-3.5 w-3.5 shrink-0 mt-0.5 text-muted-foreground" />
                        <div className="flex flex-col min-w-0 flex-1">
                          <span className="text-[13px] font-medium text-foreground">
                            {role.label}
                          </span>
                          <span className="text-[11px] text-muted-foreground leading-tight mt-0.5">
                            {role.description}
                          </span>
                        </div>
                      </Label>
                    </div>
                  )
                })}
              </RadioGroup>
            </div>
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => {
                setUpdateRoleDialogOpen(false)
                setSelectedMember(null)
              }}
            >
              Cancel
            </Button>
            <Button
              size="sm"
              className="h-9 text-[13px]"
              disabled={
                !selectedMember ||
                selectedRole === selectedMember.role ||
                updateRoleMutation.isPending
              }
              onClick={async () => {
                if (!selectedMember) return
                try {
                  await updateRoleMutation.mutateAsync({
                    membershipId:
                      selectedMember.membershipId || selectedMember.$id,
                    roles: [selectedRole],
                  })
                  toast.success('Role updated successfully')
                  setUpdateRoleDialogOpen(false)
                  setSelectedMember(null)
                } catch (error: any) {
                  toast.error(error?.message || 'Failed to update role')
                }
              }}
            >
              Update role
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {/* Remove Member Dialog */}
      <Dialog
        open={removeMemberDialogOpen}
        onOpenChange={(open) => {
          setRemoveMemberDialogOpen(open)
          if (!open) {
            setSelectedMember(null)
          }
        }}
      >
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-left">
            <DialogTitle>
              {selectedMember?.status === 'pending'
                ? 'Cancel Invitation'
                : 'Remove from Team'}
            </DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {selectedMember?.status === 'pending' ? (
                <>
                  Are you sure you want to cancel the invitation for{' '}
                  {selectedMember?.userName ||
                    selectedMember?.userEmail ||
                    'this member'}
                  ? They will not be able to join the organization.
                </>
              ) : (
                <>
                  Are you sure you want to remove{' '}
                  {selectedMember?.userName ||
                    selectedMember?.userEmail ||
                    'this member'}{' '}
                  from the team? They will lose access to all organization
                  resources.
                </>
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => {
                setRemoveMemberDialogOpen(false)
                setSelectedMember(null)
              }}
            >
              Cancel
            </Button>
            <Button
              variant="destructive"
              size="sm"
              className="h-9 text-[13px]"
              disabled={!selectedMember || removeMemberMutation.isPending}
              onClick={async () => {
                if (!selectedMember) return
                try {
                  await removeMemberMutation.mutateAsync(
                    selectedMember.membershipId || selectedMember.$id,
                  )
                  toast.success(
                    selectedMember.status === 'pending'
                      ? 'Invitation cancelled successfully'
                      : 'Member removed successfully',
                  )
                  setRemoveMemberDialogOpen(false)
                  setSelectedMember(null)
                } catch (error: any) {
                  toast.error(error?.message || 'Failed to remove member')
                }
              }}
            >
              {selectedMember?.status === 'pending'
                ? 'Cancel invitation'
                : 'Remove from team'}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {/* Create Organization Dialog */}
      <CreateOrganizationDialog
        open={createOrgDialogOpen}
        onOpenChange={setCreateOrgDialogOpen}
        onCreate={async (orgData) => {
          try {
            const newOrg = await createOrgMutation.mutateAsync(orgData)
            toast.success('Organization created successfully')
            setCreateOrgDialogOpen(false)
            // Navigate to the newly created organization
            navigate({
              to: '/organizations/$orgId',
              params: { orgId: newOrg.$id },
              replace: true,
            })
          } catch (error: any) {
            toast.error(error?.message || 'Failed to create organization')
          }
        }}
        isLoading={createOrgMutation.isPending}
      />

      {/* Create Project Dialog */}
      <CreateProjectDialog
        open={createProjectDialogOpen}
        onOpenChange={setCreateProjectDialogOpen}
        teamId={orgTeamId}
        organizationPlan={organizationPlan}
        currentProjectsCount={totalProjectsCount}
      />
    </>
  )
}
