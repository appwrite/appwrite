import {
  Link,
  useParams,
  useNavigate,
  useLocation,
  useSearch,
  useMatches} from '@tanstack/react-router'
import {
  Plus,
  Folder,
  Search,
  ChevronDown,
  Check,
  UserPlus,
  Users,
  Shield,
  ShieldCheck,
  Mail,
  Trash2,
  CheckCircle2,
  XCircle,
  Key,
  AlertCircle,
  AlertTriangle,
  UserCog,
  Code,
  Edit,
  Eye,
  CreditCard,
  Settings,
  KeyRound,
  Info,
  ExternalLink,
  ChevronRight,
  Pin,
  PinOff} from '@/lib/icons'
import { useSequentialShortcuts } from '@/hooks/use-keyboard-shortcuts'
import { useGlobalCommandShortcuts } from '@/lib/keyboard-shortcuts/use-global-command-shortcuts'
import { type Organization, type TeamMember } from '@/lib/utils/mock-data'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Badge } from '@/components/ui/badge'
import {
  organizationsQueryOptions,
  organizationQueryOptions,
  organizationPlanQueryOptions,
  prefetchOrganizationInvoiceDataIfAllowed,
  organizationScopesQueryOptions,
  organizationProjectScopeQueryOptions,
  activeProjectsQueryOptions,
  projectsByIdsQueryOptions,
  deleteOrganization,
  organizationMembershipsQueryOptions,
  mapOrganizationMembershipsToTeamMembers,
  consoleTeamQueryOptions,
  useConsoleTeam,
  useUpdateConsoleTeamPrefs,
  pinnedProjectsQueryOptions,
  organizationDomainsQueryOptions,
  DOMAINS_DEFAULT_SORT_BY,
  DOMAINS_DEFAULT_SORT_ORDER,
  useOrganizationPlan,
  useOrganizationFailedInvoicePresence,
  isOrganizationBillingReadonlyStatus,
  isBudgetLimitReached,
  isPlanUsageLimitReached,
  useOrganizationScopes,
  useResendMembershipInvite,
  useUpdateMembershipRole,
  useRemoveTeamMember,
  syncConsoleAccountAfterMutation,
  updateAccountPrefs,
  mapProjectToListItem,
  useProjectListPlatforms,
  useProjectListRequestsUsage,
  formatProjectNameForDisplay} from '@/lib/react-query/hooks'
import {
  parsePinnedProjectIds,
  buildPinnedProjectIdsPrefs,
  reorderPinnedProjectIds,
  MAX_PINNED_PROJECTS} from '@/lib/team-prefs-keys'
import { GRID_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import {
  canSeeProjects,
  canShowProjectSettings,
  canShowOrgDomainsTab,
  canShowOrgMarketplaceTab,
  canShowOrgSettingsTab,
  canAccessOrgSettingsOverview,
  canAccessOrgSettingsMembers,
  canAccessOrgSettingsBilling,
  canAccessOrgSettingsCompliance,
  canShowOrgApiKeysSettings,
  canShowOrgOAuthAppsSettings,
  getFirstAllowedOrgSettingsPath,
  canInviteOrgMember,
  canCreateProject,
  canPinProjects,
  canAccessOrgOverviewTab,
  getFirstAllowedOrgOverviewPath,
  canShowOrgBillingNav,
  canShowOrgComplianceNav} from '@/lib/console-access-checks'
import { OrgMemberContextMenu } from './_components/OrgMemberContextMenu'
import { ProjectContextMenu } from './_components/ProjectContextMenu'
import {
  ProjectListCardFooter,
  ProjectListCardMain} from './_components/ProjectListCardContent'
import { ProjectListCardRequestsChart } from './_components/ProjectListRequestsChart'
import { ProjectsListTable } from './_components/ProjectsListTable'
import {
  LightningCollectorGame,
  LightningCollectorTrigger,
} from './_components/LightningCollectorGame'
import { InitOrgPromoBanner } from './_components/InitOrgPromoBanner'

import {
  Popover,
  PopoverContent,
  PopoverTrigger} from '@/components/ui/popover'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
  DropdownMenuSeparator} from '@/components/ui/dropdown-menu'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { Label } from '@/components/ui/label'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger} from '@/components/ui/dialog'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger} from '@/components/ui/tooltip'
import {
  useState,
  useEffect,
  useMemo,
  useRef,
  useCallback,
  type DragEvent} from 'react'
import {
  useQuery,
  useMutation,
  useQueryClient,
  keepPreviousData} from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { toast } from 'sonner'
import { ConsoleLayout } from '@/components/global/layout/ConsoleLayout'
import { CommandCenter } from '@/components/global/shared/CommandCenter'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import { cn } from '@/lib/utils'
import { registerCommandCenterOpener } from '@/lib/command-center/opener-bridge'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import { getPlanBadgeColor, getPlanDisplayName } from '@/lib/utils/plan-badge'
import {
  getPlanNameFromTier,
  resolveOrganizationPlanDisplayLabel,
  type CanonicalPlanId} from '@/lib/utils/plan-filter'
import { BillingTab } from '../billing/BillingTab'
import { ComplianceTab } from '../settings/ComplianceTab'
import { View as DomainsView } from '../domains/View'
import { useOrganizationDomainsPlanLimit } from '../domains/_components/useOrganizationDomainsPlanLimit'
import { View as MarketplaceView } from '../marketplace/View'
import { View as OrgAppsView } from '../apps/View'
import { EnterpriseSuccessManager } from '@/components/pages/projects/$projectId/shared/EnterpriseSuccessManager'
import { Pagination } from '@/components/global/shared/Pagination'
import { PlanLimitWarning } from '@/components/pages/projects/$projectId/shared/PlanLimitWarning'
import { OrganizationBillingHeaderBanners } from '@/components/global/shared/OrganizationBillingHeaderBanners'
import { FailedInvoiceWarningIcon } from '@/components/global/shared/FailedInvoiceWarningIcon'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { InviteMembersDialog } from './InviteMembers'
import {
  ProjectAccessSelector,
  DEFAULT_PROJECT_ROLE,
} from './_components/ProjectAccessSelector'
import {
  buildProjectRole,
  parseProjectAccess,
  projectIdsFromRoles,
  type ProjectAccessEntry,
} from '@/lib/console-project-roles'
import { CreateOrganizationDialog } from './CreateOrganization'
import { CreateProjectDialog } from './CreateProjectDialog'
import { useCreateOrganization } from '@/lib/react-query/hooks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { SettingsLayoutShell } from '@/components/global/shared/settings-search/SettingsLayoutShell'
import {
  SettingsCardsList,
  type SettingsCardItem} from '@/components/global/shared/settings-search/SettingsCardsList'
import {
  ORG_SETTINGS_CARD_INDEX,
  SOC2_SETTINGS_KEYWORDS} from '@/lib/settings-search/org-settings-cards'
import { useDebugMode } from '@/components/global/providers/DebugMode'
import { useDebugOverrides } from '@/lib/debug-overrides'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow} from '@/components/ui/table'
import {
  RESOURCE_CARD_GRID_CLASSNAME,
  RESOURCE_CARD_INTERACTIVE_CLASSNAME,
  RESOURCE_CARD_PADDED_CLASSNAME} from '@/components/pages/projects/$projectId/shared/ResourceCard'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { GripVertical } from 'lucide-react'
import { useServiceListViewMode } from '@/hooks/use-service-list-view-mode'
import { ServiceListViewToggle } from '@/components/pages/projects/$projectId/shared/ServiceListViewToggle'
import { useT } from '@/lib/i18n/translate'
import { analyticsAttrs, getOrgTabAnalyticsAction } from '@/lib/analytics-actions'

function DomainsPlanLimitAlert({ orgId }: { orgId: string | undefined }) {
  const { currentCount, limit, plan, planName } =
    useOrganizationDomainsPlanLimit(orgId)

  if (plan === undefined) return null

  return (
    <PlanLimitWarning
      currentCount={currentCount}
      limit={limit}
      planName={planName}
      resourceName="domains"
      orgId={orgId}
      fullWidth={false}
    />
  )
}

// Role options with descriptions (matching InviteMembersDialog)
const ROLE_OPTIONS = [
  {
    value: 'owner',
    label: 'Owner',
    icon: Shield,
    description: 'Full control over all aspects including team and billing.'},
  {
    value: 'developer',
    label: 'Developer',
    icon: Code,
    description: 'All resources except team management and billing writes.'},
  {
    value: 'editor',
    label: 'Editor',
    icon: Edit,
    description: 'Can modify most resources but not critical backend.'},
  {
    value: 'analyst',
    label: 'Analyst',
    icon: Eye,
    description: 'Read-only access across all resources.'},
  {
    value: 'billing',
    label: 'Billing',
    icon: CreditCard,
    description: 'Billing and payment management only.'},
] as const

function orgMembershipRoleDisplay(role: string): {
  Icon: (typeof ROLE_OPTIONS)[number]['icon']
  label: string
} {
  const opt = ROLE_OPTIONS.find((r) => r.value === role)
  if (opt) return { Icon: opt.icon, label: opt.label }
  return {
    Icon: Users,
    label: role.charAt(0).toUpperCase() + role.slice(1)}
}

/** Single source for role badges, so the table and its tooltips cannot drift. */
function OrgRoleBadge({ role }: { role: string }) {
  const t = useT()
  const { Icon, label } = orgMembershipRoleDisplay(role)
  return (
    <Badge
      variant="secondary"
      className={cn(
        'inline-flex items-center gap-1 border px-2 py-0.5 text-[11px] font-medium',
      )}
    >
      <Icon className="h-3 w-3 shrink-0" aria-hidden />
      {t(label)}
    </Badge>
  )
}

/** Header avatar stack beside Invite: fixed width fits this many md avatars. */
const HEADER_MEMBER_AVATAR_SLOTS = 2

function EmptyMemberAvatarSlot({
  zIndex,
  onClick,
  disabled,
  disabledTooltip}: {
  zIndex: number
  onClick: () => void
  disabled?: boolean
  disabledTooltip?: string
}) {
  const t = useT()
  const button = (
    <button
      type="button"
      disabled={disabled}
      onClick={onClick}
      {...analyticsAttrs('invite-org-member')}
      className={cn(
        'relative flex shrink-0 items-center justify-center rounded-full border-2 border-background focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
        disabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer',
      )}
      style={{ zIndex }}
      title={disabled ? undefined : t('Invite member')}
      aria-label={t('Invite member')}
    >
      <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border border-dashed border-muted-foreground/35 bg-muted/25">
        <Plus className="h-3.5 w-3.5 text-muted-foreground" />
      </div>
    </button>
  )

  if (!disabled || !disabledTooltip) {
    return button
  }

  return (
    <TooltipProvider delayDuration={0}>
      <Tooltip>
        <TooltipTrigger asChild>
          <span className="relative inline-flex shrink-0" style={{ zIndex }}>
            {button}
          </span>
        </TooltipTrigger>
        <TooltipContent className="max-w-xs text-xs">
          {disabledTooltip}
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  )
}

import { ProjectSelector } from '@/components/global/shared/ProjectSelector'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'

interface OrgOverviewProps {
  tab?: 'projects' | 'marketplace' | 'domains' | 'settings'
  children?: React.ReactNode
}

export function OrgOverview({ tab: tabProp, children }: OrgOverviewProps) {
  const t = useT()
  const { account } = useAuth()
  const queryClient = useQueryClient()
  const { orgId } = useParams({ from: '/_public/organizations/$orgId' })
  const location = useLocation()
  const navigate = useNavigate()
  const search = useSearch({ strict: false })
  const matches = useMatches()
  const [searchQuery, setSearchQuery] = useState('')
  const [pinnedDragOverIndex, setPinnedDragOverIndex] = useState<number | null>(
    null,
  )
  const [pinnedDraggingIndex, setPinnedDraggingIndex] = useState<number | null>(
    null,
  )
  const pinnedDragPreviewRef = useRef<HTMLDivElement | null>(null)
  const { features, isCloud } = useConsoleProfile()
  const supportsMultiTenancy = features.multiTenancy
  const { access, isLoading: orgScopesLoading } = useOrganizationScopes(orgId)
  const { viewMode: projectsViewMode, setViewMode: setProjectsViewMode } =
    useServiceListViewMode('projects')

  const { data: failedInvoicePresence } =
    useOrganizationFailedInvoicePresence(orgId)
  const showFailedInvoiceOrgAlert =
    features.billing && failedInvoicePresence?.hasFailedInvoice === true
  const { showSuccessTeamCard: debugShowSuccessTeamCard } = useDebugOverrides()
  const { isDebugModeOpen } = useDebugMode()
  const [lightningCollectorOpen, setLightningCollectorOpen] = useState(false)

  useEffect(() => {
    if (!isDebugModeOpen) {
      setLightningCollectorOpen(false)
    }
  }, [isDebugModeOpen])

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

  // App detail (owned or catalog): pathname updates before matches during navigation.
  const isAppDetailRoute = useMemo(() => {
    const isDetailRouteByMatch = matches.some(
      (match) =>
        match.routeId.includes('/apps/$appId') ||
        match.routeId.includes('/marketplace/$appId'),
    )

    if (isDetailRouteByMatch) {
      return true
    }

    const pathParts = location.pathname.split('/').filter(Boolean)
    const orgIndex = pathParts.findIndex((part) => part === 'organizations')

    if (orgIndex < 0) {
      return false
    }

    const segment = pathParts[orgIndex + 2]
    const resourceId = pathParts[orgIndex + 3]

    return (
      !!resourceId && (segment === 'apps' || segment === 'marketplace')
    )
  }, [matches, location.pathname])

  // Check if we should render children (domains / marketplace list) vs tab content
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

    const isMarketplaceIndexRoute = matches.some(
      (match) => match.routeId === '/_public/organizations/$orgId/marketplace/',
    )

    // Also check pathname as fallback (helps during navigation transitions)
    const pathParts = location.pathname.split('/').filter(Boolean)
    const orgIndex = pathParts.findIndex((part) => part === 'organizations')
    const isDomainsRouteByPath =
      orgIndex >= 0 &&
      pathParts[orgIndex + 2] === 'domains' &&
      !pathParts[orgIndex + 3] // No domainId means we're on the index route

    const isMarketplaceRouteByPath =
      orgIndex >= 0 &&
      pathParts[orgIndex + 2] === 'marketplace' &&
      !pathParts[orgIndex + 3]

    // Only render children on list index routes; detail routes bypass OrgOverview
    return (
      isDomainsIndexRoute ||
      isDomainsRouteByPath ||
      isMarketplaceIndexRoute ||
      isMarketplaceRouteByPath
    )
  }, [matches, location.pathname, isDomainDetailRoute])

  // Derive active tab from pathname if prop is not provided
  const activeTab = useMemo(() => {
    if (tabProp) return tabProp

    // If we're on a detail route, don't set active tab (let child route handle it)
    if (isDomainDetailRoute || isAppDetailRoute) {
      return null
    }

    // Extract tab from pathname
    // Pattern: /organizations/:orgId or /organizations/:orgId/:tab
    const pathParts = location.pathname.split('/').filter(Boolean)
    const orgIndex = pathParts.findIndex((part) => part === 'organizations')

    if (orgIndex >= 0) {
      // Check if there's a tab segment after orgId
      // pathParts structure: ['organizations', 'orgId', 'tab?', 'subTab?']
      if (pathParts[orgIndex + 2]) {
        const tabFromPath = pathParts[orgIndex + 2]
        // settings and settings/billing both map to 'settings' tab
        if (tabFromPath === 'settings') {
          return 'settings'
        }
        if (['projects', 'marketplace', 'domains'].includes(tabFromPath)) {
          return tabFromPath as 'projects' | 'marketplace' | 'domains'
        }
      }
    }

    // Default to projects for index route (/organizations/:orgId or /organizations/:orgId/)
    return 'projects'
  }, [tabProp, location.pathname, isDomainDetailRoute, isAppDetailRoute])

  // Settings sub-tab (when on settings): 'overview' | 'members' | 'billing' | 'compliance' | 'oauth-apps' | 'api-keys'
  const settingsSubTab = useMemo(() => {
    const pathParts = location.pathname.split('/').filter(Boolean)
    const orgIndex = pathParts.findIndex((part) => part === 'organizations')
    if (orgIndex >= 0 && pathParts[orgIndex + 2] === 'settings') {
      const subTab = pathParts[orgIndex + 3]
      if (subTab === 'members') return 'members'
      if (subTab === 'billing') return 'billing'
      if (subTab === 'compliance') return 'compliance'
      if (subTab === 'oauth-apps') return 'oauth-apps'
      if (subTab === 'api-keys') return 'api-keys'
      return 'overview'
    }
    return 'overview'
  }, [location.pathname])

  const orgSettingsNavItems = useMemo(() => {
    const allNavItems = [
      {
        id: 'overview',
        label: t('General'),
        to: '/organizations/$orgId/settings',
        icon: Settings,
        keywords: ['general', 'overview', 'name', 'delete']},
      {
        id: 'members',
        label: t('Members'),
        to: '/organizations/$orgId/settings/members',
        icon: Users,
        keywords: ['members', 'team', 'invite', 'roles']},
      ...(features.billing
        ? [
            {
              id: 'billing',
              label: t('Billing'),
              to: '/organizations/$orgId/settings/billing',
              icon: CreditCard,
              keywords: ['billing', 'payment', 'invoice', 'subscription']},
          ]
        : []),
      ...(features.compliance
        ? [
            {
              id: 'compliance',
              label: t('Compliance'),
              to: '/organizations/$orgId/settings/compliance',
              icon: ShieldCheck,
              keywords: [
                'compliance',
                'dpa',
                'baa',
                'hipaa',
                'gdpr',
                ...SOC2_SETTINGS_KEYWORDS,
              ]},
          ]
        : []),
      ...(features.oauthApps
        ? [
            {
              id: 'oauth-apps',
              label: t('OAuth apps'),
              to: '/organizations/$orgId/settings/oauth-apps',
              icon: KeyRound,
              keywords: ['oauth', 'sso', 'apps', 'login']},
          ]
        : []),
      ...(features.orgApiKeys
        ? [
            {
              id: 'api-keys',
              label: t('API keys'),
              to: '/organizations/$orgId/settings/api-keys',
              icon: Key,
              keywords: ['api', 'keys', 'credentials']},
          ]
        : []),
    ]

    const filtered = features.orgRoles
      ? allNavItems.filter((item) => {
          if (item.id === 'overview')
            return canAccessOrgSettingsOverview(access)
          if (item.id === 'members') return canAccessOrgSettingsMembers(access)
          if (item.id === 'billing') return canAccessOrgSettingsBilling(access)
          if (item.id === 'compliance')
            return canAccessOrgSettingsCompliance(access)
          if (item.id === 'oauth-apps')
            return canShowOrgOAuthAppsSettings(access, features)
          if (item.id === 'api-keys')
            return canShowOrgApiKeysSettings(access, features)
          return true
        })
      : allNavItems

    return filtered.map((item) => ({
      ...item,
      params: { orgId: orgId ?? '' }}))
  }, [features, access, orgId, t])

  const orgSettingsCardIndex = useMemo(() => {
    if (supportsMultiTenancy) return ORG_SETTINGS_CARD_INDEX
    return ORG_SETTINGS_CARD_INDEX.filter(
      (entry) => entry.title !== 'Delete organization',
    )
  }, [supportsMultiTenancy])

  // Top-level tab vs role: default index is "projects" in the URL, but hidden tabs (e.g. billing-only) must land on first allowed tab
  useEffect(() => {
    if (
      !features.orgRoles ||
      !orgId ||
      activeTab === null ||
      orgScopesLoading
    ) {
      return
    }

    const hasAnyTab =
      canSeeProjects(access, features) ||
      canShowOrgMarketplaceTab(access, features) ||
      canShowOrgDomainsTab(access, features) ||
      canShowOrgSettingsTab(access)

    if (!hasAnyTab) return

    if (!canAccessOrgOverviewTab(access, features, activeTab)) {
      const target = getFirstAllowedOrgOverviewPath(access, features)
      navigate({
        to: target as '/organizations/$orgId',
        params: { orgId },
        replace: true})
    }
  }, [
    features.orgRoles,
    features,
    orgId,
    activeTab,
    orgScopesLoading,
    access,
    navigate,
  ])

  const [commandCenterOpen, setCommandCenterOpen] = useState(false)
  const [commandCenterInitialSubPage, setCommandCenterInitialSubPage] =
    useState<string | null>(null)
  const [orgSwitcherOpen, setOrgSwitcherOpen] = useState(false)
  const [inviteDialogOpen, setInviteDialogOpen] = useState(false)
  const [updateRoleDialogOpen, setUpdateRoleDialogOpen] = useState(false)
  const [removeMemberDialogOpen, setRemoveMemberDialogOpen] = useState(false)
  const [selectedMember, setSelectedMember] = useState<TeamMember | null>(null)
  const [selectedRole, setSelectedRole] = useState<
    'owner' | 'developer' | 'editor' | 'analyst' | 'billing'
  >('developer')
  const [editAccessType, setEditAccessType] = useState<'all' | 'specific'>(
    'all',
  )
  const [editProjectAccess, setEditProjectAccess] = useState<
    ProjectAccessEntry[]
  >([])
  const [createOrgDialogOpen, setCreateOrgDialogOpen] = useState(false)

  const handleOpenCreateOrganization = useCallback(() => {
    if (!supportsMultiTenancy) return
    if (features.billing) {
      navigate({ to: '/upgrade' })
      return
    }
    setCreateOrgDialogOpen(true)
  }, [features.billing, navigate, supportsMultiTenancy])
  const [createProjectDialogOpen, setCreateProjectDialogOpen] = useState(false)
  const [deleteOrgDialogOpen, setDeleteOrgDialogOpen] = useState(false)
  const [deleteOrgConfirmation, setDeleteOrgConfirmation] = useState('')

  // Redirect from disabled settings sub-tabs (feature flags or role-based)
  useEffect(() => {
    if (settingsSubTab === 'billing' && !features.billing) {
      navigate({
        to: '/organizations/$orgId/settings',
        params: { orgId: orgId! },
        replace: true})
      return
    }
    if (
      (settingsSubTab === 'compliance' && !features.compliance) ||
      (settingsSubTab === 'oauth-apps' && !features.oauthApps) ||
      (settingsSubTab === 'api-keys' && !features.orgApiKeys)
    ) {
      navigate({
        to: '/organizations/$orgId/settings',
        params: { orgId: orgId! },
        replace: true})
      return
    }
    if (features.orgRoles && activeTab === 'settings') {
      const allowed =
        (settingsSubTab === 'members' && canAccessOrgSettingsMembers(access)) ||
        (settingsSubTab === 'billing' && canAccessOrgSettingsBilling(access)) ||
        (settingsSubTab === 'overview' &&
          canAccessOrgSettingsOverview(access)) ||
        (settingsSubTab === 'compliance' &&
          canAccessOrgSettingsCompliance(access)) ||
        (settingsSubTab === 'oauth-apps' &&
          canShowOrgOAuthAppsSettings(access, features)) ||
        (settingsSubTab === 'api-keys' &&
          canShowOrgApiKeysSettings(access, features))
      if (!allowed) {
        const firstAllowed = getFirstAllowedOrgSettingsPath(
          access,
          features,
          '/organizations/$orgId/settings',
        )
        navigate({
          to: firstAllowed as '/organizations/$orgId/settings',
          params: { orgId: orgId! },
          replace: true})
      }
    }
  }, [activeTab, settingsSubTab, features, access, orgId, navigate])

  const handleOrgNavigate = useCallback(
    (tab: string) => {
      const tabRoutes: Record<string, string> = {
        projects: '/organizations/$orgId',
        marketplace: '/organizations/$orgId/marketplace/',
        domains: '/organizations/$orgId/domains/',
        settings: '/organizations/$orgId/settings',
        'settings/members': '/organizations/$orgId/settings/members',
        'settings/billing': '/organizations/$orgId/settings/billing',
        'settings/compliance': '/organizations/$orgId/settings/compliance',
        'settings/oauth-apps': '/organizations/$orgId/settings/oauth-apps'}

      const route = tabRoutes[tab]
      if (route) {
        navigate({
          to: route as unknown,
          params: { orgId: orgId! } as unknown,
          replace: true})
      }
    },
    [navigate, orgId],
  )

  const openOrgCommandCenter = useCallback(() => {
    setCommandCenterInitialSubPage(null)
    setCommandCenterOpen(true)
  }, [])

  const openOrgShortcutsHelp = useCallback(() => {
    setCommandCenterInitialSubPage('shortcuts')
    setCommandCenterOpen(true)
  }, [])

  // Agent pane is a sibling of this page; register so console protocol can open CC.
  useEffect(() => {
    return registerCommandCenterOpener((page) => {
      setCommandCenterInitialSubPage(page)
      setCommandCenterOpen(true)
    })
  }, [])

  useGlobalCommandShortcuts({
    commandCenterOpen,
    onOpenCommandCenter: openOrgCommandCenter,
    onOpenShortcutsHelp: openOrgShortcutsHelp})

  useSequentialShortcuts(
    {
      'g p': () => handleOrgNavigate('projects'),
      ...(canShowOrgDomainsTab(access, features)
        ? { 'g d': () => handleOrgNavigate('domains') }
        : {}),
      ...(canShowOrgSettingsTab(access)
        ? { 'g s': () => handleOrgNavigate('settings') }
        : {}),
      ...(canAccessOrgSettingsMembers(access)
        ? { 'g m': () => handleOrgNavigate('settings/members') }
        : {}),
      ...(canShowOrgBillingNav(access, features)
        ? { 'g b': () => handleOrgNavigate('settings/billing') }
        : {}),
      ...(canShowOrgComplianceNav(access, features)
        ? { 'g c': () => handleOrgNavigate('settings/compliance') }
        : {}),
      ...(canCreateProject(access, features)
        ? {
            'c p': () => {
              handleOrgNavigate('projects')
              setCreateProjectDialogOpen(true)
            }}
        : {}),
      ...(supportsMultiTenancy
        ? { 'c t': () => handleOpenCreateOrganization() }
        : {}),
      ...(canInviteOrgMember(access, features)
        ? { 'c m': () => setInviteDialogOpen(true) }
        : {})},
    { enabled: !commandCenterOpen },
  )

  // Projects list: prefer URL search so page size change and page are shareable
  const projectsPageFromSearch =
    typeof search === 'object' && search != null && 'projectsPage' in search
      ? typeof (search as { projectsPage?: number }).projectsPage === 'number'
        ? (search as { projectsPage: number }).projectsPage
        : Number((search as { projectsPage?: unknown }).projectsPage)
      : undefined
  const projectsLimitFromSearch =
    typeof search === 'object' && search != null && 'projectsLimit' in search
      ? typeof (search as { projectsLimit?: number }).projectsLimit === 'number'
        ? (search as { projectsLimit: number }).projectsLimit
        : Number((search as { projectsLimit?: unknown }).projectsLimit)
      : undefined
  const urlProjectsPage =
    Number.isInteger(projectsPageFromSearch) &&
    (projectsPageFromSearch ?? 0) >= 1
      ? projectsPageFromSearch!
      : 1
  const urlProjectsLimit =
    Number.isInteger(projectsLimitFromSearch) &&
    (projectsLimitFromSearch ?? 0) >= 1
      ? projectsLimitFromSearch!
      : GRID_DEFAULT_PAGE_SIZE

  // Pagination state (1-indexed for projects, like storage view)
  const [requestedPage, setRequestedPage] = useState(urlProjectsPage)
  const [displayedPage, setDisplayedPage] = useState(urlProjectsPage)
  // Alias for projects current page (used in pagination UI; matches memberships pattern)
  const activeProjectsPage = displayedPage
  const [requestedMembershipsPage, setRequestedMembershipsPage] = useState(1)
  const [displayedMembershipsPage, setDisplayedMembershipsPage] = useState(1)
  const [membershipsPageSize, setMembershipsPageSize] = useState(
    GRID_DEFAULT_PAGE_SIZE,
  )
  const [membershipsSearchQuery, setMembershipsSearchQuery] = useState('')
  const [settingsNavSearch, setSettingsNavSearch] = useState('')

  // Same pattern as projects list: cache filled by org layout loader; keepPreviousData on org switch
  const { data: organizationsData, isLoading: organizationsLoading } = useQuery(
    {
      ...organizationsQueryOptions(),
      placeholderData: keepPreviousData},
  )

  const {
    data: organizationDetail,
    isLoading: organizationDetailLoading,
    isFetching: organizationDetailFetching,
    isFetched: organizationDetailFetched,
    isError: organizationDetailError,
  } = useQuery({
    ...organizationQueryOptions(orgId),
    placeholderData: keepPreviousData,
  })

  // Get organizations list and map to our Organization type
  // Note: The API returns "teams" but they are actually organizations
  const organizations = useMemo(() => {
    if (!organizationsData?.teams) return []

    return organizationsData.teams.map(
      (org: {
        $id: string
        name: string
        total?: number
        billingPlan?: string
        billingPlanDowngrade?: unknown
        tier?: string
        prefs?: Record<string, unknown>
        status?: string
      }) => {
        const planName = getPlanNameFromTier(
          org.billingPlan ?? (org.prefs as { tier?: string })?.tier ?? 'free',
        )
        const plan = planName as CanonicalPlanId

        return {
          $id: org.$id,
          name: org.name,
          slug: org.name.toLowerCase().replace(/\s+/g, '-'),
          avatar: undefined, // Organizations from SDK don't have avatar
          plan,
          members: org.total || 0,
          status: org.status,
          billingPlanDowngrade: org.billingPlanDowngrade}
      },
    )
  }, [organizationsData])

  // Get selected organization from URL param (orgId)
  const selectedOrg = useMemo(() => {
    if (!orgId) return null
    if (organizations.length > 0) {
      const fromList = organizations.find(
        (org: Organization) => org.$id === orgId,
      )
      if (fromList) return fromList
    }
    if (organizationDetail && organizationDetail.$id === orgId) {
      const planName = getPlanNameFromTier(
        organizationDetail.billingPlan ??
          (organizationDetail.prefs as { tier?: string })?.tier ??
          'free',
      )
      return {
        $id: organizationDetail.$id,
        name: organizationDetail.name,
        slug: organizationDetail.name.toLowerCase().replace(/\s+/g, '-'),
        avatar: undefined,
        plan: planName as CanonicalPlanId,
        members: organizationDetail.total || 0,
        status: organizationDetail.status,
        billingPlanDowngrade: organizationDetail.billingPlanDowngrade} satisfies Organization
    }
    return null
  }, [orgId, organizations, organizationDetail])

  const orgBillingReadonlyForFailedInvoice =
    showFailedInvoiceOrgAlert &&
    isOrganizationBillingReadonlyStatus(selectedOrg?.status)

  const showBudgetLimitAlert =
    features.billing && isBudgetLimitReached(organizationDetail)
  const showPlanUsageLimitAlert =
    features.billing &&
    !showBudgetLimitAlert &&
    isPlanUsageLimitReached(organizationDetail)
  const showProjectsLockedAlert =
    showBudgetLimitAlert || showPlanUsageLimitAlert

  const [orgName, setOrgName] = useState('')

  // Update orgName when selectedOrg changes
  useEffect(() => {
    if (selectedOrg) {
      setOrgName(selectedOrg.name)
      setDeleteOrgConfirmation('')
      // Reset pagination when org changes
      setRequestedPage(1)
      setDisplayedPage(1)
      setRequestedMembershipsPage(1)
      setDisplayedMembershipsPage(1)
      setMembershipsSearchQuery('')
    }
  }, [selectedOrg])

  // Check for createOrg search param and open dialog (self-hosted) or upgrade wizard (cloud)
  useEffect(() => {
    const shouldCreateOrg =
      typeof search === 'object' &&
      'createOrg' in search &&
      search.createOrg === true
    if (!shouldCreateOrg || organizationsLoading) {
      return
    }

    if (!features.multiTenancy) {
      const url = new URL(window.location.href)
      url.searchParams.delete('createOrg')
      window.history.replaceState(
        window.history.state,
        '',
        `${url.pathname}${url.search}${url.hash}`,
      )
      return
    }

    if (features.billing) {
      navigate({ to: '/upgrade', replace: true })
      return
    }

    if (!createOrgDialogOpen) {
      setCreateOrgDialogOpen(true)
      // Remove the search param from URL
      navigate({
        to: location.pathname,
        search: (prev: Record<string, unknown>) => {
          if (!prev || typeof prev !== 'object') return {}
          const newSearch = { ...(prev as Record<string, unknown>) }
          delete newSearch.createOrg
          // Return empty object if no other params, otherwise return the cleaned object
          return Object.keys(newSearch).length === 0 ? {} : newSearch
        },
        replace: true})
    }
  }, [
    search,
    organizationsLoading,
    createOrgDialogOpen,
    navigate,
    location.pathname,
    features.billing,
    features.multiTenancy,
  ])

  // Check for create=project search param (e.g. from header plus button) and open Create Project dialog
  useEffect(() => {
    const shouldCreateProject =
      typeof search === 'object' &&
      'create' in search &&
      (search as { create?: string }).create === 'project'
    if (
      shouldCreateProject &&
      !organizationsLoading &&
      !createProjectDialogOpen
    ) {
      setCreateProjectDialogOpen(true)
      navigate({
        to: location.pathname,
        search: (prev: Record<string, unknown>) => {
          if (!prev || typeof prev !== 'object') return {}
          const newSearch = { ...(prev as Record<string, unknown>) }
          delete newSearch.create
          return Object.keys(newSearch).length === 0 ? {} : newSearch
        },
        replace: true})
    }
  }, [
    search,
    organizationsLoading,
    createProjectDialogOpen,
    navigate,
    location.pathname,
  ])

  // Handle missing organization: redirect to next org or open creation wizard
  useEffect(() => {
    // Only act if organizations have finished loading and we have an orgId in the URL
    if (organizationsLoading || !orgId) return

    // If the selected org is not found
    if (!selectedOrg) {
      // After create/upgrade navigation the list can lag behind the URL. Wait for
      // the org detail query to settle before treating the org as missing, or we
      // briefly bounce back to /upgrade (create form flash).
      const detailMatchesCurrentOrg = organizationDetail?.$id === orgId
      const waitingForDetail =
        !detailMatchesCurrentOrg &&
        (organizationDetailLoading ||
          organizationDetailFetching ||
          (!organizationDetailFetched && !organizationDetailError))
      if (waitingForDetail) return

      // If there are other organizations, redirect to the first one
      if (organizations.length > 0) {
        navigate({
          to: '/organizations/$orgId',
          params: { orgId: organizations[0].$id },
          replace: true})
      } else if (features.billing) {
        navigate({ to: '/upgrade', replace: true })
      } else if (features.multiTenancy && !createOrgDialogOpen) {
        // No organizations at all, open creation dialog (only if not already open)
        setCreateOrgDialogOpen(true)
      } else {
        navigate({ to: '/', replace: true })
      }
    }
  }, [
    selectedOrg,
    organizations,
    organizationsLoading,
    organizationDetail,
    organizationDetailLoading,
    organizationDetailFetching,
    organizationDetailFetched,
    organizationDetailError,
    orgId,
    navigate,
    createOrgDialogOpen,
    features.billing,
    features.multiTenancy,
  ])

  // Mutation to update user prefs when switching organizations
  const updateOrgPrefsMutation = useMutation({
    mutationFn: async (orgId: string) => {
      const accountPrefs = (
        account as { prefs?: Record<string, unknown> } | null | undefined
      )?.prefs
      return await updateAccountPrefs({
        ...accountPrefs,
        organization: orgId,
      })
    },
    onMutate: (orgId) => {
      queryClient.setQueriesData<{ prefs?: Record<string, unknown> }>(
        { queryKey: ['account', 'console'] },
        (current) =>
          current
            ? {
                ...current,
                prefs: { ...current.prefs, organization: orgId }}
            : current,
      )
      syncConsoleAccountAfterMutation(queryClient)
    },
    onSuccess: (updatedAccount) => {
      syncConsoleAccountAfterMutation(queryClient, {
        apiResult: updatedAccount})
    }})

  // Mutation to update organization name
  const updateOrgNameMutation = useMutation({
    mutationFn: async ({ orgId, name }: { orgId: string; name: string }) => {
      await sdk.forConsole.teams.updateName({ teamId: orgId, name })
    },
    onSuccess: () => {
      // Invalidate organizations query to refetch with updated name
      queryClient.invalidateQueries({ queryKey: ['organizations', 'console'] })
      toast.success(t('Organization name updated successfully'))
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to update organization name'))
    }})

  // Mutation to delete organization
  const deleteOrgMutation = useMutation({
    mutationFn: async (orgIdToDelete: string) => {
      await deleteOrganization(orgIdToDelete)
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['organizations', 'console'] })
      toast.success(t('Organization deleted successfully'))
      setDeleteOrgDialogOpen(false)
      setDeleteOrgConfirmation('')

      const remainingOrgs = organizations.filter(
        (org: Organization) => org.$id !== orgId,
      )
      if (remainingOrgs.length > 0) {
        navigate({
          to: '/organizations/$orgId',
          params: { orgId: remainingOrgs[0].$id },
          replace: true})
      } else {
        navigate({ to: '/', replace: true })
      }
    },
    onError: (error: Error) => {
      toast.error(error.message || t('Failed to delete organization'))
    }})

  // Mutation to create organization
  const createOrgMutation = useCreateOrganization()

  // Get team ID from URL param (orgId) - no need to wait for selectedOrg state
  // In Appwrite, organizations ARE teams, so we use the organization ID directly as the team ID
  const orgTeamId = orgId || null


  // Pinned projects: stored in team prefs, excluded from main list
  const { data: consoleTeam } = useConsoleTeam(orgTeamId)
  const teamPrefs = (consoleTeam as { prefs?: Record<string, unknown> } | null)
    ?.prefs
  const allPinnedIds = useMemo(
    () => parsePinnedProjectIds(teamPrefs),
    [teamPrefs],
  )
  const { data: orgProjectScope } = useQuery(
    organizationProjectScopeQueryOptions(orgTeamId),
  )
  const restrictToProjectIds = orgProjectScope ?? null

  // Pins live in org-level team prefs and are shared by every member, so a
  // project-scoped member would otherwise see pinned cards for projects they
  // cannot open. Filtering here also keeps them out of the exclude list and
  // the project count.
  const pinnedIds = useMemo(() => {
    if (!restrictToProjectIds) return allPinnedIds
    const allowed = new Set(restrictToProjectIds)
    return allPinnedIds.filter((id) => allowed.has(id))
  }, [allPinnedIds, restrictToProjectIds])

  const updateTeamPrefsMutation = useUpdateConsoleTeamPrefs(orgTeamId)

  /** While searching, list API must include pinned rows if they match; pinned section is hidden in the UI. */
  const projectsSearchActive = Boolean(searchQuery.trim())
  const listExcludePinnedIds = projectsSearchActive ? undefined : pinnedIds

  const { data: pinnedProjectsData } = useQuery({
    ...pinnedProjectsQueryOptions(orgTeamId, pinnedIds),
    placeholderData: keepPreviousData})

  // Sync page state from URL when it changes (e.g. browser back or initial load)
  useEffect(() => {
    setRequestedPage((p) => (p === urlProjectsPage ? p : urlProjectsPage))
    setDisplayedPage((p) => (p === urlProjectsPage ? p : urlProjectsPage))
  }, [urlProjectsPage])

  // Fetch data for the requested page (triggers load when user changes page); exclude pinned when not searching
  const {
    data: requestedProjectsData,
    isLoading: activeProjectsLoading,
    isFetching: activeProjectsFetching,
    error: activeProjectsError} = useQuery({
    ...activeProjectsQueryOptions(
      orgTeamId,
      requestedPage - 1,
      urlProjectsLimit,
      searchQuery,
      listExcludePinnedIds,
      restrictToProjectIds,
    ),
    placeholderData: keepPreviousData})

  // Fetch data for the displayed page (what we show - stays until new page is ready); exclude pinned when not searching
  const { data: activeProjectsData, isLoading: displayedProjectsLoading } =
    useQuery({
      ...activeProjectsQueryOptions(
        orgTeamId,
        displayedPage - 1,
        urlProjectsLimit,
        searchQuery,
        listExcludePinnedIds,
        restrictToProjectIds,
      ),
      placeholderData: keepPreviousData})

  // Only show full loading when we have no data to display (initial load)
  const displayedProjects = activeProjectsData?.projects ?? []
  const showProjectsLoading =
    displayedProjectsLoading && displayedProjects.length === 0

  // Update displayed page only when requested page data is ready (no flash)
  useEffect(() => {
    if (
      requestedPage !== displayedPage &&
      !activeProjectsFetching &&
      requestedProjectsData != null
    ) {
      setDisplayedPage(requestedPage)
    }
  }, [
    requestedPage,
    displayedPage,
    activeProjectsFetching,
    requestedProjectsData,
  ])

  // Reset pagination when search query changes
  useEffect(() => {
    setRequestedPage(1)
    setDisplayedPage(1)
  }, [searchQuery])

  useEffect(() => {
    setPinnedDragOverIndex(null)
    setPinnedDraggingIndex(null)
  }, [searchQuery])

  // Track when projects are actually rendered in the DOM (for controlling full-screen loader)
  const [, setProjectsRendered] = useState(false)
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

  // Pinned projects in display shape (order preserved from pinnedIds)
  const pinnedProjects = useMemo(() => {
    if (!pinnedProjectsData?.projects?.length) return []
    const raw = pinnedProjectsData.projects as Models.Project[]
    const byId = new Map(raw.map((p) => [p.$id, p]))
    return pinnedIds
      .map((id) => byId.get(id))
      .filter((p): p is Models.Project => p != null)
      .map(mapProjectToListItem)
  }, [pinnedProjectsData, pinnedIds])

  const canPinProjectsResult = canPinProjects(access, features)
  const canReorderPinned =
    canPinProjectsResult && !searchQuery.trim() && pinnedProjects.length > 1
  const canManageProjects = canCreateProject(access, features)
  const showProjectSettingsTab = canShowProjectSettings(access, features)

  const handlePinProject = (projectId: string) => {
    if (!canPinProjectsResult) return
    updateTeamPrefsMutation.mutate(
      (freshPrefs) => {
        const currentPinnedIds = parsePinnedProjectIds(freshPrefs)
        if (
          currentPinnedIds.length >= MAX_PINNED_PROJECTS &&
          !currentPinnedIds.includes(projectId)
        ) {
          throw new Error(
            `You can pin up to ${MAX_PINNED_PROJECTS} projects`,
          )
        }
        const next = currentPinnedIds.includes(projectId)
          ? currentPinnedIds.filter((id) => id !== projectId)
          : [...currentPinnedIds, projectId].slice(0, MAX_PINNED_PROJECTS)
        return buildPinnedProjectIdsPrefs(next)
      },
      {
        onSuccess: (prefs) => {
          queryClient.invalidateQueries({ queryKey: ['projects'] })
          const nextIds = parsePinnedProjectIds(
            prefs as Record<string, unknown> | undefined,
          )
          toast.success(
            nextIds.includes(projectId)
              ? t('Project pinned')
              : t('Project unpinned'),
          )
        },
        onError: (error) => {
          const message =
            error instanceof Error ? error.message : undefined
          toast.error(
            message?.startsWith('You can pin up to')
              ? message
              : t('Failed to update pinned projects'),
          )
        },
      },
    )
  }

  const handlePinnedDragStart = useCallback(
    (e: DragEvent, index: number, projectName: string) => {
      if (updateTeamPrefsMutation.isPending) {
        e.preventDefault()
        return
      }
      e.dataTransfer.setData('application/json', JSON.stringify({ index }))
      e.dataTransfer.effectAllowed = 'move'
      setPinnedDraggingIndex(index)

      const preview = document.createElement('div')
      // Keep off-screen so the preview never flashes at the viewport origin (0,0).
      preview.style.cssText =
        'position:fixed;left:-9999px;top:0;z-index:99999;pointer-events:none;'
      preview.className =
        'flex min-h-[40px] min-w-[200px] max-w-[min(280px,calc(100vw-2rem))] items-center rounded-lg border border-border/80 bg-card px-3 py-2 shadow-md'
      const label = document.createElement('span')
      label.className =
        'block min-w-0 max-w-[240px] truncate text-[13px] font-medium text-foreground'
      label.textContent = projectName
      preview.appendChild(label)
      document.body.appendChild(preview)
      pinnedDragPreviewRef.current = preview
      const rect = preview.getBoundingClientRect()
      e.dataTransfer.setDragImage(
        preview,
        Math.min(rect.width / 2, 80),
        rect.height / 2,
      )
    },
    [updateTeamPrefsMutation.isPending],
  )

  const handlePinnedDragEnd = useCallback(() => {
    setPinnedDraggingIndex(null)
    setPinnedDragOverIndex(null)
    pinnedDragPreviewRef.current?.remove()
    pinnedDragPreviewRef.current = null
  }, [])

  const handlePinnedDragOver = (e: DragEvent, index: number) => {
    if (!canReorderPinned || updateTeamPrefsMutation.isPending) return
    e.preventDefault()
    e.dataTransfer.dropEffect = 'move'
    setPinnedDragOverIndex(index)
  }

  const handlePinnedCardDragLeave = useCallback(
    (e: DragEvent<HTMLDivElement>) => {
      const next = e.relatedTarget as Node | null
      if (next && e.currentTarget.contains(next)) return
      setPinnedDragOverIndex(null)
    },
    [],
  )

  const handlePinnedDrop = (e: DragEvent, dropIndex: number) => {
    e.preventDefault()
    setPinnedDragOverIndex(null)
    setPinnedDraggingIndex(null)
    if (!canReorderPinned || !orgTeamId || updateTeamPrefsMutation.isPending)
      return
    const raw = e.dataTransfer.getData('application/json')
    if (!raw) return
    try {
      const { index: dragIndex } = JSON.parse(raw) as { index: number }
      if (dragIndex === dropIndex) return
      updateTeamPrefsMutation.mutate(
        (freshPrefs) => {
          const currentPinnedIds = parsePinnedProjectIds(freshPrefs)
          const next = reorderPinnedProjectIds(
            currentPinnedIds,
            dragIndex,
            dropIndex,
          )
          return buildPinnedProjectIdsPrefs(next)
        },
        {
          onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['projects'] })
          },
          onError: () => toast.error(t('Failed to reorder pinned projects')),
        },
      )
    } catch {
      // ignore invalid payload
    }
  }

  const handleProjectDeleted = async (projectId: string) => {
    if (!pinnedIds.includes(projectId)) return
    try {
      await updateTeamPrefsMutation.mutateAsync((freshPrefs) => {
        const currentPinnedIds = parsePinnedProjectIds(freshPrefs)
        if (!currentPinnedIds.includes(projectId)) {
          return {}
        }
        return buildPinnedProjectIdsPrefs(
          currentPinnedIds.filter((id) => id !== projectId),
        )
      })
    } catch {
      // Keep project deletion successful even if pin cleanup fails.
    }
  }

  // Get active projects from API (team-scoped; pinned ids omitted from the list query only when not searching)
  const activeProjects = useMemo(() => {
    if (!activeProjectsData?.projects) return []

    return activeProjectsData.projects.map((project: Models.Project) =>
      mapProjectToListItem(project),
    )
  }, [activeProjectsData])

  // Group active projects by team (non-pinned only)
  const projectsByTeam = useMemo(() => {
    if (!selectedOrg || activeProjects.length === 0) return []

    return [
      {
        team: {
          $id: selectedOrg.$id,
          name: selectedOrg.name,
          color: 'from-blue-400 to-violet-500',
          members: selectedOrg.members,
          orgId: selectedOrg.$id},
        projects: activeProjects},
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

  // Total count of all projects (without search) - for plan-limit checks.
  // The active-projects listing excludes pinned ids, so when no search is
  // active the unconditional total is just `listing total + pinned count`.
  // We cache the last value seen while the search box was empty so the
  // limit check stays accurate when the user starts typing a query (the
  // listing's total is filtered by the search and would otherwise drift).
  const lastUnfilteredTotalRef = useRef(0)
  if (!searchQuery && activeProjectsData?.total != null) {
    lastUnfilteredTotalRef.current = activeProjectsData.total + pinnedIds.length
  }
  const totalProjectsCount = !searchQuery
    ? (activeProjectsData?.total ?? 0) + pinnedIds.length
    : lastUnfilteredTotalRef.current

  // Fetch organization plan to check if additional members are supported
  const { plan: organizationPlan } = useOrganizationPlan(orgId)

  // Check if the plan supports additional members
  // Only disable if seats addon is explicitly disabled with supported = false
  const supportsAdditionalMembers = useMemo(() => {
    return organizationPlan?.addons?.seats?.supported !== false
  }, [organizationPlan])

  // Per-project access is a paid capability; without it members are org-wide only.
  const supportsProjectRoles = Boolean(
    features.orgRoles && organizationPlan?.supportsProjectSpecificRoles,
  )

  // Name lookup for project-scoped role badges. A member may hold a role on a
  // project outside the current page or search, so callers fall back to the id.
  const orgProjectNameById = useMemo(() => {
    const map = new Map<string, string>()
    for (const project of activeProjects) map.set(project.$id, project.name)
    return map
  }, [activeProjects])

  const canInviteMembers = canInviteOrgMember(access, features)
  const inviteDisabled =
    !supportsAdditionalMembers || !canInviteMembers || !orgId
  const inviteDisabledTooltip = !orgId
    ? t('Select an organization to invite members.')
    : !canInviteMembers
      ? t("You don't have permission to invite members.")
      : !supportsAdditionalMembers
        ? t('Member limit reached for your plan.')
        : undefined

  // Agent console protocol: invite=member opens the invite dialog.
  useEffect(() => {
    const shouldInvite =
      typeof search === 'object' &&
      'invite' in search &&
      (search as { invite?: string }).invite === 'member'
    if (
      !shouldInvite ||
      organizationsLoading ||
      inviteDialogOpen ||
      inviteDisabled
    ) {
      return
    }
    setInviteDialogOpen(true)
    navigate({
      to: location.pathname,
      search: (prev: Record<string, unknown>) => {
        if (!prev || typeof prev !== 'object') return {}
        const newSearch = { ...(prev as Record<string, unknown>) }
        delete newSearch.invite
        return Object.keys(newSearch).length === 0 ? {} : newSearch
      },
      replace: true,
    })
  }, [
    inviteDialogOpen,
    inviteDisabled,
    location.pathname,
    navigate,
    organizationsLoading,
    search,
  ])

  // Calculate member limit
  // Check both addons.seats and plan.members field
  const memberLimit = useMemo(() => {
    if (!organizationPlan) return null

    // First check for seats addon
    const seatsLimit = organizationPlan?.addons?.seats?.limit
    const seatsPlanIncluded = organizationPlan?.addons?.seats?.planIncluded

    // Then check plan.members field (base plan members)
    const planMembers = organizationPlan?.members

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

  // Check if the plan supports success team (custom plans)
  const supportsSuccessTeam = useMemo(() => {
    if (!organizationPlan) return false
    const planName = organizationPlan.name?.toLowerCase() || ''
    return planName === 'custom' || selectedOrg?.plan === 'custom'
  }, [organizationPlan, selectedOrg])

  // Memberships table + header avatars: mirror projects list (two queries, keepPreviousData, loader fills cache)
  const {
    data: requestedMembershipsRaw,
    isFetching: membershipsRequestedFetching} = useQuery({
    ...organizationMembershipsQueryOptions(
      orgId,
      requestedMembershipsPage - 1,
      membershipsPageSize,
      membershipsSearchQuery,
    ),
    placeholderData: keepPreviousData})

  const {
    data: displayedMembershipsRaw,
    isLoading: displayedMembershipsLoading,
    error: membershipsError} = useQuery({
    ...organizationMembershipsQueryOptions(
      orgId,
      displayedMembershipsPage - 1,
      membershipsPageSize,
      membershipsSearchQuery,
    ),
    placeholderData: keepPreviousData})

  const memberships = useMemo(
    () =>
      mapOrganizationMembershipsToTeamMembers(displayedMembershipsRaw, orgId),
    [displayedMembershipsRaw, orgId],
  )
  // Projects named by any member's project-specific roles on this page.
  // Resolved by id rather than read off the org's project list, which is
  // paginated and searched and so will not contain most of them.
  const memberProjectIds = useMemo(() => {
    if (!supportsProjectRoles) return []
    const ids = new Set<string>()
    for (const member of memberships) {
      for (const id of projectIdsFromRoles(member.roles)) ids.add(id)
    }
    return Array.from(ids)
  }, [memberships, supportsProjectRoles])

  const { data: memberProjectsData } = useQuery(
    projectsByIdsQueryOptions(orgTeamId, memberProjectIds),
  )

  const memberProjectNameById = useMemo(() => {
    const map = new Map<string, string>(orgProjectNameById)
    const resolved = (memberProjectsData?.projects ?? []) as Array<{
      $id: string
      name: string
    }>
    for (const project of resolved) map.set(project.$id, project.name)
    return map
  }, [memberProjectsData, orgProjectNameById])

  const membershipsTotal = displayedMembershipsRaw?.total ?? 0

  const membershipsLoading =
    displayedMembershipsLoading && displayedMembershipsRaw === undefined

  // Update displayed memberships page only when requested page data is ready (no flash)
  useEffect(() => {
    if (
      requestedMembershipsPage !== displayedMembershipsPage &&
      !membershipsRequestedFetching &&
      requestedMembershipsRaw != null
    ) {
      setDisplayedMembershipsPage(requestedMembershipsPage)
    }
  }, [
    membershipsRequestedFetching,
    requestedMembershipsPage,
    displayedMembershipsPage,
    requestedMembershipsRaw,
  ])

  // Resend invitation mutation
  const resendInviteMutation = useResendMembershipInvite(orgId)

  // Update membership role mutation
  const updateRoleMutation = useUpdateMembershipRole(orgId)

  // Remove organization member mutation
  const removeMemberMutation = useRemoveTeamMember(orgId)

  // Reset memberships pagination when search query changes
  useEffect(() => {
    setRequestedMembershipsPage(1)
    setDisplayedMembershipsPage(1)
  }, [membershipsSearchQuery])

  // Org tabs (billing is under settings). When profile supports roles, gate by access.
  const orgTabs = useMemo(() => {
    if (!selectedOrg) return []

    const tabs: { id: string; label: string; to: string }[] = []
    if (canSeeProjects(access, features)) {
      tabs.push({
        id: 'projects',
        label: 'Projects',
        to: '/organizations/$orgId'})
    }
    if (canShowOrgMarketplaceTab(access, features)) {
      tabs.push({
        id: 'marketplace',
        label: 'Marketplace',
        to: '/organizations/$orgId/marketplace/'})
    }
    if (canShowOrgDomainsTab(access, features)) {
      tabs.push({
        id: 'domains',
        label: 'Domains',
        to: '/organizations/$orgId/domains/'})
    }
    if (canShowOrgSettingsTab(access)) {
      tabs.push({
        id: 'settings',
        label: 'Settings',
        to: '/organizations/$orgId/settings'})
    }
    return tabs
  }, [selectedOrg, access, features])

  const filteredProjectsByTeam = projectsByTeam
    .map(({ team, projects }) => ({
      team,
      projects: projectsSearchActive
        ? projects
        : projects.filter((p) =>
            p.name?.toLowerCase().includes(searchQuery.toLowerCase()),
          )}))
    .filter(({ projects }) => projects.length > 0)

  // Keep current results until new data is ready: when search is fetching or pagination in flight
  const displayedProjectsByTeam =
    (activeProjectsFetching && searchQuery.trim()) ||
    requestedPage !== displayedPage
      ? projectsByTeam
      : filteredProjectsByTeam

  const showProjectUsageCharts = features.usageStats
  // Budget-locked projects cannot load platform/usage APIs (402). Skip those
  // fetches and show N/A on the cards instead.
  const skipProjectCardExtras = showProjectsLockedAlert

  const visibleProjectIds = useMemo(() => {
    if (!showProjectUsageCharts || skipProjectCardExtras) return []
    return [
      ...new Set([
        ...pinnedProjects.map((project) => project.$id),
        ...displayedProjectsByTeam.flatMap(({ projects }) =>
          projects.map((project) => project.$id),
        ),
      ]),
    ]
  }, [
    showProjectUsageCharts,
    skipProjectCardExtras,
    pinnedProjects,
    displayedProjectsByTeam,
  ])

  const fetchedProjectRequestsUsageById = useProjectListRequestsUsage(
    visibleProjectIds,
    showProjectUsageCharts && !skipProjectCardExtras,
  )

  const projectListPlatformIds = useMemo(() => {
    if (skipProjectCardExtras) return []
    return [
      ...new Set([
        ...pinnedProjects.map((project) => project.$id),
        ...displayedProjectsByTeam.flatMap(({ projects }) =>
          projects.map((project) => project.$id),
        ),
      ]),
    ]
  }, [skipProjectCardExtras, pinnedProjects, displayedProjectsByTeam])

  const fetchedProjectPlatformsById = useProjectListPlatforms(
    projectListPlatformIds,
    projectListPlatformIds.length > 0,
  )

  const lockedProjectCardIds = useMemo(() => {
    if (!skipProjectCardExtras) return [] as string[]
    return [
      ...new Set([
        ...pinnedProjects.map((project) => project.$id),
        ...displayedProjectsByTeam.flatMap(({ projects }) =>
          projects.map((project) => project.$id),
        ),
      ]),
    ]
  }, [skipProjectCardExtras, pinnedProjects, displayedProjectsByTeam])

  const projectRequestsUsageById = useMemo(() => {
    if (!skipProjectCardExtras) return fetchedProjectRequestsUsageById
    const map = new Map(fetchedProjectRequestsUsageById)
    for (const projectId of lockedProjectCardIds) {
      map.set(projectId, {
        isLoading: false,
        isError: false,
        data: undefined,
        unavailable: true,
      })
    }
    return map
  }, [
    skipProjectCardExtras,
    fetchedProjectRequestsUsageById,
    lockedProjectCardIds,
  ])

  const projectPlatformsById = useMemo(() => {
    if (!skipProjectCardExtras) return fetchedProjectPlatformsById
    const map = new Map(fetchedProjectPlatformsById)
    for (const projectId of lockedProjectCardIds) {
      map.set(projectId, {
        isLoading: false,
        isError: false,
        platforms: [],
        unavailable: true,
      })
    }
    return map
  }, [
    skipProjectCardExtras,
    fetchedProjectPlatformsById,
    lockedProjectCardIds,
  ])

  const prefetchOrganizationSwitchData = useCallback(
    async (nextOrgId: string) => {
      const [nextTeam] = await Promise.all([
        queryClient.ensureQueryData(consoleTeamQueryOptions(nextOrgId)),
        queryClient.ensureQueryData(organizationsQueryOptions()),
        queryClient.ensureQueryData(organizationQueryOptions(nextOrgId)),
        ...(features.billing
          ? [
              queryClient.ensureQueryData(
                organizationPlanQueryOptions(nextOrgId),
              ),
              prefetchOrganizationInvoiceDataIfAllowed(
                queryClient,
                nextOrgId,
              ),
            ]
          : []),
        ...(features.orgRoles
          ? [
              queryClient
                .ensureQueryData(organizationScopesQueryOptions(nextOrgId))
                .catch(() => {}),
            ]
          : []),
      ])
      const nextTeamPrefs = (
        nextTeam as { prefs?: Record<string, unknown> } | null
      )?.prefs
      const nextPinnedIds = parsePinnedProjectIds(nextTeamPrefs)
      const projectPages = Array.from(
        new Set([0, Math.max(0, requestedPage - 1)]),
      )
      // Resolved first so the prefetch lands on the key the overview reads.
      const nextProjectScope = await queryClient
        .ensureQueryData(organizationProjectScopeQueryOptions(nextOrgId))
        .catch(() => null)

      await Promise.all([
        queryClient.ensureQueryData(
          organizationMembershipsQueryOptions(
            nextOrgId,
            0,
            GRID_DEFAULT_PAGE_SIZE,
            '',
          ),
        ),
        ...projectPages.map((page) =>
          queryClient.ensureQueryData(
            activeProjectsQueryOptions(
              nextOrgId,
              page,
              urlProjectsLimit,
              searchQuery,
              searchQuery.trim() ? undefined : nextPinnedIds,
              nextProjectScope,
            ),
          ),
        ),
        ...(nextPinnedIds.length > 0
          ? [
              queryClient.ensureQueryData(
                pinnedProjectsQueryOptions(nextOrgId, nextPinnedIds),
              ),
            ]
          : []),
        ...(activeTab === 'domains'
          ? [
              queryClient.ensureQueryData(
                organizationDomainsQueryOptions(
                  nextOrgId,
                  0,
                  GRID_DEFAULT_PAGE_SIZE,
                  undefined,
                  undefined,
                  DOMAINS_DEFAULT_SORT_BY,
                  DOMAINS_DEFAULT_SORT_ORDER,
                ),
              ),
              queryClient.ensureQueryData(
                organizationDomainsQueryOptions(
                  nextOrgId,
                  0,
                  1,
                  undefined,
                  undefined,
                  DOMAINS_DEFAULT_SORT_BY,
                  DOMAINS_DEFAULT_SORT_ORDER,
                ),
              ),
            ]
          : []),
      ])
    },
    [
      activeTab,
      features.billing,
      features.orgRoles,
      queryClient,
      requestedPage,
      searchQuery,
      urlProjectsLimit,
    ],
  )

  const handleSelectOrg = async (org: Organization) => {
    setOrgSwitcherOpen(false)

    // Navigate to the new organization route, preserving the current tab
    const tabRoutes: Record<string, string> = {
      projects: '/organizations/$orgId',
      marketplace: '/organizations/$orgId/marketplace/',
      domains: '/organizations/$orgId/domains/',
      settings: '/organizations/$orgId/settings'}

    // Preserve settings sub-tab when switching orgs
    const settingsSubRoute =
      activeTab === 'settings' &&
      ['members', 'billing', 'compliance', 'oauth-apps', 'api-keys'].includes(
        settingsSubTab,
      )
        ? `/organizations/$orgId/settings/${settingsSubTab}`
        : null
    const route =
      settingsSubRoute ||
      (activeTab && tabRoutes[activeTab as keyof typeof tabRoutes]) ||
      '/organizations/$orgId'

    try {
      await prefetchOrganizationSwitchData(org.$id)
    } catch (error) {
      console.warn('Failed to prefetch organization switch data:', error)
    }

    navigate({
      to: route as unknown,
      params: { orgId: org.$id } as unknown,
      replace: true})

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
          onCommandCenterOpen: openOrgCommandCenter,
          onCreateOrganization: supportsMultiTenancy
            ? handleOpenCreateOrganization
            : undefined}}
        headerBanner={
          <OrganizationBillingHeaderBanners organizationId={orgId} />
        }
        showFooter
        containerClassName="org-layout-container"
      >
        <InitOrgPromoBanner />

        {/* Org Header with Switcher */}
        <div>
          {/* Title Row: fixed h-16 so padding + toolbar never grows (h1 margins, badges, etc.) */}
          <div className="mx-auto flex h-16 min-h-16 w-full max-w-7xl shrink-0 items-center justify-between gap-3 px-4 sm:px-6">
            {/* Left: Org Switcher - h-8 control; h1 uses m-0 so UA margins do not shift layout */}
            <div className="flex h-8 min-h-8 max-h-8 min-w-0 flex-1 items-center gap-2">
              {selectedOrg ? (
                supportsMultiTenancy ? (
                  <Popover
                    open={orgSwitcherOpen}
                    onOpenChange={setOrgSwitcherOpen}
                  >
                    <PopoverTrigger asChild>
                      <button
                        type="button"
                        {...analyticsAttrs('organization-switcher')}
                        className="group flex h-8 max-h-8 min-h-8 min-w-0 max-w-full cursor-pointer items-center gap-2 rounded-lg px-2 -ms-2 transition-colors hover:bg-accent"
                      >
                        <InitialsAvatar name={selectedOrg.name} size="sm" />
                        <h1 className="m-0 min-w-0 max-w-[10rem] truncate text-[13px] font-semibold leading-tight text-foreground sm:max-w-[14rem] md:max-w-[20rem] lg:max-w-[28rem]">
                          {selectedOrg.name}
                        </h1>
                        {isCloud && (
                          <Badge
                            className={cn(
                              'shrink-0 rounded px-1.5 py-0.5 text-[10px] font-medium leading-none',
                              selectedOrg.billingPlanDowngrade
                                ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400'
                                : getPlanBadgeColor(selectedOrg.plan),
                            )}
                          >
                            {selectedOrg.billingPlanDowngrade
                              ? t('Downgraded')
                              : getPlanDisplayName(selectedOrg.plan)}
                          </Badge>
                        )}
                        <ChevronDown className="h-3.5 w-3.5 shrink-0 text-muted-foreground transition-transform group-data-[state=open]:rotate-180" />
                      </button>
                    </PopoverTrigger>
                    <PopoverContent
                      align="start"
                      className="w-72 border-border bg-popover p-0"
                    >
                      <div className="border-b border-border px-3 py-2">
                        <p className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                          {t('Switch organization')}
                        </p>
                      </div>
                      <div className="max-h-64 overflow-y-auto py-1">
                        {organizations.map((org) => (
                          <button
                            key={org.$id}
                            onClick={() => handleSelectOrg(org)}
                            className={cn(
                              'flex w-full cursor-pointer items-center gap-3 px-3 py-2 text-start transition-colors hover:bg-accent',
                              selectedOrg.$id === org.$id && 'bg-accent',
                            )}
                          >
                            <InitialsAvatar name={org.name} size="md" />
                            <div className="flex-1 min-w-0">
                              <p className="truncate text-[13px] font-medium text-foreground">
                                {org.name}
                              </p>
                              <div className="flex items-center gap-2">
                                {isCloud && (
                                  <span
                                    className={cn(
                                      'rounded px-1.5 py-0.5 text-[10px] font-medium',
                                      org.billingPlanDowngrade
                                        ? 'bg-amber-500/10 text-amber-600 dark:text-amber-400'
                                        : getPlanBadgeColor(org.plan),
                                    )}
                                  >
                                    {org.billingPlanDowngrade
                                      ? t('Downgraded')
                                      : getPlanDisplayName(org.plan)}
                                  </span>
                                )}
                                <span className="text-[11px] text-muted-foreground">
                                  {org.members}{' '}
                                  {org.members !== 1
                                    ? t('members')
                                    : t('member')}
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
                          type="button"
                          {...analyticsAttrs('create-organization')}
                          onClick={() => {
                            setOrgSwitcherOpen(false)
                            handleOpenCreateOrganization()
                          }}
                          className="flex w-full cursor-pointer items-center gap-2 rounded-md px-2 py-2 text-[13px] text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                        >
                          <Plus className="h-4 w-4" />
                          {t('Create organization')}
                        </button>
                      </div>
                    </PopoverContent>
                  </Popover>
                ) : (
                  <div className="flex h-8 max-h-8 min-h-8 min-w-0 max-w-full items-center gap-2 px-2 -ms-2">
                    <InitialsAvatar name={selectedOrg.name} size="sm" />
                    <h1 className="m-0 min-w-0 max-w-[10rem] truncate text-[13px] font-semibold leading-tight text-foreground sm:max-w-[14rem] md:max-w-[20rem] lg:max-w-[28rem]">
                      {selectedOrg.name}
                    </h1>
                  </div>
                )
              ) : orgId ? (
                <div
                  className="flex h-8 max-h-8 min-h-8 min-w-0 max-w-full items-center gap-2 overflow-hidden px-2 -ms-2"
                  aria-hidden
                >
                  <div className="h-6 w-6 shrink-0 animate-pulse rounded-full bg-muted" />
                  <div className="h-4 min-h-4 min-w-0 flex-1 max-w-[10rem] animate-pulse rounded bg-muted sm:max-w-[14rem] md:max-w-[20rem]" />
                  {isCloud && (
                    <div className="h-5 max-h-5 min-h-5 w-14 shrink-0 animate-pulse rounded bg-muted" />
                  )}
                  {supportsMultiTenancy && (
                    <div className="h-3.5 w-3.5 shrink-0 animate-pulse rounded bg-muted" />
                  )}
                </div>
              ) : null}
              {supportsMultiTenancy && (
                <Tooltip>
                  <TooltipTrigger asChild>
                    <Button
                      variant="ghost"
                      size="sm"
                      className="h-8 w-8 shrink-0 p-0 rounded-lg hover:bg-accent"
                      aria-label={t('Create organization')}
                      onClick={handleOpenCreateOrganization}
                      {...analyticsAttrs('create-organization')}
                    >
                      <Plus className="h-4 w-4" />
                    </Button>
                  </TooltipTrigger>
                  <TooltipContent>
                    <p>{t('Create organization')}</p>
                  </TooltipContent>
                </Tooltip>
              )}
            </div>

            {/* Right: same fixed h-8 band as left; hide members + invite on small screens */}
            <div className="hidden h-8 min-h-8 max-h-8 shrink-0 items-center gap-3 sm:flex">
              {orgId && (
                <div className="flex h-8 min-h-8 w-[5.5rem] shrink-0 items-center justify-start">
                  {!selectedOrg || membershipsLoading ? (
                    <div className="flex -space-x-2" aria-hidden>
                      <div
                        className="relative rounded-full border-2 border-background"
                        style={{ zIndex: 2 }}
                      >
                        <div className="h-8 w-8 shrink-0 rounded-full bg-muted animate-pulse" />
                      </div>
                      <div
                        className="relative rounded-full border-2 border-background"
                        style={{ zIndex: 1 }}
                      >
                        <div className="h-8 w-8 shrink-0 rounded-full bg-muted animate-pulse" />
                      </div>
                    </div>
                  ) : (
                    (() => {
                      const displayMembers = memberships.slice(
                        0,
                        HEADER_MEMBER_AVATAR_SLOTS,
                      )
                      const totalCount = membershipsTotal
                      const showOverflow =
                        totalCount > HEADER_MEMBER_AVATAR_SLOTS
                      const emptySlotCount = showOverflow
                        ? 0
                        : Math.max(0, HEADER_MEMBER_AVATAR_SLOTS - totalCount)
                      const slotCount =
                        displayMembers.length +
                        emptySlotCount +
                        (showOverflow ? 1 : 0)

                      return (
                        <div
                          className={cn(
                            'flex h-8 min-h-8 items-center',
                            slotCount > 1 && '-space-x-2',
                          )}
                        >
                          {(displayMembers.length > 0 || showOverflow) && (
                            <Link
                              to="/organizations/$orgId/settings/members"
                              params={{ orgId: orgId! }}
                              className={cn(
                                'flex h-8 min-h-8 items-center rounded-full focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 cursor-pointer',
                                (displayMembers.length > 1 ||
                                  (displayMembers.length > 0 &&
                                    showOverflow)) &&
                                  '-space-x-2',
                              )}
                              title={t('View members')}
                            >
                              {displayMembers.map(
                                (member: TeamMember, index: number) => (
                                  <div
                                    key={member.$id}
                                    className="relative rounded-full border-2 border-background"
                                    style={{
                                      zIndex:
                                        emptySlotCount +
                                        (displayMembers.length - index)}}
                                    title={member.userName}
                                  >
                                    <InitialsAvatar
                                      name={member.userName}
                                      size="md"
                                    />
                                  </div>
                                ),
                              )}
                              {showOverflow && (
                                <div
                                  className="relative flex h-8 w-8 items-center justify-center rounded-full border-2 border-background bg-muted text-[11px] font-medium text-foreground/80"
                                  style={{ zIndex: 0 }}
                                >
                                  +{totalCount - HEADER_MEMBER_AVATAR_SLOTS}
                                </div>
                              )}
                            </Link>
                          )}
                          {Array.from({ length: emptySlotCount }).map(
                            (_, index) => (
                              <EmptyMemberAvatarSlot
                                key={`empty-member-slot-${index}`}
                                zIndex={emptySlotCount - index}
                                onClick={() => setInviteDialogOpen(true)}
                                disabled={inviteDisabled}
                                disabledTooltip={inviteDisabledTooltip}
                              />
                            ),
                          )}
                        </div>
                      )
                    })()
                  )}
                </div>
              )}

              <TooltipProvider delayDuration={0}>
                <Tooltip>
                  <TooltipTrigger asChild>
                    <span className="inline-flex">
                      <Button
                        size="sm"
                        variant="outline"
                        className="h-8 gap-2 border-border text-[13px] text-muted-foreground hover:bg-accent hover:text-foreground"
                        onClick={() => setInviteDialogOpen(true)}
                        disabled={inviteDisabled}
                        {...analyticsAttrs('invite-org-member')}
                      >
                        <UserPlus className="h-3.5 w-3.5" />
                        {t('Invite')}
                      </Button>
                    </span>
                  </TooltipTrigger>
                  {inviteDisabledTooltip ? (
                    <TooltipContent className="max-w-xs text-xs">
                      {inviteDisabledTooltip}
                    </TooltipContent>
                  ) : null}
                </Tooltip>
              </TooltipProvider>
            </div>
          </div>

          {/* Tabs row: min height matches tab links (py-2.5 + text) so it doesn’t collapse before orgTabs render */}
          <div className="border-b border-border">
            <div
              className="mx-auto flex min-h-[2.75rem] w-full max-w-7xl items-end gap-0 overflow-x-auto px-4 sm:px-6"
              role="tablist"
            >
              {orgTabs.length > 0 ? (
                orgTabs.map((tab) => {
                  const tabAnalytics = getOrgTabAnalyticsAction(tab.id)
                  return (
                  <Link
                    key={tab.id}
                    to={tab.to as unknown}
                    params={{ orgId: orgId! } as unknown}
                    replace
                    role="tab"
                    aria-selected={activeTab === tab.id}
                    {...(tabAnalytics ? analyticsAttrs(tabAnalytics) : {})}
                    className={cn(
                      'relative flex h-[2.75rem] shrink-0 items-center gap-1.5 px-3 text-[13px] font-medium leading-none transition-colors rounded-sm',
                      'focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset',
                      activeTab === tab.id
                        ? 'text-foreground'
                        : 'text-muted-foreground hover:text-foreground/80',
                    )}
                  >
                    {t(tab.label)}
                    {activeTab === tab.id && (
                      <div className="absolute bottom-0 start-0 end-0 h-0.5 bg-foreground" />
                    )}
                  </Link>
                  )
                })
              ) : orgId ? (
                <div
                  className="flex h-[2.75rem] w-full items-center gap-6 px-3"
                  aria-hidden
                >
                  <div className="h-4 w-16 animate-pulse rounded bg-muted" />
                  <div className="h-4 w-14 animate-pulse rounded bg-muted" />
                  <div className="h-4 w-16 animate-pulse rounded bg-muted" />
                </div>
              ) : null}
            </div>
          </div>
        </div>

        {activeTab === 'domains' ? (
          <DomainsPlanLimitAlert orgId={orgId} />
        ) : null}

        {/* Plan Limit Alert - After Tabs */}
        {activeTab === 'settings' &&
          settingsSubTab === 'members' &&
          (() => {
            if (!organizationPlan) return null

            const seatsLimit = organizationPlan?.addons?.seats?.limit
            const planIncluded = organizationPlan?.addons?.seats?.planIncluded
            const limitNum = Number(seatsLimit ?? planIncluded)
            const limit = isNaN(limitNum) ? null : limitNum
            const planName =
              resolveOrganizationPlanDisplayLabel({
                planName: organizationPlan?.name ?? null,
                planId: organizationPlan?.$id}) || 'plan'

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
                              ? `${t("You've reached the limit of")} ${limit} ${limit !== 1 ? t('members') : t('member')}`
                              : t('Approaching member limit')}
                          </AlertTitle>
                          <AlertDescription className="text-[12px] text-amber-600/80 dark:text-amber-400/80">
                            <span className="inline">
                              {isAtLimit ? (
                                <>
                                  Your {planName} plan includes up to {limit}{' '}
                                  member{limit !== 1 ? 's' : ''}.{' '}
                                  <Link
                                    to="/upgrade"
                                    search={{ orgId: orgId! }}
                                    className="font-medium underline hover:no-underline"
                                  >
                                    {t('Upgrade')}
                                  </Link>{' '}
                                  {t('to unlock more capacity.')}
                                </>
                              ) : (
                                <>
                                  Your {planName} plan includes up to {limit}{' '}
                                  member{limit !== 1 ? 's' : ''}. You have{' '}
                                  {remaining} remaining.{' '}
                                  <Link
                                    to="/upgrade"
                                    search={{ orgId: orgId! }}
                                    className="font-medium underline hover:no-underline"
                                  >
                                    {t('Upgrade')}
                                  </Link>{' '}
                                  {t('to unlock more capacity.')}
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
                          <Link to="/upgrade" search={{ orgId: orgId! }}>
                            {t('Upgrade')}
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

            const projectLimit = organizationPlan?.addons?.projects?.limit
            const planIncluded =
              organizationPlan?.addons?.projects?.planIncluded
            const limitNum = Number(projectLimit ?? planIncluded)
            const limit = isNaN(limitNum) ? null : limitNum
            const planName =
              resolveOrganizationPlanDisplayLabel({
                planName: organizationPlan?.name ?? null,
                planId: organizationPlan?.$id}) || 'plan'

            // Only show if limit exists, is greater than 0, and user has reached it
            if (limit !== null && limit > 0) {
              const isAtLimit = totalProjectsCount >= limit
              if (!isAtLimit) {
                return null
              }

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
                            {`${t("You've reached the limit of")} ${limit} ${limit !== 1 ? t('projects') : t('project')}`}
                          </AlertTitle>
                          <AlertDescription className="text-[12px] text-amber-600/80 dark:text-amber-400/80">
                            <span className="inline">
                              <>
                                Your {planName} plan includes up to {limit}{' '}
                                project{limit !== 1 ? 's' : ''}.{' '}
                                <Link
                                  to="/upgrade"
                                  search={{ orgId: orgId! }}
                                  className="font-medium underline hover:no-underline"
                                >
                                  {t('Upgrade')}
                                </Link>{' '}
                                {t('to unlock more capacity.')}
                              </>
                            </span>
                          </AlertDescription>
                        </div>
                        <Button
                          asChild
                          size="sm"
                          className="h-8 shrink-0 bg-amber-500 px-3 text-[12px] font-medium text-amber-950 hover:bg-amber-400 dark:bg-amber-500 dark:text-amber-950 dark:hover:bg-amber-400"
                        >
                          <Link to="/upgrade" search={{ orgId: orgId! }}>
                            {t('Upgrade')}
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
        <div className="min-w-0 flex-1">
          <div className="mx-auto min-w-0 max-w-7xl px-4 py-4 sm:px-6">
            {/* Render child routes (domains list) when on domains route */}
            {shouldRenderChildren && children ? (
              <div className="h-full">{children}</div>
            ) : (
              <>
                {activeTab === 'projects' && (
                  <>
                    {isDebugModeOpen && (
                      <LightningCollectorGame
                        open={lightningCollectorOpen}
                        onOpenChange={setLightningCollectorOpen}
                      />
                    )}
                    {/* Error State */}
                    {activeProjectsError && (
                      <div className="flex flex-col items-center justify-center py-16 text-center">
                        <div className="mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-muted">
                          <Search className="h-6 w-6 text-muted-foreground" />
                        </div>
                        <h3 className="text-[15px] font-medium text-foreground">
                          {t('Failed to load projects')}
                        </h3>
                        <p className="mt-1 text-[13px] text-muted-foreground">
                          {activeProjectsError instanceof Error
                            ? activeProjectsError.message
                            : t('An error occurred')}
                        </p>
                      </div>
                    )}

                    {/* Projects Content */}
                    {!activeProjectsError && (
                      <>
                        {/* Toolbar: Search + Filters + Create */}
                        <div className="mb-4 flex items-center gap-3">
                          <div className="relative min-w-0 flex-1 @[640px]:max-w-xs">
                            <Search className="absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                              placeholder={t('Search projects...')}
                              value={searchQuery}
                              onChange={(e) => setSearchQuery(e.target.value)}
                              className="h-9 border-border bg-accent/50 ps-10 text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                            />
                          </div>

                          <ServiceListViewToggle
                            viewMode={projectsViewMode}
                            onViewModeChange={setProjectsViewMode}
                          />

                          <div className="ms-auto flex shrink-0 items-center gap-2">
                          {isDebugModeOpen && (
                            <LightningCollectorTrigger
                              open={lightningCollectorOpen}
                              onOpenChange={setLightningCollectorOpen}
                            />
                          )}
                          {(() => {
                            if (!canCreateProject(access, features)) {
                              return (
                                <TooltipProvider delayDuration={0}>
                                  <Tooltip>
                                    <TooltipTrigger asChild>
                                      <span>
                                        <Button
                                          variant="brandCta"
                                          className="h-9 gap-2 text-[13px] font-medium opacity-50 cursor-not-allowed"
                                          disabled
                                          {...analyticsAttrs('create-project')}
                                        >
                                          <Plus className="h-4 w-4" />
                                          {t('Create project')}
                                        </Button>
                                      </span>
                                    </TooltipTrigger>
                                    <TooltipContent>
                                      <p>
                                        {t(
                                          "You don't have permission to create projects.",
                                        )}
                                      </p>
                                    </TooltipContent>
                                  </Tooltip>
                                </TooltipProvider>
                              )
                            }
                            if (!organizationPlan) {
                              return (
                                <Button
                                  variant="brandCta"
                                  className="h-9 gap-2 text-[13px] font-medium"
                                  onClick={() =>
                                    setCreateProjectDialogOpen(true)
                                  }
                                  {...analyticsAttrs('create-project')}
                                >
                                  <Plus className="h-4 w-4" />
                                  {t('Create project')}
                                </Button>
                              )
                            }

                            const projectLimit =
                              organizationPlan?.addons?.projects?.limit
                            const planIncluded =
                              organizationPlan?.addons?.projects?.planIncluded
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
                                    <div>
                                      <Button
                                        variant="brandCta"
                                        className="h-9 gap-2 text-[13px] font-medium disabled:opacity-50 disabled:cursor-not-allowed"
                                        disabled={isAtLimit}
                                        onClick={() =>
                                          setCreateProjectDialogOpen(true)
                                        }
                                        {...analyticsAttrs('create-project')}
                                      >
                                        <Plus className="h-4 w-4" />
                                        {t('Create project')}
                                      </Button>
                                    </div>
                                  </TooltipTrigger>
                                  {isAtLimit && (
                                    <TooltipContent side="bottom">
                                      <p>
                                        {t(
                                          "You've reached the limit for projects on your plan",
                                        )}
                                      </p>
                                    </TooltipContent>
                                  )}
                                </Tooltip>
                              </TooltipProvider>
                            )
                          })()}
                          </div>
                        </div>

                        {/* Loading placeholder - same layout as grid to prevent shift */}
                        {showProjectsLoading ? (
                          <div className="rounded-lg border border-border bg-card py-12 text-center">
                            <p className="text-[13px] text-muted-foreground">
                              {t('Loading projects...')}
                            </p>
                          </div>
                        ) : (
                          <>
                            {/* Pinned projects (keeps previous data visible while refetching) */}
                            {!projectsSearchActive &&
                              pinnedProjects.length > 0 && (
                                <div className="mb-8">
                                  <h2 className="mb-3 text-[13px] font-semibold text-muted-foreground uppercase tracking-wider">
                                    {t('Pinned')}
                                  </h2>
                                  {projectsViewMode === 'list' ? (
                                    <ProjectsListTable
                                      projects={pinnedProjects}
                                      showProjectSettingsTab={
                                        showProjectSettingsTab
                                      }
                                      canDeleteProject={canManageProjects}
                                      onProjectDeleted={handleProjectDeleted}
                                      showFailedInvoiceOrgAlert={
                                        showFailedInvoiceOrgAlert
                                      }
                                      orgBillingReadonlyForFailedInvoice={
                                        orgBillingReadonlyForFailedInvoice
                                      }
                                      budgetLimitReached={showProjectsLockedAlert}
                                      showUsageCharts={showProjectUsageCharts}
                                      projectRequestsUsageById={
                                        projectRequestsUsageById
                                      }
                                      projectPlatformsById={
                                        projectPlatformsById
                                      }
                                      canPinProjects={canPinProjectsResult}
                                      pinnedIds={pinnedIds}
                                      onPinProject={handlePinProject}
                                      isPinPending={
                                        updateTeamPrefsMutation.isPending
                                      }
                                    />
                                  ) : (
                                  <div className={RESOURCE_CARD_GRID_CLASSNAME}>
                                    {pinnedProjects.map((project, index) => {
                                      const isDragActive =
                                        canReorderPinned &&
                                        pinnedDraggingIndex !== null
                                      const isDragSource =
                                        isDragActive &&
                                        pinnedDraggingIndex === index
                                      const isDropTarget =
                                        isDragActive &&
                                        pinnedDragOverIndex === index &&
                                        pinnedDraggingIndex !== index
                                      const isDragDimmed =
                                        isDragActive &&
                                        pinnedDraggingIndex !== index &&
                                        pinnedDragOverIndex !== index

                                      return (
                                        <ProjectContextMenu
                                          key={project.$id}
                                          project={project}
                                          showSettingsTab={
                                            showProjectSettingsTab
                                          }
                                          canDeleteProject={canManageProjects}
                                          onProjectDeleted={
                                            handleProjectDeleted
                                          }
                                          canPinProjects={canPinProjectsResult}
                                          isPinned
                                          onPinProject={handlePinProject}
                                          isPinPending={
                                            updateTeamPrefsMutation.isPending
                                          }
                                        >
                                          <div
                                            className={cn(
                                              RESOURCE_CARD_PADDED_CLASSNAME,
                                              RESOURCE_CARD_INTERACTIVE_CLASSNAME,
                                              'pb-0',
                                              'group relative transition-[opacity,transform,box-shadow,border-color] duration-200 ease-out',
                                              !isDragActive && 'border-border',
                                              isDragSource &&
                                                'z-0 scale-[0.99] opacity-[0.48] ring-2 ring-dashed ring-muted-foreground/45',
                                              isDropTarget &&
                                                'z-20 border-border opacity-100 ring-1 ring-inset ring-primary/35',
                                              isDragDimmed && 'opacity-[0.26]',
                                            )}
                                            data-project-card
                                            onDragOver={
                                              canReorderPinned
                                                ? (e) =>
                                                    handlePinnedDragOver(
                                                      e,
                                                      index,
                                                    )
                                                : undefined
                                            }
                                            onDragLeave={
                                              canReorderPinned
                                                ? handlePinnedCardDragLeave
                                                : undefined
                                            }
                                            onDrop={
                                              canReorderPinned
                                                ? (e) =>
                                                    handlePinnedDrop(e, index)
                                                : undefined
                                            }
                                          >
                                            <Link
                                              to="/projects/$projectId"
                                              params={{
                                                projectId: project.$id,
                                              }}
                                              className="absolute inset-0 z-0"
                                              aria-label={`${t('Open')} ${project.name}`}
                                            />
                                            <div
                                              className={cn(
                                                'relative z-[1] min-w-0 pointer-events-none',
                                                (canReorderPinned ||
                                                  canPinProjectsResult) &&
                                                  'pe-10',
                                              )}
                                            >
                                              <ProjectListCardMain
                                                project={project}
                                                budgetLimitReached={
                                                  showProjectsLockedAlert
                                                }
                                                failedInvoiceWarning={
                                                  <FailedInvoiceWarningIcon
                                                    show={
                                                      showFailedInvoiceOrgAlert
                                                    }
                                                    orgBillingReadonly={
                                                      orgBillingReadonlyForFailedInvoice
                                                    }
                                                    className="shrink-0"
                                                  />
                                                }
                                              />
                                            </div>
                                            {showProjectUsageCharts ? (
                                              <ProjectListCardRequestsChart
                                                projectId={project.$id}
                                                usageByProjectId={
                                                  projectRequestsUsageById
                                                }
                                              />
                                            ) : null}
                                            <ProjectListCardFooter
                                              project={project}
                                              showSettingsTab={
                                                showProjectSettingsTab
                                              }
                                              platformsByProjectId={
                                                projectPlatformsById
                                              }
                                            />
                                            {(canReorderPinned ||
                                              canPinProjectsResult) && (
                                              <div
                                                className={cn(
                                                  'absolute end-2 top-2 z-10 flex shrink-0 items-center gap-0.5 opacity-0 pointer-events-none transition-opacity group-hover:pointer-events-auto group-hover:opacity-100',
                                                  isDragActive &&
                                                    'pointer-events-auto opacity-100',
                                                )}
                                              >
                                                  {canPinProjectsResult ? (
                                                    <TooltipProvider
                                                      delayDuration={0}
                                                    >
                                                      <Tooltip>
                                                        <TooltipTrigger asChild>
                                                          <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="h-8 w-8 rounded-md"
                                                            aria-label={t(
                                                              'Unpin project',
                                                            )}
                                                            onClick={(e) => {
                                                              e.preventDefault()
                                                              handlePinProject(
                                                                project.$id,
                                                              )
                                                            }}
                                                            disabled={
                                                              updateTeamPrefsMutation.isPending
                                                            }
                                                          >
                                                            <PinOff className="h-4 w-4" />
                                                          </Button>
                                                        </TooltipTrigger>
                                                        <TooltipContent>
                                                          <p>
                                                            {t('Unpin project')}
                                                          </p>
                                                        </TooltipContent>
                                                      </Tooltip>
                                                    </TooltipProvider>
                                                  ) : null}
                                                  {canReorderPinned ? (
                                                    <TooltipProvider
                                                      delayDuration={0}
                                                    >
                                                      <Tooltip>
                                                        <TooltipTrigger asChild>
                                                          <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="icon"
                                                            draggable={
                                                              !updateTeamPrefsMutation.isPending
                                                            }
                                                            onDragStart={(e) =>
                                                              handlePinnedDragStart(
                                                                e,
                                                                index,
                                                                project.name ??
                                                                  '',
                                                              )
                                                            }
                                                            onDragEnd={
                                                              handlePinnedDragEnd
                                                            }
                                                            className={cn(
                                                              'h-8 w-8 cursor-grab rounded-md active:cursor-grabbing',
                                                              updateTeamPrefsMutation.isPending &&
                                                                'pointer-events-none opacity-40',
                                                            )}
                                                            aria-grabbed={
                                                              pinnedDraggingIndex ===
                                                              index
                                                            }
                                                            aria-label={`${t('Drag to reorder')} ${project.name}`}
                                                          >
                                                            <GripVertical className="h-4 w-4" />
                                                          </Button>
                                                        </TooltipTrigger>
                                                        <TooltipContent side="top">
                                                          <p>
                                                            {t(
                                                              'Drag to reorder',
                                                            )}
                                                          </p>
                                                        </TooltipContent>
                                                      </Tooltip>
                                                    </TooltipProvider>
                                                  ) : null}
                                              </div>
                                            )}
                                          </div>
                                        </ProjectContextMenu>
                                      )
                                    })}
                                  </div>
                                  )}
                                </div>
                              )}

                            {/* Projects by Team (non-pinned, paginated) */}
                            <div
                              className="space-y-8"
                              ref={projectsContainerRef}
                            >
                              {displayedProjectsByTeam.map(
                                ({ team, projects }) => (
                                  <div key={team.$id}>
                                    {!projectsSearchActive &&
                                      pinnedProjects.length > 0 && (
                                        <h2 className="mb-3 text-[13px] font-semibold text-muted-foreground uppercase tracking-wider">
                                          {t('All projects')}
                                        </h2>
                                      )}
                                    {projectsViewMode === 'list' ? (
                                      <ProjectsListTable
                                        projects={projects}
                                        showProjectSettingsTab={
                                          showProjectSettingsTab
                                        }
                                        canDeleteProject={canManageProjects}
                                        onProjectDeleted={handleProjectDeleted}
                                        showFailedInvoiceOrgAlert={
                                          showFailedInvoiceOrgAlert
                                        }
                                        orgBillingReadonlyForFailedInvoice={
                                          orgBillingReadonlyForFailedInvoice
                                        }
                                        budgetLimitReached={showProjectsLockedAlert}
                                        showUsageCharts={showProjectUsageCharts}
                                        projectRequestsUsageById={
                                          projectRequestsUsageById
                                        }
                                        projectPlatformsById={
                                          projectPlatformsById
                                        }
                                        canPinProjects={canPinProjectsResult}
                                        pinnedIds={pinnedIds}
                                        onPinProject={handlePinProject}
                                        isPinPending={
                                          updateTeamPrefsMutation.isPending
                                        }
                                      />
                                    ) : (
                                    <div
                                      className={RESOURCE_CARD_GRID_CLASSNAME}
                                    >
                                      {projects.map((project) => {
                                        const canPin =
                                          pinnedIds.length < MAX_PINNED_PROJECTS
                                        return (
                                          <ProjectContextMenu
                                            key={project.$id}
                                            project={project}
                                            showSettingsTab={
                                              showProjectSettingsTab
                                            }
                                            canDeleteProject={canManageProjects}
                                            onProjectDeleted={
                                              handleProjectDeleted
                                            }
                                            canPinProjects={canPinProjectsResult}
                                            isPinned={false}
                                            canPinMore={canPin}
                                            onPinProject={handlePinProject}
                                            isPinPending={
                                              updateTeamPrefsMutation.isPending
                                            }
                                          >
                                            <div
                                              className={cn(
                                                RESOURCE_CARD_PADDED_CLASSNAME,
                                                RESOURCE_CARD_INTERACTIVE_CLASSNAME,
                                                'group relative pb-0',
                                              )}
                                              data-project-card
                                            >
                                              <Link
                                                to="/projects/$projectId"
                                                params={{
                                                  projectId: project.$id,
                                                }}
                                                className="absolute inset-0 z-0"
                                                aria-label={`${t('Open')} ${project.name}`}
                                              />
                                              <div
                                                className={cn(
                                                  'relative z-[1] min-w-0 pointer-events-none',
                                                  canPin &&
                                                    canPinProjectsResult &&
                                                    'pe-10',
                                                )}
                                              >
                                                <ProjectListCardMain
                                                  project={project}
                                                  budgetLimitReached={
                                                    showProjectsLockedAlert
                                                  }
                                                  failedInvoiceWarning={
                                                    <FailedInvoiceWarningIcon
                                                      show={
                                                        showFailedInvoiceOrgAlert
                                                      }
                                                      orgBillingReadonly={
                                                        orgBillingReadonlyForFailedInvoice
                                                      }
                                                      className="shrink-0"
                                                    />
                                                  }
                                                />
                                              </div>
                                              {showProjectUsageCharts ? (
                                                <ProjectListCardRequestsChart
                                                  projectId={project.$id}
                                                  usageByProjectId={
                                                    projectRequestsUsageById
                                                  }
                                                />
                                              ) : null}
                                              <ProjectListCardFooter
                                                project={project}
                                                showSettingsTab={
                                                  showProjectSettingsTab
                                                }
                                                platformsByProjectId={
                                                  projectPlatformsById
                                                }
                                              />
                                              {canPin && canPinProjectsResult && (
                                                <div className="absolute end-2 top-2 z-10 flex shrink-0 opacity-0 pointer-events-none transition-opacity group-hover:pointer-events-auto group-hover:opacity-100">
                                                      <TooltipProvider
                                                        delayDuration={0}
                                                      >
                                                        <Tooltip>
                                                          <TooltipTrigger
                                                            asChild
                                                          >
                                                            <Button
                                                              variant="ghost"
                                                              size="icon"
                                                              className="h-8 w-8 rounded-md"
                                                              aria-label={t(
                                                                'Pin project',
                                                              )}
                                                              onClick={(e) => {
                                                                e.preventDefault()
                                                                handlePinProject(
                                                                  project.$id,
                                                                )
                                                              }}
                                                              disabled={
                                                                updateTeamPrefsMutation.isPending
                                                              }
                                                            >
                                                              <Pin className="h-4 w-4" />
                                                            </Button>
                                                          </TooltipTrigger>
                                                          <TooltipContent>
                                                            <p>
                                                              {t('Pin project')}
                                                            </p>
                                                          </TooltipContent>
                                                        </Tooltip>
                                                      </TooltipProvider>
                                                </div>
                                              )}
                                            </div>
                                          </ProjectContextMenu>
                                        )
                                      })}
                                    </div>
                                    )}
                                  </div>
                                ),
                              )}
                            </div>

                            {/* Empty State: no pinned and no other projects (not shown while search is fetching) */}
                            {(projectsSearchActive ||
                              pinnedProjects.length === 0) &&
                              displayedProjectsByTeam.length === 0 && (
                                <EmptyState
                                  icon={Folder}
                                  title={t('No projects yet')}
                                  description={t(
                                    'Create your first project to get started',
                                  )}
                                  isEmpty={!searchQuery}
                                  hasFilters={!!searchQuery}
                                  variant="card"
                                />
                              )}

                            {/* Pagination for Active Projects */}
                            {activeProjectsTotal > urlProjectsLimit && (
                              <Pagination
                                currentPage={activeProjectsPage}
                                totalItems={activeProjectsTotal}
                                pageSize={urlProjectsLimit}
                                pageSizeOptions={[12, 18, 36, 72]}
                                onPageChange={(page: number) => {
                                  setRequestedPage(page)
                                  navigate({
                                    to: location.pathname,
                                    search: (
                                      prev: Record<string, unknown>,
                                    ) => ({
                                      ...(typeof prev === 'object' && prev
                                        ? prev
                                        : {}),
                                      projectsPage: page,
                                      projectsLimit: urlProjectsLimit}),
                                    replace: true})
                                }}
                                onPageSizeChange={(size: number) => {
                                  setRequestedPage(1)
                                  setDisplayedPage(1)
                                  navigate({
                                    to: location.pathname,
                                    search: (
                                      prev: Record<string, unknown>,
                                    ) => ({
                                      ...(typeof prev === 'object' && prev
                                        ? prev
                                        : {}),
                                      projectsPage: 1,
                                      projectsLimit: size}),
                                    replace: true})
                                }}
                                itemLabel={t('projects')}
                              />
                            )}

                            {/* Enterprise Success Manager - Only show if plan supports it and debug option enabled */}
                            {supportsSuccessTeam &&
                              debugShowSuccessTeamCard && (
                                <EnterpriseSuccessManager />
                              )}
                          </>
                        )}
                      </>
                    )}
                  </>
                )}

                {activeTab === 'settings' && (
                  <SettingsLayoutShell
                    navItems={orgSettingsNavItems.map((item) =>
                      item.id === 'billing' &&
                      (showProjectsLockedAlert || showFailedInvoiceOrgAlert)
                        ? {
                            ...item,
                            endAdornment: (
                              <AlertTriangle
                                className="h-3.5 w-3.5 shrink-0 text-red-600 dark:text-red-400"
                                aria-label={
                                  showBudgetLimitAlert
                                    ? t('Budget limit reached')
                                    : showPlanUsageLimitAlert
                                      ? t('Plan limit reached')
                                      : t('Payment failed')
                                }
                              />
                            ),
                          }
                        : item,
                    )}
                    activeSectionId={settingsSubTab}
                    cardIndex={orgSettingsCardIndex}
                    searchQuery={settingsNavSearch}
                    onSearchQueryChange={setSettingsNavSearch}
                    onNavigateToSection={(sectionId) => {
                      const item = orgSettingsNavItems.find(
                        (n) => n.id === sectionId,
                      )
                      if (item && orgId) {
                        navigate({
                          to: item.to as '/',
                          params: { orgId }})
                      }
                    }}
                  >
                    {settingsSubTab === 'billing' ? (
                      <BillingTab />
                    ) : settingsSubTab === 'compliance' ? (
                      <ComplianceTab />
                    ) : settingsSubTab === 'oauth-apps' ? (
                      <OrgAppsView />
                    ) : settingsSubTab === 'api-keys' ? (
                      <SettingsCardsList
                        className="mx-auto w-full max-w-7xl px-4 pb-4 sm:px-6 sm:pb-6"
                        cards={[
                          {
                            id: 'api-key-types',
                            search: {
                              title: 'API key types',
                              description:
                                'Keys apply at different levels. Each key has its own permissions (scopes) to control access.',
                              keywords: [
                                'api',
                                'keys',
                                'scopes',
                                'credentials',
                              ]},
                            node: (
                              <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
                                <div className="px-6 py-4">
                                  <h3 className="text-[15px] font-semibold text-foreground">
                                    {t('API key types')}
                                  </h3>
                                  <p className="mt-1 text-[13px] text-muted-foreground">
                                    {t(
                                      'Keys apply at different levels. Each key has its own permissions (scopes) to control access.',
                                    )}
                                  </p>
                                </div>
                              </div>
                            )},
                          {
                            id: 'project-keys',
                            search: {
                              title: 'Project keys',
                              keywords: [
                                'database',
                                'storage',
                                'functions',
                                'project',
                              ]},
                            node: (
                              <div className="rounded-xl border border-border bg-card/50 overflow-hidden transition-colors hover:border-border/80">
                                <div className="px-4 py-3">
                                  <h3 className="text-[13px] font-semibold text-foreground">
                                    {t('Project keys')}
                                  </h3>
                                  <p className="mt-1 text-[12px] text-muted-foreground leading-relaxed">
                                    {t(
                                      'Databases, storage, users, functions. One project per key.',
                                    )}
                                  </p>
                                </div>
                                <div className="flex min-h-9 w-full items-center border-t border-border px-4 py-3 bg-muted/20">
                                  {activeProjects.length > 0 ? (
                                    <ProjectSelector
                                      orgTeamId={orgTeamId}
                                      getProjectLink={(projectId) => ({
                                        to: '/projects/$projectId/api-keys',
                                        params: { projectId }})}
                                      showApiKeysCount
                                    />
                                  ) : (
                                    <Button
                                      variant="outline"
                                      size="sm"
                                      className="h-9 w-full justify-between text-[13px] font-normal"
                                      asChild
                                    >
                                      <Link
                                        to="/organizations/$orgId"
                                        params={{ orgId: orgId ?? '' }}
                                        className="inline-flex items-center gap-1.5"
                                      >
                                        {t('Create a project first')}
                                        <ChevronRight className="h-3.5 w-3.5 shrink-0" />
                                      </Link>
                                    </Button>
                                  )}
                                </div>
                              </div>
                            )},
                          {
                            id: 'account-keys',
                            search: {
                              title: 'Account keys',
                              keywords: ['cli', 'sessions', 'user', 'account']},
                            node: (
                              <div className="rounded-xl border border-border bg-card/50 overflow-hidden transition-colors hover:border-border/80">
                                <div className="px-4 py-3">
                                  <h3 className="text-[13px] font-semibold text-foreground">
                                    {t('Account keys')}
                                  </h3>
                                  <p className="mt-1 text-[12px] text-muted-foreground leading-relaxed">
                                    {t(
                                      'Account-level ops, CLI auth, sessions. Per-user credentials.',
                                    )}
                                  </p>
                                </div>
                                <div className="flex min-h-9 w-full items-center border-t border-border px-4 py-3 bg-muted/20">
                                  <Button
                                    variant="outline"
                                    size="sm"
                                    className="h-9 w-full justify-between text-[13px] font-normal"
                                    asChild
                                  >
                                    <Link
                                      to="/account"
                                      className="inline-flex items-center gap-1.5"
                                    >
                                      {t('Account settings')}
                                      <ChevronRight className="h-3.5 w-3.5 shrink-0" />
                                    </Link>
                                  </Button>
                                </div>
                              </div>
                            )},
                          {
                            id: 'org-keys',
                            search: {
                              title: 'Org keys',
                              keywords: [
                                'billing',
                                'team',
                                'organization',
                                'org',
                              ]},
                            node: (
                              <div className="rounded-xl border border-border bg-card/50 overflow-hidden transition-colors hover:border-border/80">
                                <div className="px-4 py-3">
                                  <h3 className="text-[13px] font-semibold text-foreground">
                                    {t('Org keys')}
                                  </h3>
                                  <p className="mt-1 text-[12px] text-muted-foreground leading-relaxed">
                                    {t(
                                      'Billing, team, cross-project. One key for the whole org.',
                                    )}
                                  </p>
                                </div>
                                <div className="flex min-h-9 w-full items-center border-t border-border px-4 py-3 bg-muted/20">
                                  <Button
                                    variant="outline"
                                    size="sm"
                                    className="h-9 w-full justify-between text-[13px] font-normal"
                                    asChild
                                  >
                                    <DocsRouteLink className="inline-flex items-center gap-1.5" href="/docs/advanced/platform/api-keys">
                                      {t('Docs')}
                                      <ExternalLink className="h-3.5 w-3.5 shrink-0" />
                                    </DocsRouteLink>
                                  </Button>
                                </div>
                              </div>
                            )},
                          {
                            id: 'api-keys-info',
                            search: {
                              title: 'API key types',
                              keywords: [
                                'organization-level',
                                'server-side',
                                'manageable',
                              ]},
                            node: (
                              <div className="rounded-lg border border-border bg-muted/20 px-4 py-3">
                                <p className="text-[12px] text-muted-foreground">
                                  <Info className="mb-0.5 me-2 inline-block h-4 w-4 align-middle" />
                                  {t(
                                    'Organization-level keys will be manageable here once available. Meanwhile, use project keys for server-side access.',
                                  )}
                                </p>
                              </div>
                            )},
                        ]}
                      />
                    ) : settingsSubTab === 'members' ? (
                      <>
                        {/* Error State */}
                        {membershipsError && (
                          <div className="flex flex-col items-center justify-center py-16 text-center">
                            <div className="mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-muted">
                              <Users className="h-6 w-6 text-muted-foreground" />
                            </div>
                            <h3 className="text-[15px] font-medium text-foreground">
                              {t('Failed to load members')}
                            </h3>
                            <p className="mt-1 text-[13px] text-muted-foreground">
                              {membershipsError instanceof Error
                                ? membershipsError.message
                                : t('An error occurred')}
                            </p>
                          </div>
                        )}

                        {/* Members Content */}
                        {!membershipsError && (
                          <>
                            {/* Toolbar: Search + Invite */}
                            <div className="mb-4 flex items-center gap-3">
                              <div className="relative w-64">
                                <Search className="absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                  placeholder={t('Search members...')}
                                  value={membershipsSearchQuery}
                                  onChange={(e) =>
                                    setMembershipsSearchQuery(e.target.value)
                                  }
                                  className="h-9 border-border bg-accent/50 ps-10 text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                                />
                              </div>

                              <TooltipProvider delayDuration={0}>
                                <Tooltip>
                                  <TooltipTrigger asChild>
                                    <span className="ms-auto inline-flex">
                                      <Button
                                        variant="brandCta"
                                        className="h-9 gap-2 text-[13px] font-medium"
                                        onClick={() =>
                                          setInviteDialogOpen(true)
                                        }
                                        disabled={inviteDisabled}
                                        {...analyticsAttrs('invite-org-member')}
                                      >
                                        <Plus className="h-4 w-4" />
                                        {t('Invite')}
                                      </Button>
                                    </span>
                                  </TooltipTrigger>
                                  {inviteDisabledTooltip ? (
                                    <TooltipContent className="max-w-xs text-xs">
                                      {inviteDisabledTooltip}
                                    </TooltipContent>
                                  ) : null}
                                </Tooltip>
                              </TooltipProvider>
                            </div>

                            {/* Members List or Empty State */}
                            {membershipsLoading ? (
                              <div className="flex flex-col items-center justify-center py-16 text-center">
                                <div className="mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-muted">
                                  <Users className="h-6 w-6 text-muted-foreground" />
                                </div>
                                <p className="text-[13px] text-muted-foreground">
                                  {t('Loading members...')}
                                </p>
                              </div>
                            ) : memberships.length > 0 ? (
                              <>
                                <div className="rounded-lg border border-border bg-card overflow-hidden">
                                  <Table>
                                    <TableHeader>
                                      <TableRow className="hover:bg-transparent border-b border-border">
                                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                                          {t('Member')}
                                        </TableHead>
                                        {features.orgRoles && (
                                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-center">
                                            {t('Role')}
                                          </TableHead>
                                        )}
                                        {supportsProjectRoles && (
                                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-center hidden md:table-cell">
                                            {t('Projects')}
                                          </TableHead>
                                        )}
                                        {features.accountMfa && (
                                          <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-center hidden sm:table-cell">
                                            {t('MFA')}
                                          </TableHead>
                                        )}
                                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end hidden sm:table-cell">
                                          {t('Joined')}
                                        </TableHead>
                                        <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[40px]"></TableHead>
                                      </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                      {memberships.map((member: TeamMember) => {
                                        const memberProjectAccess =
                                          supportsProjectRoles
                                            ? parseProjectAccess(member.roles)
                                            : []
                                        const isProjectScoped =
                                          memberProjectAccess.length > 0
                                        const projectLabel = (
                                          projectId: string,
                                        ) =>
                                          memberProjectNameById.get(projectId) ??
                                          projectId
                                        const canManageMembers =
                                          canInviteOrgMember(access, features)
                                        return (
                                          <OrgMemberContextMenu
                                            key={member.$id}
                                            orgId={orgId!}
                                            member={member}
                                            canManageMembers={canManageMembers}
                                            onUpdate={
                                              canManageMembers &&
                                              member.status !== 'pending'
                                                ? () => {
                                                    setSelectedMember(member)
                                                    setSelectedRole(
                                                      member.role as
                                                        | 'owner'
                                                        | 'developer'
                                                        | 'editor'
                                                        | 'analyst'
                                                        | 'billing',
                                                    )
                                                    setEditAccessType(
                                                      memberProjectAccess.length >
                                                        0
                                                        ? 'specific'
                                                        : 'all',
                                                    )
                                                    setEditProjectAccess(
                                                      memberProjectAccess,
                                                    )
                                                    setUpdateRoleDialogOpen(
                                                      true,
                                                    )
                                                  }
                                                : undefined
                                            }
                                            onRemove={
                                              canManageMembers
                                                ? () => {
                                                    setSelectedMember(member)
                                                    setRemoveMemberDialogOpen(
                                                      true,
                                                    )
                                                  }
                                                : undefined
                                            }
                                          >
                                            <TableRow className="border-b border-border/50 hover:bg-muted/30 transition-colors">
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
                                                  <div className="flex min-w-0 items-center gap-2">
                                                    <p className="min-w-0 truncate text-[13px] font-medium text-foreground">
                                                      {member.userName ||
                                                        member.userEmail}
                                                    </p>
                                                    {member.status ===
                                                      'pending' && (
                                                      <Badge
                                                        variant="secondary"
                                                        className="text-[11px] font-medium border px-2 py-0.5 shrink-0"
                                                      >
                                                        {t('Pending')}
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
                                            {features.orgRoles && (
                                              <TableCell className="px-4 py-3">
                                                <div className="flex flex-nowrap items-center justify-center gap-1 whitespace-nowrap">
                                                  {isProjectScoped ? (
                                                    // Roles are per project for
                                                    // this member; the Projects
                                                    // cell names them.
                                                    <span className="text-[12px] text-muted-foreground">
                                                      {t('Per project')}
                                                    </span>
                                                  ) : (
                                                    <OrgRoleBadge
                                                      role={member.role}
                                                    />
                                                  )}
                                                </div>
                                              </TableCell>
                                            )}
                                            {supportsProjectRoles && (
                                              <TableCell className="px-4 py-3 hidden md:table-cell">
                                                <div className="flex items-center justify-center">
                                                  {!isProjectScoped ? (
                                                    <span className="text-[12px] text-muted-foreground">
                                                      {t('All projects')}
                                                    </span>
                                                  ) : (
                                                    // Popover, not an expanded
                                                    // row: the detail is a small
                                                    // table of its own and must
                                                    // not grow the member row or
                                                    // borrow the Member column,
                                                    // where a project name reads
                                                    // as the member's name.
                                                    <Popover>
                                                      <PopoverTrigger asChild>
                                                        <button
                                                          type="button"
                                                          className="inline-flex cursor-pointer items-center rounded-md px-1.5 py-0.5 text-[12px] text-foreground hover:bg-muted/60"
                                                        >
                                                          {`${memberProjectAccess.length} ${t('projects')}`}
                                                        </button>
                                                      </PopoverTrigger>
                                                      <PopoverContent
                                                        align="center"
                                                        className="w-64 p-0"
                                                      >
                                                        <p className="border-b border-border px-3 py-2 text-[11px] font-medium text-muted-foreground">
                                                          {t('Project access')}
                                                        </p>
                                                        <div className="max-h-64 overflow-y-auto py-1">
                                                          {memberProjectAccess.map(
                                                            (row) => (
                                                              <div
                                                                key={
                                                                  row.projectId
                                                                }
                                                                className="flex items-center justify-between gap-3 px-3 py-1.5"
                                                              >
                                                                <span
                                                                  className="min-w-0 truncate text-[12px] text-foreground"
                                                                  title={projectLabel(
                                                                    row.projectId,
                                                                  )}
                                                                >
                                                                  {projectLabel(
                                                                    row.projectId,
                                                                  )}
                                                                </span>
                                                                <span className="shrink-0 text-[11px] text-muted-foreground">
                                                                  {t(
                                                                    orgMembershipRoleDisplay(
                                                                      row.roleName,
                                                                    ).label,
                                                                  )}
                                                                </span>
                                                              </div>
                                                            ),
                                                          )}
                                                        </div>
                                                      </PopoverContent>
                                                    </Popover>
                                                  )}
                                                </div>
                                              </TableCell>
                                            )}
                                            {features.accountMfa && (
                                              <TableCell className="px-4 py-3 hidden sm:table-cell">
                                                <div className="flex items-center justify-center">
                                                  {member.status ===
                                                  'pending' ? (
                                                    <span className="text-muted-foreground/50 text-[12px]">
                                                      -
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
                                                </div>
                                              </TableCell>
                                            )}
                                            <TableCell className="px-4 py-3 hidden sm:table-cell">
                                              <div className="text-end">
                                                {member.status === 'pending' ? (
                                                  <span className="text-[12px] text-muted-foreground/70 italic">
                                                    {t('Invited')}
                                                  </span>
                                                ) : (
                                                  <DateTooltip
                                                    date={
                                                      new Date(member.joinedAt)
                                                    }
                                                    className="text-[12px] text-muted-foreground font-mono"
                                                  />
                                                )}
                                              </div>
                                            </TableCell>
                                            <TableCell className="px-4 py-3">
                                              <div className="flex items-center justify-end">
                                                {member.status === 'pending' ? (
                                                  <DropdownMenu>
                                                    <DropdownMenuTrigger
                                                      asChild
                                                    >
                                                      <RowActionsMenuTrigger />
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent
                                                      align="end"
                                                      className="w-48"
                                                    >
                                                      <DropdownMenuItem
                                                        onClick={async () => {
                                                          try {
                                                            const roles =
                                                              member.roles &&
                                                              member.roles
                                                                .length > 0
                                                                ? member.roles
                                                                : [member.role]
                                                            await resendInviteMutation.mutateAsync(
                                                              {
                                                                membershipId:
                                                                  member.membershipId ||
                                                                  member.$id,
                                                                email:
                                                                  member.userEmail,
                                                                roles},
                                                            )
                                                            toast.success(
                                                              t(
                                                                'Invitation resent successfully',
                                                              ),
                                                            )
                                                          } catch (error: unknown) {
                                                            toast.error(
                                                              error?.message ||
                                                                t(
                                                                  'Failed to resend invitation',
                                                                ),
                                                            )
                                                          }
                                                        }}
                                                        disabled={
                                                          resendInviteMutation.isPending
                                                        }
                                                      >
                                                        <MenuItemContent icon={Mail}>
                                                          {resendInviteMutation.isPending
                                                            ? t('Resending...')
                                                            : t('Resend')}
                                                        </MenuItemContent>
                                                      </DropdownMenuItem>
                                                      <DropdownMenuSeparator />
                                                      <DropdownMenuItem
                                                        onClick={() => {
                                                          setSelectedMember(
                                                            member,
                                                          )
                                                          setRemoveMemberDialogOpen(
                                                            true,
                                                          )
                                                        }}
                                                      >
                                                        <MenuItemContent icon={Trash2}>
                                                          {t('Remove')}
                                                        </MenuItemContent>
                                                      </DropdownMenuItem>
                                                    </DropdownMenuContent>
                                                  </DropdownMenu>
                                                ) : (
                                                  <DropdownMenu>
                                                    <DropdownMenuTrigger
                                                      asChild
                                                    >
                                                      <RowActionsMenuTrigger />
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent
                                                      align="end"
                                                      className="w-48"
                                                    >
                                                      {canInviteOrgMember(
                                                        access,
                                                        features,
                                                      ) && (
                                                        <>
                                                          <DropdownMenuItem
                                                            onClick={() => {
                                                              setSelectedMember(
                                                                member,
                                                              )
                                                              const role =
                                                                member.role as
                                                                  | 'owner'
                                                                  | 'developer'
                                                                  | 'editor'
                                                                  | 'analyst'
                                                                  | 'billing'
                                                              setSelectedRole(
                                                                role,
                                                              )
                                                              setUpdateRoleDialogOpen(
                                                                true,
                                                              )
                                                            }}
                                                          >
                                                            <MenuItemContent icon={UserCog}>
                                                              {t('Update')}
                                                            </MenuItemContent>
                                                          </DropdownMenuItem>
                                                          <DropdownMenuSeparator />
                                                        </>
                                                      )}
                                                      {canInviteOrgMember(
                                                        access,
                                                        features,
                                                      ) && (
                                                        <DropdownMenuItem
                                                          onClick={() => {
                                                            setSelectedMember(
                                                              member,
                                                            )
                                                            setRemoveMemberDialogOpen(
                                                              true,
                                                            )
                                                          }}
                                                        >
                                                          <MenuItemContent icon={Trash2}>
                                                            {t('Remove')}
                                                          </MenuItemContent>
                                                        </DropdownMenuItem>
                                                      )}
                                                    </DropdownMenuContent>
                                                  </DropdownMenu>
                                                )}
                                              </div>
                                            </TableCell>
                                            </TableRow>
                                          </OrgMemberContextMenu>
                                        )
                                      })}
                                    </TableBody>
                                  </Table>
                                </div>

                                {membershipsTotal > 0 && (
                                  <Pagination
                                    currentPage={displayedMembershipsPage}
                                    totalItems={membershipsTotal}
                                    pageSize={membershipsPageSize}
                                    pageSizeOptions={[12, 18, 36, 72]}
                                    onPageChange={(page: number) =>
                                      setRequestedMembershipsPage(page)
                                    }
                                    onPageSizeChange={(size) => {
                                      setMembershipsPageSize(size)
                                      setRequestedMembershipsPage(1)
                                      setDisplayedMembershipsPage(1)
                                    }}
                                    itemLabel={t('members')}
                                  />
                                )}
                              </>
                            ) : (
                              <EmptyState
                                icon={Users}
                                title={t('No members found')}
                                description={
                                  membershipsSearchQuery
                                    ? undefined
                                    : t(
                                        'Invite organization members to collaborate on your projects',
                                      )
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
                    ) : (
                      <SettingsCardsList
                        cards={[
                          ...(selectedOrg
                            ? [
                                {
                                  id: 'org-id',
                                  search: {
                                    title: 'Organization ID',
                                    keywords: [
                                      'id',
                                      'api',
                                      'webhook',
                                      'sdk',
                                      'copy',
                                    ]},
                                  node: (
                                    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
                                      <div className="px-6 py-4">
                                        <h3 className="text-[15px] font-semibold text-foreground">
                                          {t('Organization ID')}
                                        </h3>
                                      </div>
                                      <div className="border-t border-border" />
                                      <div className="px-6 py-4">
                                        <p className="text-[13px] text-muted-foreground mb-3">
                                          {t(
                                            'Use this ID when integrating with the Appwrite API, webhooks, or SDKs. Support may also ask for this ID when assisting with issues.', // pragma: allowlist secret
                                          )}
                                        </p>
                                        <CopyableId
                                          id={selectedOrg.$id}
                                          size="md"
                                          maxWidth={240}
                                        />
                                      </div>
                                    </div>
                                  )} satisfies SettingsCardItem,
                              ]
                            : []),
                          {
                            id: 'org-name',
                            search: {
                              title: 'Organization name',
                              keywords: ['rename', 'display name']},
                            node: (
                              <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
                                <div className="px-6 py-4">
                                  <h3 className="text-[15px] font-semibold text-foreground">
                                    {t('Organization name')}
                                  </h3>
                                </div>
                                <div className="border-t border-border" />
                                <div className="px-6 py-4">
                                  <p className="text-[13px] text-muted-foreground">
                                    {t(
                                      "Update your organization's display name. This will be visible to all organization members.",
                                    )}
                                  </p>
                                  <Input
                                    value={orgName}
                                    onChange={(e) => setOrgName(e.target.value)}
                                    placeholder={t('Organization name')}
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
                                          name: orgName.trim()})
                                      }
                                    }}
                                  >
                                    {t('Update')}
                                  </Button>
                                </div>
                              </div>
                            )},
                          ...(supportsMultiTenancy
                            ? [
                                {
                                  id: 'delete-org',
                                  search: {
                                    title: 'Delete organization',
                                    keywords: [
                                      'delete',
                                      'remove',
                                      'destroy',
                                      'danger',
                                    ]},
                                  node: (
                                    <div className="rounded-xl border border-destructive/50 bg-card/50 overflow-hidden">
                                      <div className="px-6 py-4">
                                        <h3 className="text-[15px] font-semibold text-foreground">
                                          {t('Delete organization')}
                                        </h3>
                                      </div>
                                      <div className="border-t border-destructive/20" />
                                      <div className="px-6 py-4">
                                        <p className="text-[13px] text-muted-foreground">
                                          {t(
                                            'Permanently delete this organization and all associated data. This action cannot be undone.',
                                          )}
                                        </p>

                                        {selectedOrg && (
                                          <div className="flex items-center gap-3 mt-4">
                                            <InitialsAvatar
                                              name={selectedOrg.name}
                                              size="md"
                                            />
                                            <div className="flex-1 min-w-0">
                                              <p className="text-[14px] font-medium text-foreground truncate">
                                                {selectedOrg.name}
                                              </p>
                                              <p className="text-[12px] text-muted-foreground">
                                                {membershipsTotal}{' '}
                                                {membershipsTotal !== 1
                                                  ? t('members')
                                                  : t('member')}{' '}
                                                • {totalOrgProjects}{' '}
                                                {totalOrgProjects !== 1
                                                  ? t('projects')
                                                  : t('project')}
                                              </p>
                                            </div>

                                            {memberships.length > 0 && (
                                              <div className="flex items-center gap-2">
                                                <Link
                                                  to="/organizations/$orgId/settings/members"
                                                  params={{ orgId: orgId! }}
                                                  className="flex -space-x-2 rounded-full focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 cursor-pointer"
                                                  title={t('View members')}
                                                  onClick={() =>
                                                    setDeleteOrgDialogOpen(
                                                      false,
                                                    )
                                                  }
                                                >
                                                  {memberships
                                                    .slice(0, 4)
                                                    .map(
                                                      (
                                                        member: TeamMember,
                                                        index: number,
                                                      ) => (
                                                        <div
                                                          key={member.$id}
                                                          className="relative rounded-full border-2 border-background"
                                                          style={{
                                                            zIndex: 4 - index}}
                                                          title={
                                                            member.userName
                                                          }
                                                        >
                                                          <InitialsAvatar
                                                            name={
                                                              member.userName
                                                            }
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
                                                </Link>
                                              </div>
                                            )}
                                          </div>
                                        )}
                                      </div>

                                      <div className="px-6 py-4 border-t border-destructive/20 bg-destructive/5">
                                        <Dialog
                                          open={deleteOrgDialogOpen}
                                          onOpenChange={setDeleteOrgDialogOpen}
                                        >
                                          <DialogTrigger asChild>
                                            <Button
                                              variant="destructive"
                                              size="sm"
                                              className="h-9 text-[13px]"
                                            >
                                              {t('Delete organization')}
                                            </Button>
                                          </DialogTrigger>
                                          <DialogContent className="sm:max-w-md p-0">
                                            <DialogHeader className="px-6 pt-6 text-start">
                                              <DialogTitle>
                                                {t('Delete organization')}
                                              </DialogTitle>
                                              <DialogDescription className="text-[13px] mt-2">
                                                {t(
                                                  'Are you sure you want to delete',
                                                )}{' '}
                                                {selectedOrg && (
                                                  <span className="font-medium text-foreground">
                                                    {selectedOrg.name}
                                                  </span>
                                                )}{' '}
                                                {t(
                                                  'and all its projects, databases, and files? This action cannot be undone.',
                                                )}
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
                                                        {membershipsTotal}{' '}
                                                        {membershipsTotal !== 1
                                                          ? t('members')
                                                          : t('member')}{' '}
                                                        {t('will lose access')}{' '}
                                                        •{' '}
                                                        {activeProjectsTotal}{' '}
                                                        {activeProjectsTotal !==
                                                        1
                                                          ? t('projects')
                                                          : t('project')}{' '}
                                                        {t('will be deleted')}
                                                      </p>
                                                    </div>
                                                  </div>
                                                )}
                                              </div>
                                              {activeProjects.length > 0 && (
                                                <div className="mb-4">
                                                  <p className="text-[12px] text-muted-foreground">
                                                    {t(
                                                      'Projects that will be deleted:',
                                                    )}{' '}
                                                    {activeProjects
                                                      .slice(0, 5)
                                                      .map((project, index) => (
                                                        <span key={project.$id}>
                                                          {index > 0 && ', '}
                                                          <span
                                                            className="font-medium text-foreground"
                                                            title={project.name}
                                                          >
                                                            {formatProjectNameForDisplay(
                                                              project.name,
                                                            )}
                                                          </span>
                                                        </span>
                                                      ))}
                                                    {activeProjectsTotal >
                                                      5 && (
                                                      <span>
                                                        {' '}
                                                        and{' '}
                                                        {activeProjectsTotal -
                                                          5}{' '}
                                                        more
                                                      </span>
                                                    )}
                                                  </p>
                                                </div>
                                              )}

                                              <label className="text-[13px] text-muted-foreground">
                                                {t('Type')}{' '}
                                                {selectedOrg && (
                                                  <span className="font-mono font-medium text-foreground bg-muted px-1.5 py-0.5 rounded">
                                                    {selectedOrg.name}
                                                  </span>
                                                )}{' '}
                                                {t('to confirm')}
                                              </label>
                                              <Input
                                                value={deleteOrgConfirmation}
                                                onChange={(e) =>
                                                  setDeleteOrgConfirmation(
                                                    e.target.value,
                                                  )
                                                }
                                                placeholder={t(
                                                  'Enter organization name',
                                                )}
                                                className="mt-2 h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-red-500/50 focus:ring-0"
                                              />
                                            </div>

                                            <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                                              <Button
                                                variant="outline"
                                                size="sm"
                                                className="h-9 text-[13px]"
                                                onClick={() => {
                                                  setDeleteOrgDialogOpen(false)
                                                  setDeleteOrgConfirmation('')
                                                }}
                                              >
                                                {t('Cancel')}
                                              </Button>
                                              <Button
                                                variant="destructive"
                                                size="sm"
                                                className="h-9 text-[13px]"
                                                disabled={
                                                  !selectedOrg ||
                                                  deleteOrgConfirmation !==
                                                    selectedOrg.name ||
                                                  deleteOrgMutation.isPending
                                                }
                                                onClick={() => {
                                                  if (
                                                    selectedOrg &&
                                                    deleteOrgConfirmation ===
                                                      selectedOrg.name
                                                  ) {
                                                    deleteOrgMutation.mutate(
                                                      selectedOrg.$id,
                                                    )
                                                  }
                                                }}
                                              >
                                                {t('Delete')}
                                              </Button>
                                            </div>
                                          </DialogContent>
                                        </Dialog>
                                      </div>
                                    </div>
                                  )} satisfies SettingsCardItem,
                              ]
                            : []),
                        ]}
                      />
                    )}
                  </SettingsLayoutShell>
                )}

                {activeTab === 'domains' && <DomainsView />}

                {activeTab === 'marketplace' && <MarketplaceView />}
              </>
            )}
          </div>
        </div>
      </ConsoleLayout>

      {/* Command Center */}
      <CommandCenter
        open={commandCenterOpen}
        onOpenChange={(open) => {
          setCommandCenterOpen(open)
          if (!open) setCommandCenterInitialSubPage(null)
        }}
        context="org"
        onOrgNavigate={handleOrgNavigate}
        initialSubPage={commandCenterInitialSubPage}
        onInitialSubPageConsumed={() => setCommandCenterInitialSubPage(null)}
        onInviteMember={
          canInviteOrgMember(access, features) && supportsAdditionalMembers
            ? () => setInviteDialogOpen(true)
            : undefined
        }
        onOrgCreateProject={
          canCreateProject(access, features)
            ? () => {
                handleOrgNavigate('projects')
                setCreateProjectDialogOpen(true)
              }
            : undefined
        }
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
          organizationPlan={organizationPlan}
          onSuccess={() => {
            if (activeTab !== 'settings' || settingsSubTab !== 'members') {
              handleOrgNavigate('settings/members')
            }
          }}
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
          <DialogHeader className="px-6 pt-6 text-start">
            <DialogTitle>{t('Update Role')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Update the role for')}{' '}
              {selectedMember?.userName ||
                selectedMember?.userEmail ||
                t('this member')}
              .
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 pb-4 pt-0">
            {supportsProjectRoles && (
              <div className="mb-4 space-y-1.5">
                <label className="text-[13px] font-medium text-foreground mb-1.5 block">
                  {t('Access')}
                </label>
                <RadioGroup
                  value={editAccessType}
                  onValueChange={(value: 'all' | 'specific') => {
                    setEditAccessType(value)
                    if (value === 'specific' && editProjectAccess.length === 0) {
                      setEditProjectAccess([
                        { projectId: '', roleName: DEFAULT_PROJECT_ROLE },
                      ])
                    }
                  }}
                  className="flex flex-row gap-4"
                >
                  <div className="flex items-center gap-2">
                    <RadioGroupItem id="edit-access-all" value="all" />
                    <Label
                      htmlFor="edit-access-all"
                      className="cursor-pointer text-[13px] font-normal"
                    >
                      {t('All projects')}
                    </Label>
                  </div>
                  <div className="flex items-center gap-2">
                    <RadioGroupItem id="edit-access-specific" value="specific" />
                    <Label
                      htmlFor="edit-access-specific"
                      className="cursor-pointer text-[13px] font-normal"
                    >
                      {t('Specific projects')}
                    </Label>
                  </div>
                </RadioGroup>
              </div>
            )}

            {supportsProjectRoles && editAccessType === 'specific' ? (
              <div>
                <ProjectAccessSelector
                  orgId={orgId!}
                  value={editProjectAccess}
                  onChange={setEditProjectAccess}
                />
                {!editProjectAccess.some((row) => row.projectId) && (
                  <p className="mt-2 text-[11px] text-muted-foreground">
                    {t('Add at least one project to grant access.')}
                  </p>
                )}
              </div>
            ) : (
            <div className="space-y-1.5">
              <label className="text-[13px] font-medium text-foreground mb-1.5 block">
                {t('Role')}
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
                            {t(role.label)}
                          </span>
                          <span className="text-[11px] text-muted-foreground leading-tight mt-0.5">
                            {t(role.description)}
                          </span>
                        </div>
                      </Label>
                    </div>
                  )
                })}
              </RadioGroup>
            </div>
            )}
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
              {t('Cancel')}
            </Button>
            <Button
              size="sm"
              className="h-9 text-[13px]"
              disabled={(() => {
                if (!selectedMember || updateRoleMutation.isPending) return true
                if (supportsProjectRoles && editAccessType === 'specific') {
                  return !editProjectAccess.some((row) => row.projectId)
                }
                // Moving a project-scoped member back to org-wide is a real
                // change even when the org role itself looks unchanged.
                if (parseProjectAccess(selectedMember.roles).length > 0) {
                  return false
                }
                return selectedRole === selectedMember.role
              })()}
              onClick={async () => {
                if (!selectedMember) return
                const roles =
                  supportsProjectRoles && editAccessType === 'specific'
                    ? editProjectAccess
                        .filter((row) => row.projectId && row.roleName)
                        .map((row) =>
                          buildProjectRole(row.projectId, row.roleName),
                        )
                    : [selectedRole]
                try {
                  await updateRoleMutation.mutateAsync({
                    membershipId:
                      selectedMember.membershipId || selectedMember.$id,
                    roles})
                  toast.success(t('Role updated successfully'))
                  setUpdateRoleDialogOpen(false)
                  setSelectedMember(null)
                } catch (error: unknown) {
                  toast.error(error?.message || t('Failed to update role'))
                }
              }}
            >
              {t('Update role')}
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
          <DialogHeader className="px-6 pt-6 text-start">
            <DialogTitle>
              {selectedMember?.status === 'pending'
                ? t('Cancel Invitation')
                : t('Remove from Team')}
            </DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {selectedMember?.status === 'pending' ? (
                <>
                  {t('Are you sure you want to cancel the invitation for')}{' '}
                  {selectedMember?.userName ||
                    selectedMember?.userEmail ||
                    t('this member')}
                  ? {t('They will not be able to join the organization.')}
                </>
              ) : (
                <>
                  {t('Are you sure you want to remove')}{' '}
                  {selectedMember?.userName ||
                    selectedMember?.userEmail ||
                    t('this member')}{' '}
                  {t(
                    'from the team? They will lose access to all organization resources.',
                  )}
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
              {t('Cancel')}
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
                      ? t('Invitation cancelled successfully')
                      : t('Member removed successfully'),
                  )
                  setRemoveMemberDialogOpen(false)
                  setSelectedMember(null)
                } catch (error: unknown) {
                  toast.error(error?.message || t('Failed to remove member'))
                }
              }}
            >
              {selectedMember?.status === 'pending'
                ? t('Cancel invitation')
                : t('Remove from team')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {supportsMultiTenancy && !features.billing && (
        <CreateOrganizationDialog
          open={createOrgDialogOpen}
          onOpenChange={setCreateOrgDialogOpen}
          onCreate={async (orgData) => {
            try {
              const newOrg = await createOrgMutation.mutateAsync(orgData)
              toast.success(t('Organization created successfully'))
              setCreateOrgDialogOpen(false)
              // Navigate to the newly created organization
              navigate({
                to: '/organizations/$orgId',
                params: { orgId: newOrg.$id },
                replace: true})
            } catch (error: unknown) {
              toast.error(error?.message || t('Failed to create organization'))
            }
          }}
          isLoading={createOrgMutation.isPending}
        />
      )}

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
