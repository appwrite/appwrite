import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  type MouseEvent,
  type ReactNode,
} from 'react'
import { Link, useNavigate } from '@tanstack/react-router'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Boxes,
  CreditCard,
  Globe,
  Import,
  Package,
  PanelLeft,
  PanelLeftClose,
  Pin,
  PinOff,
  Plus,
  Puzzle,
  Settings,
  ShieldCheck,
  UserPlus,
  Users,
  type LucideIcon,
} from 'lucide-react'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  canAccessOrgSettingsCompliance,
  canCreateProject,
  canShowOrgBillingNav,
  canShowOrgComplianceNav,
  canShowProjectSettings,
} from '@/lib/console-access-checks'
import { isUsageStatsCapabilityResolved } from '@/lib/console-profiles'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  DropdownMenuContent,
  DropdownMenuLabel,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { InitialsAvatar, PhotoAvatar } from '@/components/global/shared/Avatar'
import { InviteMembersDialog } from '@/components/pages/organizations/$orgId/overview/InviteMembers'
import {
  PartnerSettingsButton,
  PartnerSettingsDialog,
} from '@/components/pages/partners/$partnerId/PartnerSettingsDialog'
import { CreateProjectDialog } from '@/components/pages/organizations/$orgId/overview/CreateProjectDialog'
import { BillingTab } from '@/components/pages/organizations/$orgId/billing/BillingTab'
import { ComplianceTab } from '@/components/pages/organizations/$orgId/settings/ComplianceTab'
import { SettingsSearchProviderLocal } from '@/components/global/shared/settings-search/SettingsSearchContext'
import { ClientAppsPane } from '@/components/pages/partners/$partnerId/_components/AppsPane'
import { DevelopmentProjects } from '@/components/pages/partners/$partnerId/_components/DevelopmentProjects'
import { TransferProject } from '@/components/pages/partners/$partnerId/_components/TransferProject'
import { PartnerProjectsSpreadsheet } from '@/components/pages/partners/$partnerId/_components/ProjectsSpreadsheet'
import { ClientMembersList } from '@/components/pages/partners/$partnerId/_components/MembersList'
import { ClientSettingsPane } from '@/components/pages/partners/$partnerId/_components/SettingsPane'
import { ClientDomainsPane } from '@/components/pages/partners/$partnerId/_components/DomainsPane'
import {
  PartnerListPaginationFooter,
  PARTNER_LIST_PAGE_SIZE,
} from '@/components/pages/partners/$partnerId/_components/PartnerListPaginationFooter'
import {
  PartnerProjectsPaneHeader,
  usePartnerProjectsFilterState,
} from '@/components/pages/partners/$partnerId/_components/ProjectsPaneHeader'
import { ClientContextMenu } from '@/components/pages/partners/$partnerId/_components/ClientContextMenu'
import { DatabaseSidebarTableSearch } from '@/components/pages/projects/$projectId/databases/_components/DatabaseSidebarTableSearch'
import {
  ClientNavResizableLayout,
  PartnerClientsRailLayout,
} from '@/components/pages/partners/$partnerId/_components/ClientsResizableLayout'
import { PartnersDocsCard } from '@/components/pages/partners/$partnerId/_components/PartnersDocsCard'
import { PartnerPaneTitleRow } from '@/components/pages/partners/$partnerId/_components/PaneHeader'
import { usePartnerPinnedProjects } from '@/components/pages/partners/$partnerId/_components/usePartnerPinnedProjects'
import {
  PARTNER_PANE_HEADER_BUTTON_CLASSNAME,
  PARTNER_PANE_HEADER_CLASSNAME,
} from '@/components/pages/partners/$partnerId/_components/pane-header'
import { RESOURCE_CARD_BASE_CLASSNAME } from '@/components/pages/projects/$projectId/shared/ResourceCard'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import { apiNavItemClassName } from '@/lib/api-explorer/nav-styles'
import {
  PARTNER_CLIENT_SECTION_LABELS,
  PARTNER_CLIENT_SECTION_GROUPS,
  PARTNER_WORKSPACE_RESOURCE_SECTIONS,
  getDefaultPartnerClientId,
  isPartnerWorkspaceResourceSection,
  partnerClientLink,
  partnerClientsLink,
  partnerWorkspaceLink,
  sortPartnerClientsByPins,
  type PartnerAwareOrganization,
  type PartnerClientSection,
  type PartnerWorkspaceResourceSection,
} from '@/lib/partners'
import {
  activeProjectsQueryOptions,
  mapProjectToListItem,
  nextActiveProjectsSort,
  ACTIVE_PROJECTS_DEFAULT_SORT_BY,
  ACTIVE_PROJECTS_DEFAULT_SORT_ORDER,
  organizationAddonsQueryOptions,
  mapOrganizationMembershipsToTeamMembers,
  organizationMembershipsQueryOptions,
  partnerClientsQueryOptions,
  prefetchOrganizationBillingTab,
  useOrganizationById,
  useOrganizationPlan,
  useOrganizationScopes,
  usePartnerClientsColumnLayout,
  usePinnedPartnerClientIds,
  useProjectListAuthMauUsage,
  useProjectListBandwidthUsage,
  useProjectListComputeUsage,
  useProjectListExecutionsUsage,
  useProjectListRequestsUsage,
  useProjectListStorageUsage,
} from '@/lib/react-query/hooks'
import { type TeamMember } from '@/lib/utils/mock-data'
import { getPlanNameFromTier } from '@/lib/utils/plan-filter'
import { getPlanBadgeColor, getPlanDisplayName } from '@/lib/utils/plan-badge'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import { PARTNER_CLIENTS_COLUMN_COLLAPSED_WIDTH_PX } from '@/lib/resizable-layout'

type WorkspaceProps = {
  partnerId: string
  view: 'clients' | 'workspace'
  clientId?: string
  section?: PartnerClientSection
}

export function PartnerWorkspace({
  partnerId,
  view,
  clientId,
  section: sectionFromRoute,
}: WorkspaceProps) {
  const t = useT()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { account } = useAuth()
  const { features } = useConsoleProfile()
  const { collapsed, persistCollapsed } = usePartnerClientsColumnLayout(
    account as { prefs?: Record<string, unknown> } | undefined,
  )
  const { pinnedClientIds, togglePinnedClient, isPinPending } =
    usePinnedPartnerClientIds(
      account as { prefs?: Record<string, unknown> } | undefined,
      partnerId,
    )
  const { organization } = useOrganizationById(partnerId)
  const partner = organization as PartnerAwareOrganization | null
  const {
    data: clientsData,
    isPending: clientsPending,
    isFetching: clientsFetching,
    refetch: refetchClients,
  } = useQuery(partnerClientsQueryOptions(partnerId))
  const clients = useMemo(
    () =>
      sortPartnerClientsByPins(clientsData?.teams ?? [], pinnedClientIds),
    [clientsData?.teams, pinnedClientIds],
  )
  const defaultClientId = getDefaultPartnerClientId(clients, pinnedClientIds)
  const selectedFromUrl =
    clientId
      ? (clients.find((client) => client.$id === clientId) ?? null)
      : null
  const selectedClient =
    selectedFromUrl ??
    (view === 'clients' && !clientId && defaultClientId
      ? (clients.find((client) => client.$id === defaultClientId) ?? null)
      : null)
  const selectedClientId = selectedClient?.$id
  const lastClientsPaneClientIdRef = useRef<string | undefined>(undefined)
  if (selectedClientId) {
    lastClientsPaneClientIdRef.current = selectedClientId
  } else if (!lastClientsPaneClientIdRef.current && defaultClientId) {
    lastClientsPaneClientIdRef.current = defaultClientId
  }
  const clientsPaneClient =
    clients.find(
      (client) =>
        client.$id ===
        (selectedClientId ?? lastClientsPaneClientIdRef.current),
    ) ?? null
  const { access: selectedClientAccess, isLoading: selectedClientScopesLoading } =
    useOrganizationScopes(selectedClientId)
  const section: PartnerClientSection = sectionFromRoute ?? 'projects'
  const workspaceSection: PartnerWorkspaceResourceSection =
    isPartnerWorkspaceResourceSection(sectionFromRoute)
      ? sectionFromRoute
      : 'projects'
  const [displayedSection, setDisplayedSection] =
    useState<PartnerClientSection>(section)

  useEffect(() => {
    if (!selectedClientId) return
    void prefetchOrganizationBillingTab(queryClient, selectedClientId, {
      waitForAggregation: false,
    }).catch(() => {})
  }, [queryClient, selectedClientId])

  useEffect(() => {
    if (section !== 'billing') {
      setDisplayedSection(section)
      return
    }
    if (!selectedClientId) return
    let cancelled = false
    void prefetchOrganizationBillingTab(queryClient, selectedClientId, {
      waitForAggregation: true,
    })
      .catch(() => {})
      .finally(() => {
        if (!cancelled) setDisplayedSection('billing')
      })
    return () => {
      cancelled = true
    }
  }, [queryClient, section, selectedClientId])

  const openWorkspace = useCallback(
    (nextSection?: PartnerWorkspaceResourceSection) => {
      void navigate({
        ...partnerWorkspaceLink(partnerId, nextSection),
      })
    },
    [navigate, partnerId],
  )

  const openClient = useCallback(
    (nextClientId: string, nextSection?: PartnerClientSection) => {
      void navigate({
        ...partnerClientLink(partnerId, nextClientId, nextSection),
      })
    },
    [navigate, partnerId],
  )

  const setView = (nextView: 'clients' | 'workspace') => {
    if (nextView === 'workspace') {
      openWorkspace(
        isPartnerWorkspaceResourceSection(sectionFromRoute)
          ? sectionFromRoute
          : undefined,
      )
      return
    }
    if (selectedClientId) {
      openClient(selectedClientId)
      return
    }
    void navigate(partnerClientsLink(partnerId))
  }

  useEffect(() => {
    if (view !== 'clients' || !selectedClientId) return
    if (features.orgRoles && selectedClientScopesLoading) return
    const sectionUnavailable =
      (section === 'billing' &&
        !canShowOrgBillingNav(selectedClientAccess, features)) ||
      (section === 'compliance' &&
        !canShowOrgComplianceNav(selectedClientAccess, features))
    if (!sectionUnavailable) return
    openClient(selectedClientId, 'projects')
  }, [
    features,
    openClient,
    section,
    selectedClientAccess,
    selectedClientId,
    selectedClientScopesLoading,
    view,
  ])

  const handlePinClient = (clientId: string) => {
    const wasPinned = pinnedClientIds.includes(clientId)
    togglePinnedClient(clientId)
    toast.success(wasPinned ? t('Client unpinned') : t('Client pinned'))
  }

  const clientsList = (
    <ClientsColumn
      partnerId={partnerId}
      clients={clients}
      selectedClientId={selectedClient?.$id}
      collapsed={collapsed}
      pinnedIds={pinnedClientIds}
      isPinPending={isPinPending}
      isReloading={clientsFetching}
      onReload={() => void refetchClients()}
      onCollapsedChange={persistCollapsed}
      onSelect={(clientId) => openClient(clientId, section)}
      onPinClient={handlePinClient}
    />
  )
  const clientsMain = clientsPaneClient ? (
    <ClientDetailColumn
      partnerId={partnerId}
      client={clientsPaneClient}
      section={displayedSection}
    />
  ) : !clientsPending && clients.length === 0 ? (
    <div className="flex h-full min-h-0 min-w-0 flex-1 items-center justify-center px-8">
      <EmptyState
        icon={Users}
        title="No clients"
        description="Onboard a client organization to manage their projects and billing from this grid."
        isEmpty
      />
    </div>
  ) : null

  return (
    <div className="flex h-full min-h-0 min-w-0 flex-1 flex-col overflow-hidden bg-background">
      <AgencyHeader
        partnerId={partnerId}
        partner={partner}
        view={view}
        onViewChange={setView}
      />
      <div className="min-h-0 flex-1 px-3 pb-2 pt-2">
        <div
          className={cn(
            RESOURCE_CARD_BASE_CLASSNAME,
            'relative flex h-full min-h-0 min-w-0',
          )}
        >
          <PartnerClientsRailLayout
            className="@container/partner-workspace"
            visible={view === 'clients'}
            collapsed={collapsed}
            sidebar={clientsList}
          >
            <ClientNavResizableLayout
              sidebar={
                <div className="relative h-full min-h-0 min-w-0">
                  <PartnerViewLayer active={view === 'workspace'}>
                    <WorkspaceNavColumn
                      section={workspaceSection}
                      onSelect={(nextSection) => openWorkspace(nextSection)}
                    />
                  </PartnerViewLayer>
                  <PartnerViewLayer active={view === 'clients'}>
                    {clientsPaneClient ? (
                      <ClientNavColumn
                        client={clientsPaneClient}
                        section={section}
                        onSelect={(nextSection) =>
                          openClient(clientsPaneClient.$id, nextSection)
                        }
                        onPrefetchSection={(nextSection) => {
                          if (nextSection !== 'billing') return
                          void prefetchOrganizationBillingTab(
                            queryClient,
                            clientsPaneClient.$id,
                            { waitForAggregation: true },
                          ).catch(() => {})
                        }}
                      />
                    ) : null}
                  </PartnerViewLayer>
                </div>
              }
            >
              <div className="relative flex h-full min-h-0 min-w-[320px] flex-1 flex-col overflow-hidden">
                <PartnerViewLayer active={view === 'workspace'}>
                  <div className="flex h-full min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
                    {workspaceSection === 'projects' ? (
                      <DevelopmentProjects partnerId={partnerId} />
                    ) : workspaceSection === 'domains' ? (
                      <ClientDomainsPane
                        partnerId={partnerId}
                        organizationId={partnerId}
                        variant="workspace"
                      />
                    ) : (
                      <ClientAppsPane
                        partnerId={partnerId}
                        organizationId={partnerId}
                        variant="workspace"
                      />
                    )}
                  </div>
                </PartnerViewLayer>
                <PartnerViewLayer active={view === 'clients'}>
                  {clientsMain}
                </PartnerViewLayer>
              </div>
            </ClientNavResizableLayout>
          </PartnerClientsRailLayout>
        </div>
      </div>
    </div>
  )
}

function PartnerViewLayer({
  active,
  children,
  className,
}: {
  active: boolean
  children: ReactNode
  className?: string
}) {
  return (
    <div
      className={cn(
        'absolute inset-0 flex min-h-0 min-w-0 flex-col overflow-hidden',
        active ? 'z-10' : 'invisible pointer-events-none z-0',
        className,
      )}
      aria-hidden={!active}
      inert={!active ? true : undefined}
    >
      {children}
    </div>
  )
}

function Pane({
  title,
  action,
  children,
  footer,
  className,
  contentClassName,
}: {
  title: string
  action?: React.ReactNode
  children: React.ReactNode
  footer?: React.ReactNode
  className?: string
  contentClassName?: string
}) {
  return (
    <div
      className={cn(
        'flex h-full min-h-0 min-w-[220px] w-[240px] shrink-0 flex-col overflow-hidden border-e border-border',
        className,
      )}
    >
      <PartnerPaneTitleRow title={title}>{action}</PartnerPaneTitleRow>
      <div className={cn('min-h-0 flex-1 overflow-y-auto', contentClassName)}>
        {children}
      </div>
      {footer}
    </div>
  )
}

const HEADER_MEMBER_AVATAR_SLOTS = 2
const HEADER_MEMBER_AVATAR_FRAME =
  'relative h-8 w-8 shrink-0 rounded-full ring-2 ring-background'

function EmptyMemberAvatarSlot({
  zIndex,
  onClick,
}: {
  zIndex: number
  onClick: () => void
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      {...analyticsAttrs('invite-org-member')}
      className={cn(
        HEADER_MEMBER_AVATAR_FRAME,
        'flex cursor-pointer items-center justify-center border border-dashed border-muted-foreground/35 bg-muted/25 focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
      )}
      style={{ zIndex }}
      title="Invite member"
      aria-label="Invite member"
    >
      <Plus className="h-3.5 w-3.5 text-muted-foreground" />
    </button>
  )
}

function InviteMembersButton({
  onClick,
  variant = 'outline',
}: {
  onClick: () => void
  variant?: 'outline' | 'brandCta'
}) {
  return (
    <Button
      size="sm"
      variant={variant}
      className={
        variant === 'brandCta'
          ? 'h-9 gap-2 text-[13px] font-medium'
          : 'h-8 cursor-pointer gap-2 border-border text-[13px] text-muted-foreground hover:bg-accent hover:text-foreground'
      }
      onClick={onClick}
      {...analyticsAttrs('invite-org-member')}
    >
      {variant === 'brandCta' ? (
        <Plus className="h-4 w-4" />
      ) : (
        <UserPlus className="h-3.5 w-3.5" />
      )}
      Invite
    </Button>
  )
}

function MemberAvatarStack({
  memberships,
  total,
  isLoading,
  onInvite,
}: {
  memberships: TeamMember[]
  total: number
  isLoading: boolean
  onInvite: () => void
}) {
  if (isLoading) {
    return (
      <div className="flex h-8 w-[5.5rem] shrink-0 items-center justify-start overflow-visible">
        <div className="flex -space-x-2" aria-hidden>
          <div
            className={cn(HEADER_MEMBER_AVATAR_FRAME, 'animate-pulse bg-muted')}
            style={{ zIndex: 2 }}
          />
          <div
            className={cn(HEADER_MEMBER_AVATAR_FRAME, 'animate-pulse bg-muted')}
            style={{ zIndex: 1 }}
          />
        </div>
      </div>
    )
  }

  const displayMembers = memberships.slice(0, HEADER_MEMBER_AVATAR_SLOTS)
  const showOverflow = total > HEADER_MEMBER_AVATAR_SLOTS
  const emptySlotCount = showOverflow
    ? 0
    : Math.max(0, HEADER_MEMBER_AVATAR_SLOTS - total)
  const slotCount =
    displayMembers.length + emptySlotCount + (showOverflow ? 1 : 0)

  return (
    <div className="flex h-8 w-[5.5rem] shrink-0 items-center justify-start overflow-visible">
      <div
        className={cn(
          'flex h-8 items-center overflow-visible',
          slotCount > 1 && '-space-x-2',
        )}
      >
        {(displayMembers.length > 0 || showOverflow) && (
          <div
            className={cn(
              'flex h-8 items-center overflow-visible rounded-full',
              (displayMembers.length > 1 ||
                (displayMembers.length > 0 && showOverflow)) &&
                '-space-x-2',
            )}
          >
            {displayMembers.map((member, index) => (
              <div
                key={member.$id}
                className={HEADER_MEMBER_AVATAR_FRAME}
                style={{
                  zIndex: emptySlotCount + (displayMembers.length - index),
                }}
                title={member.userName}
              >
                <PhotoAvatar
                  userId={member.userId}
                  name={member.userName || member.userEmail}
                  size="md"
                  className="h-full w-full"
                />
              </div>
            ))}
            {showOverflow ? (
              <div
                className={cn(
                  HEADER_MEMBER_AVATAR_FRAME,
                  'flex items-center justify-center bg-muted text-[11px] font-medium leading-none text-foreground/80',
                )}
                style={{ zIndex: 0 }}
              >
                +{total - HEADER_MEMBER_AVATAR_SLOTS}
              </div>
            ) : null}
          </div>
        )}
        {Array.from({ length: emptySlotCount }).map((_, index) => (
          <EmptyMemberAvatarSlot
            key={`empty-member-slot-${index}`}
            zIndex={emptySlotCount - index}
            onClick={onInvite}
          />
        ))}
      </div>
    </div>
  )
}

function AgencyHeader({
  partnerId,
  partner,
  view,
  onViewChange,
}: {
  partnerId: string
  partner: PartnerAwareOrganization | null
  view: 'clients' | 'workspace'
  onViewChange: (next: 'clients' | 'workspace') => void
}) {
  const t = useT()
  const [inviteOpen, setInviteOpen] = useState(false)
  const [settingsOpen, setSettingsOpen] = useState(false)
  const { data: membershipsData, isLoading: membershipsLoading } = useQuery(
    organizationMembershipsQueryOptions(partnerId, 0, 8),
  )
  const { plan } = useOrganizationPlan(partnerId)
  const memberships = mapOrganizationMembershipsToTeamMembers(
    membershipsData,
    partnerId,
  )
  const memberTotal = membershipsData?.total ?? memberships.length

  return (
    <>
      <div className="grid min-h-16 shrink-0 grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-center gap-3 px-4 pb-2 pt-4">
        <div className="flex min-w-0 items-center gap-2.5">
          <InitialsAvatar name={partner?.name || 'Partner'} size="md" />
          <div className="min-w-0">
            <h1 className="m-0 truncate text-[14px] font-semibold leading-tight text-foreground">
              {partner?.name || 'Partner'}
            </h1>
          </div>
        </div>
        <ToggleGroup
          type="single"
          variant="outline"
          size="sm"
          value={view}
          onValueChange={(next) => {
            if (next === 'clients' || next === 'workspace') onViewChange(next)
          }}
          aria-label={t('Workspace view')}
        >
          <ToggleGroupItem
            value="clients"
            className="min-w-[6.5rem] cursor-pointer px-5"
          >
            {t('Clients')}
          </ToggleGroupItem>
          <ToggleGroupItem
            value="workspace"
            className="min-w-[6.5rem] cursor-pointer px-5"
          >
            {t('Workspace')}
          </ToggleGroupItem>
        </ToggleGroup>
        <div className="flex h-8 min-w-0 items-center justify-end gap-3 overflow-visible">
          <MemberAvatarStack
            memberships={memberships}
            total={memberTotal}
            isLoading={membershipsLoading}
            onInvite={() => setInviteOpen(true)}
          />
          <InviteMembersButton onClick={() => setInviteOpen(true)} />
          <PartnerSettingsButton onClick={() => setSettingsOpen(true)} />
        </div>
      </div>
      <PartnerSettingsDialog
        partnerId={partnerId}
        partner={partner}
        open={settingsOpen}
        onOpenChange={setSettingsOpen}
      />
      <InviteMembersDialog
        open={inviteOpen}
        onOpenChange={setInviteOpen}
        organizationId={partnerId}
        currentMemberCount={memberTotal}
        memberLimit={plan?.members != null ? Number(plan.members) : null}
        organizationPlan={plan}
      />
    </>
  )
}

function ClientListItem({
  partnerId,
  client,
  selected,
  collapsed,
  isPinned,
  isPinPending,
  onSelect,
  onPinClient,
}: {
  partnerId: string
  client: PartnerAwareOrganization
  selected: boolean
  collapsed: boolean
  isPinned: boolean
  isPinPending: boolean
  onSelect: (clientId: string) => void
  onPinClient: (clientId: string) => void
}) {
  const t = useT()
  const plan = getPlanNameFromTier(client.billingPlanId || client.billingPlan)
  const planLabel = getPlanDisplayName(plan)
  const clientName = client.name || client.$id
  const row = (
    <div
      className={cn(
        'group flex h-9 w-full min-w-0 items-center gap-2.5',
        collapsed && 'justify-center px-0',
        apiNavItemClassName(selected),
      )}
    >
      <button
        type="button"
        onClick={() => onSelect(client.$id)}
        title={collapsed ? undefined : clientName}
        className={cn(
          'flex h-9 min-w-0 flex-1 cursor-pointer items-center gap-2.5 text-start',
          collapsed && 'justify-center px-0',
        )}
      >
        <InitialsAvatar name={clientName} size="xs" />
        {collapsed ? (
          <span className="sr-only">{clientName}</span>
        ) : (
          <span className="min-w-0 flex-1 truncate">{clientName}</span>
        )}
      </button>
      {collapsed ? null : (
        <>
          <Tooltip>
            <TooltipTrigger asChild>
              <Button
                type="button"
                variant="ghost"
                size="icon"
                className={cn(
                  'h-7 w-7 shrink-0 cursor-pointer rounded-md opacity-0 transition-opacity group-hover:opacity-100',
                  isPinned && 'opacity-100',
                )}
                aria-label={isPinned ? t('Unpin client') : t('Pin client')}
                disabled={isPinPending}
                onClick={(event: MouseEvent<HTMLButtonElement>) => {
                  event.preventDefault()
                  event.stopPropagation()
                  onPinClient(client.$id)
                }}
                {...analyticsAttrs('partner-pin-client')}
              >
                {isPinned ? (
                  <PinOff className="h-3.5 w-3.5" />
                ) : (
                  <Pin className="h-3.5 w-3.5" />
                )}
              </Button>
            </TooltipTrigger>
            <TooltipContent>
              {isPinned ? t('Unpin client') : t('Pin client')}
            </TooltipContent>
          </Tooltip>
          <span
            className={cn(
              'shrink-0 rounded-md px-1.5 py-0.5 text-[10px] font-medium capitalize',
              getPlanBadgeColor(plan),
            )}
          >
            {planLabel}
          </span>
        </>
      )}
    </div>
  )

  return (
    <li className={collapsed ? 'w-full' : undefined}>
      <ClientContextMenu
        partnerId={partnerId}
        client={client}
        isPinned={isPinned}
        isPinPending={isPinPending}
        onPinClient={onPinClient}
      >
        <div className={collapsed ? 'w-full' : undefined}>
          {collapsed ? (
            <Tooltip>
              <TooltipTrigger asChild>{row}</TooltipTrigger>
              <TooltipContent side="right">{clientName}</TooltipContent>
            </Tooltip>
          ) : (
            row
          )}
        </div>
      </ClientContextMenu>
    </li>
  )
}

const PARTNER_CLIENTS_SORT_OPTIONS = [
  { value: 'name-asc', label: 'Name (A → Z)' },
  { value: 'name-desc', label: 'Name (Z → A)' },
  { value: 'created-asc', label: 'Created (oldest first)' },
  { value: 'created-desc', label: 'Created (newest first)' },
] as const

type PartnerClientsSort = (typeof PARTNER_CLIENTS_SORT_OPTIONS)[number]['value']

function clientSortName(client: PartnerAwareOrganization) {
  return client.name?.trim() || client.$id
}

function sortPartnerClientItems(
  items: PartnerAwareOrganization[],
  sort: PartnerClientsSort,
) {
  const next = [...items]
  next.sort((a, b) => {
    if (sort === 'name-asc' || sort === 'name-desc') {
      const cmp = clientSortName(a).localeCompare(clientSortName(b), undefined, {
        sensitivity: 'base',
      })
      return sort === 'name-asc' ? cmp : -cmp
    }
    const aTime = Date.parse(a.$createdAt ?? '') || 0
    const bTime = Date.parse(b.$createdAt ?? '') || 0
    return sort === 'created-asc' ? aTime - bTime : bTime - aTime
  })
  return next
}

function ClientsColumn({
  partnerId,
  clients,
  selectedClientId,
  collapsed,
  pinnedIds,
  isPinPending,
  isReloading,
  onReload,
  onCollapsedChange,
  onSelect,
  onPinClient,
}: {
  partnerId: string
  clients: PartnerAwareOrganization[]
  selectedClientId?: string
  collapsed: boolean
  pinnedIds: string[]
  isPinPending: boolean
  isReloading: boolean
  onReload: () => void
  onCollapsedChange: (collapsed: boolean) => void
  onSelect: (clientId: string) => void
  onPinClient: (clientId: string) => void
}) {
  const t = useT()
  const navigate = useNavigate()
  const [searchValue, setSearchValue] = useState('')
  const [sort, setSort] = useState<PartnerClientsSort>('name-asc')
  const search = searchValue.trim().toLowerCase()

  const goToOnboard = () =>
    navigate({
      to: '/partners/$partnerId/onboard',
      params: { partnerId },
    })

  const onboardButton = (
    <Button
      size="sm"
      variant={collapsed ? 'ghost' : 'outline'}
      className={cn(
        PARTNER_PANE_HEADER_BUTTON_CLASSNAME,
        collapsed ? 'h-8 w-8 px-0' : 'w-full justify-center px-2.5',
      )}
      onClick={goToOnboard}
      {...analyticsAttrs('onboard-client')}
    >
      <Plus className="h-3.5 w-3.5" />
      {collapsed ? null : t('Onboard')}
    </Button>
  )

  const pinnedIdSet = useMemo(() => new Set(pinnedIds), [pinnedIds])
  const visibleClients = useMemo(() => {
    if (!search) return clients
    return clients.filter((client) => {
      const name = (client.name || '').toLowerCase()
      return name.includes(search) || client.$id.toLowerCase().includes(search)
    })
  }, [clients, search])
  const pinnedClients = useMemo(
    () =>
      sortPartnerClientItems(
        visibleClients.filter((client) => pinnedIdSet.has(client.$id)),
        sort,
      ),
    [pinnedIdSet, sort, visibleClients],
  )
  const restClients = useMemo(
    () =>
      sortPartnerClientItems(
        visibleClients.filter((client) => !pinnedIdSet.has(client.$id)),
        sort,
      ),
    [pinnedIdSet, sort, visibleClients],
  )

  const renderClientItems = (items: PartnerAwareOrganization[]) => (
    <ul
      className={cn(
        'space-y-0.5',
        collapsed && 'flex flex-col items-center',
      )}
    >
      {items.map((client) => (
        <ClientListItem
          key={client.$id}
          partnerId={partnerId}
          client={client}
          selected={client.$id === selectedClientId}
          collapsed={collapsed}
          isPinned={pinnedIdSet.has(client.$id)}
          isPinPending={isPinPending}
          onSelect={onSelect}
          onPinClient={onPinClient}
        />
      ))}
    </ul>
  )

  const clientList =
    clients.length === 0 ? (
      collapsed ? null : (
        <p className="px-4 py-4 text-[12px] leading-relaxed text-muted-foreground">
          {t(
            'Onboard a client organization to manage their projects and billing from this grid.',
          )}
        </p>
      )
    ) : visibleClients.length === 0 ? (
      collapsed ? null : (
        <p className="px-4 py-4 text-[12px] leading-relaxed text-muted-foreground">
          {t('No clients match your search')}
        </p>
      )
    ) : (
      <div
        className={cn(
          'space-y-3 px-4 py-3',
          collapsed && 'flex flex-col items-center p-1.5',
        )}
      >
        {pinnedClients.length > 0 ? (
          <div className={cn('w-full space-y-1.5', collapsed && 'space-y-0.5')}>
            {collapsed ? null : (
              <p className="px-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                {t('Pinned')}
              </p>
            )}
            {renderClientItems(pinnedClients)}
          </div>
        ) : null}
        {pinnedClients.length > 0 && restClients.length > 0 ? (
          <div
            role="separator"
            className={cn(
              'border-t border-border',
              collapsed ? 'w-6' : 'mx-2',
            )}
          />
        ) : null}
        {restClients.length > 0 ? (
          <div className={cn('w-full space-y-1.5', collapsed && 'space-y-0.5')}>
            {collapsed || pinnedClients.length === 0 ? null : (
              <p className="px-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                {t('All clients')}
              </p>
            )}
            {renderClientItems(restClients)}
          </div>
        ) : null}
      </div>
    )

  if (collapsed) {
    return (
      <div
        className="flex h-full shrink-0 flex-col overflow-hidden bg-background"
        style={{ width: PARTNER_CLIENTS_COLUMN_COLLAPSED_WIDTH_PX }}
      >
        <div className={cn(PARTNER_PANE_HEADER_CLASSNAME, 'justify-center px-0')}>
          <TooltipProvider delayDuration={0}>
            <Tooltip>
              <TooltipTrigger asChild>
                <Button
                  size="sm"
                  variant="ghost"
                  className="h-8 w-8 cursor-pointer px-0"
                  onClick={() => onCollapsedChange(false)}
                  aria-label={t('Expand clients list')}
                >
                  <PanelLeft className="h-3.5 w-3.5" />
                </Button>
              </TooltipTrigger>
              <TooltipContent side="right">{t('Expand clients list')}</TooltipContent>
            </Tooltip>
          </TooltipProvider>
        </div>
        <div className="flex min-h-0 flex-1 flex-col overflow-y-auto">
          <div className="flex justify-center pt-3 pb-1">
            <TooltipProvider delayDuration={0}>
              <Tooltip>
                <TooltipTrigger asChild>
                  <span className="inline-flex">{onboardButton}</span>
                </TooltipTrigger>
                <TooltipContent side="right">{t('Onboard')}</TooltipContent>
              </Tooltip>
            </TooltipProvider>
          </div>
          <TooltipProvider delayDuration={0}>{clientList}</TooltipProvider>
        </div>
        <PartnersDocsCard compact />
      </div>
    )
  }

  return (
    <Pane
      title={t('Clients')}
      className="min-w-0 w-full border-e-0"
      contentClassName="flex min-h-0 flex-col overflow-hidden"
      footer={<PartnersDocsCard />}
      action={
        <TooltipProvider delayDuration={0}>
          <Tooltip>
            <TooltipTrigger asChild>
              <Button
                size="sm"
                variant="ghost"
                className="h-8 w-8 cursor-pointer px-0"
                onClick={() => onCollapsedChange(true)}
                aria-label={t('Collapse clients list')}
              >
                <PanelLeftClose className="h-3.5 w-3.5" />
              </Button>
            </TooltipTrigger>
            <TooltipContent>{t('Collapse clients list')}</TooltipContent>
          </Tooltip>
        </TooltipProvider>
      }
    >
      <div className="shrink-0 px-4 pt-3">
        {onboardButton}
      </div>
      <div className="mt-3 h-px shrink-0 bg-border" role="separator" aria-hidden />
      <div className="shrink-0 px-4 py-3">
        <DatabaseSidebarTableSearch
          value={searchValue}
          onChange={setSearchValue}
          placeholder={t('Search clients...')}
          ariaLabel={t('Search clients...')}
          clearAriaLabel={t('Clear search')}
          isFetching={isReloading}
          onRefresh={onReload}
          sortAriaLabel={t('Sort clients')}
          sortMenu={
            <DropdownMenuContent align="end" className="w-56">
              <DropdownMenuLabel className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                {t('Sort clients')}
              </DropdownMenuLabel>
              <DropdownMenuSeparator />
              <DropdownMenuRadioGroup
                value={sort}
                onValueChange={(value) => {
                  const option = PARTNER_CLIENTS_SORT_OPTIONS.find(
                    (item) => item.value === value,
                  )
                  if (option) setSort(option.value)
                }}
              >
                {PARTNER_CLIENTS_SORT_OPTIONS.map((option) => (
                  <DropdownMenuRadioItem
                    key={option.value}
                    value={option.value}
                  >
                    {t(option.label)}
                  </DropdownMenuRadioItem>
                ))}
              </DropdownMenuRadioGroup>
            </DropdownMenuContent>
          }
        />
      </div>
      <div className="min-h-0 flex-1 overflow-y-auto">
        <TooltipProvider delayDuration={0}>{clientList}</TooltipProvider>
      </div>
    </Pane>
  )
}

const PARTNER_CLIENT_SECTION_ICONS: Record<PartnerClientSection, LucideIcon> = {
  projects: Boxes,
  members: Users,
  compliance: ShieldCheck,
  addons: Puzzle,
  domains: Globe,
  apps: Package,
  settings: Settings,
  billing: CreditCard,
}

function WorkspaceNavColumn({
  section,
  onSelect,
}: {
  section: PartnerWorkspaceResourceSection
  onSelect: (section: PartnerWorkspaceResourceSection) => void
}) {
  const t = useT()
  return (
    <Pane title={t('Resources')} className="min-w-0 w-full border-e-0">
      <nav className="space-y-4 px-4 py-3" aria-label={t('Resources')}>
        <div className="space-y-0.5">
          {PARTNER_WORKSPACE_RESOURCE_SECTIONS.map((item) => {
            const Icon = PARTNER_CLIENT_SECTION_ICONS[item]
            return (
              <button
                key={item}
                type="button"
                onClick={() => onSelect(item)}
                className={cn(
                  'flex h-9 w-full cursor-pointer items-center gap-2.5 text-start',
                  apiNavItemClassName(section === item),
                )}
              >
                <Icon className="h-3.5 w-3.5 shrink-0" />
                {t(PARTNER_CLIENT_SECTION_LABELS[item])}
              </button>
            )
          })}
        </div>
      </nav>
    </Pane>
  )
}

function ClientNavColumn({
  client,
  section,
  onSelect,
  onPrefetchSection,
}: {
  client: PartnerAwareOrganization
  section: PartnerClientSection
  onSelect: (section: PartnerClientSection) => void
  onPrefetchSection?: (section: PartnerClientSection) => void
}) {
  const t = useT()
  return (
    <Pane
      title={client.name || 'Client'}
      className="min-w-0 w-full border-e-0"
      action={
        <Button
          asChild
          size="sm"
          variant="ghost"
          className={cn(PARTNER_PANE_HEADER_BUTTON_CLASSNAME, 'px-2.5 text-[12px]')}
        >
          <Link
            to="/organizations/$orgId"
            params={{ orgId: client.$id }}
            target="_blank"
            rel="noopener noreferrer"
          >
            {t('Open')}
          </Link>
        </Button>
      }
    >
      <nav className="space-y-4 px-4 py-3" aria-label={t('Client resources')}>
        {PARTNER_CLIENT_SECTION_GROUPS.map((group) => (
          <div key={group.id} className="space-y-1.5">
            <p className="px-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t(group.label)}
            </p>
            <div className="space-y-0.5">
              {group.sections.map((item) => {
                const Icon = PARTNER_CLIENT_SECTION_ICONS[item]
                return (
                  <button
                    key={item}
                    type="button"
                    onClick={() => onSelect(item)}
                    onMouseEnter={() => onPrefetchSection?.(item)}
                    onFocus={() => onPrefetchSection?.(item)}
                    className={cn(
                      'flex h-9 w-full cursor-pointer items-center gap-2.5 text-start',
                      apiNavItemClassName(section === item),
                    )}
                  >
                    <Icon className="h-3.5 w-3.5 shrink-0" />
                    {t(PARTNER_CLIENT_SECTION_LABELS[item])}
                  </button>
                )
              })}
            </div>
          </div>
        ))}
      </nav>
    </Pane>
  )
}

function ClientDetailColumn({
  partnerId,
  client,
  section,
}: {
  partnerId: string
  client: PartnerAwareOrganization
  section: PartnerClientSection
}) {
  const t = useT()
  return (
    <div className="flex h-full min-h-0 min-w-[320px] flex-1 flex-col overflow-hidden">
      {section === 'projects' ? (
        <ClientProjectsList
          key={client.$id}
          partnerId={partnerId}
          organizationId={client.$id}
        />
      ) : section === 'members' ? (
        <ClientMembersList key={client.$id} organizationId={client.$id} />
      ) : section === 'settings' ? (
        <ClientSettingsPane
          key={client.$id}
          partnerId={partnerId}
          client={client}
        />
      ) : section === 'apps' ? (
        <ClientAppsPane
          key={client.$id}
          partnerId={partnerId}
          organizationId={client.$id}
        />
      ) : section === 'domains' ? (
        <ClientDomainsPane
          key={client.$id}
          partnerId={partnerId}
          organizationId={client.$id}
        />
      ) : (
        <>
          <PartnerPaneTitleRow
            title={t(PARTNER_CLIENT_SECTION_LABELS[section])}
          />
          <div className="min-h-0 flex-1 overflow-y-auto p-4">
          {section === 'billing' ? (
            <ClientBillingSection organizationId={client.$id} />
          ) : section === 'addons' ? (
            <ClientAddonsList organizationId={client.$id} />
          ) : section === 'compliance' ? (
            <ClientComplianceSection organizationId={client.$id} />
          ) : null}
        </div>
        </>
      )}
    </div>
  )
}

function ClientProjectsList({
  partnerId,
  organizationId,
}: {
  partnerId: string
  organizationId: string
}) {
  const t = useT()
  const queryClient = useQueryClient()
  const [createOpen, setCreateOpen] = useState(false)
  const [transferOpen, setTransferOpen] = useState(false)
  const [handoffProjectId, setHandoffProjectId] = useState<string | undefined>()
  const [searchValue, setSearchValue] = useState('')
  const [requestedPage, setRequestedPage] = useState(1)
  const [displayedPage, setDisplayedPage] = useState(1)
  const [pageSize, setPageSize] = useState(PARTNER_LIST_PAGE_SIZE)
  const [sortBy, setSortBy] = useState(ACTIVE_PROJECTS_DEFAULT_SORT_BY)
  const [sortOrder, setSortOrder] = useState<'asc' | 'desc'>(
    ACTIVE_PROJECTS_DEFAULT_SORT_ORDER,
  )
  const resetListToFirstPage = () => {
    setRequestedPage(1)
    setDisplayedPage(1)
  }
  const {
    filtersOpen,
    setFiltersOpen,
    filterMap,
    filterQueries,
    hasFilters,
    applyFilter,
    removeFilter,
    clearAll,
    applyQuery,
  } = usePartnerProjectsFilterState(resetListToFirstPage)
  const { features, isSelfHosted } = useConsoleProfile()
  const { access } = useOrganizationScopes(organizationId)
  const { plan } = useOrganizationPlan(organizationId)
  const canCreate = canCreateProject(access, features)
  const showSettingsTab = canShowProjectSettings(access, features)
  const createDisabledTooltip = canCreate
    ? undefined
    : t("You don't have permission to create projects.")
  const search = searchValue.trim()
  const searchActive = search.length > 0
  const listHasFilters = searchActive || hasFilters
  const {
    pinnedIds,
    pinnedProjects,
    listExcludePinnedIds,
    canPinProjects: canPin,
    isPinPending,
    handlePinProject,
  } = usePartnerPinnedProjects(organizationId, listHasFilters)

  const requestedQuery = useQuery(
    activeProjectsQueryOptions(
      organizationId,
      requestedPage - 1,
      pageSize,
      search,
      listExcludePinnedIds,
      undefined,
      sortBy,
      sortOrder,
      filterQueries,
    ),
  )
  const displayedQuery = useQuery(
    activeProjectsQueryOptions(
      organizationId,
      displayedPage - 1,
      pageSize,
      search,
      listExcludePinnedIds,
      undefined,
      sortBy,
      sortOrder,
      filterQueries,
    ),
  )

  useEffect(() => {
    if (!requestedQuery.isFetching && requestedPage !== displayedPage) {
      setDisplayedPage(requestedPage)
    }
  }, [requestedQuery.isFetching, requestedPage, displayedPage])

  const projects = useMemo(
    () => (displayedQuery.data?.projects ?? []).map(mapProjectToListItem),
    [displayedQuery.data?.projects],
  )
  const total = displayedQuery.data?.total ?? 0
  const isLoading = displayedQuery.isLoading && projects.length === 0 && pinnedProjects.length === 0
  const showPagination = projects.length > 0
  const projectIds = useMemo(
    () => [
      ...pinnedProjects.map((project) => project.$id),
      ...projects.map((project) => project.$id),
    ],
    [pinnedProjects, projects],
  )
  const showProjectUsageCharts =
    features.usageStats ||
    (isSelfHosted && !isUsageStatsCapabilityResolved())
  const projectRequestsUsageById = useProjectListRequestsUsage(
    projectIds,
    showProjectUsageCharts,
    plan,
  )
  const projectBandwidthUsageById = useProjectListBandwidthUsage(
    projectIds,
    showProjectUsageCharts,
    plan,
  )
  const projectExecutionsUsageById = useProjectListExecutionsUsage(
    projectIds,
    showProjectUsageCharts,
    plan,
  )
  const projectStorageUsageById = useProjectListStorageUsage(
    projectIds,
    showProjectUsageCharts,
    plan,
  )
  const projectComputeUsageById = useProjectListComputeUsage(
    projectIds,
    showProjectUsageCharts,
    plan,
  )
  const projectAuthMauUsageById = useProjectListAuthMauUsage(
    projectIds,
    showProjectUsageCharts,
    plan,
  )

  useEffect(() => {
    const totalPages = Math.max(1, Math.ceil(total / pageSize))
    if (total > 0 && requestedPage > totalPages) {
      setRequestedPage(totalPages)
    }
  }, [total, pageSize, requestedPage])

  const handleSearchChange = (value: string) => {
    setSearchValue(value)
    setRequestedPage(1)
    setDisplayedPage(1)
  }

  const handleSortColumn = (columnKey: string) => {
    const next = nextActiveProjectsSort(sortBy, sortOrder, columnKey)
    setSortBy(next.sortBy)
    setSortOrder(next.sortOrder)
    setRequestedPage(1)
    setDisplayedPage(1)
  }

  const handleProjectDeleted = async () => {
    await queryClient.refetchQueries({
      queryKey: ['projects', 'active', organizationId],
    })
    await queryClient.refetchQueries({
      queryKey: ['projects', 'pinned', organizationId],
    })
  }

  const handleReload = () => {
    void queryClient.refetchQueries({
      queryKey: ['projects', 'active', organizationId],
    })
    void queryClient.refetchQueries({
      queryKey: ['projects', 'pinned', organizationId],
    })
  }

  return (
    <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
      <PartnerProjectsPaneHeader
        searchValue={searchValue}
        onSearchChange={handleSearchChange}
        filterMap={filterMap}
        filtersOpen={filtersOpen}
        onFiltersOpenChange={setFiltersOpen}
        onRemoveFilter={removeFilter}
        onClearAll={clearAll}
        onApplyFilter={applyFilter}
        onApplyQuery={applyQuery}
        teamId={organizationId}
        onReload={handleReload}
        isReloading={requestedQuery.isFetching || displayedQuery.isFetching}
        actions={
          createDisabledTooltip ? (
            <TooltipProvider delayDuration={0}>
              <Tooltip>
                <TooltipTrigger asChild>
                  <span className="inline-flex">
                    <Button
                      size="sm"
                      variant="outline"
                      className={PARTNER_PANE_HEADER_BUTTON_CLASSNAME}
                      disabled
                      {...analyticsAttrs('handoff-project')}
                    >
                      <Import className="h-3.5 w-3.5" />
                      {t('Handoff project')}
                    </Button>
                  </span>
                </TooltipTrigger>
                <TooltipContent className="max-w-xs text-xs">
                  {createDisabledTooltip}
                </TooltipContent>
              </Tooltip>
            </TooltipProvider>
          ) : (
            <Button
              size="sm"
              variant="outline"
              className={PARTNER_PANE_HEADER_BUTTON_CLASSNAME}
              onClick={() => {
                setHandoffProjectId(undefined)
                setTransferOpen(true)
              }}
              {...analyticsAttrs('handoff-project')}
            >
              <Import className="h-3.5 w-3.5" />
              {t('Handoff project')}
            </Button>
          )
        }
        primaryAction={
          createDisabledTooltip ? (
            <TooltipProvider delayDuration={0}>
              <Tooltip>
                <TooltipTrigger asChild>
                  <span className="inline-flex">
                    <Button
                      size="sm"
                      variant="brandCta"
                      className={PARTNER_PANE_HEADER_BUTTON_CLASSNAME}
                      disabled
                      {...analyticsAttrs('create-project')}
                    >
                      {t('Create project')}
                    </Button>
                  </span>
                </TooltipTrigger>
                <TooltipContent className="max-w-xs text-xs">
                  {createDisabledTooltip}
                </TooltipContent>
              </Tooltip>
            </TooltipProvider>
          ) : (
            <Button
              size="sm"
              variant="brandCta"
              className={PARTNER_PANE_HEADER_BUTTON_CLASSNAME}
              onClick={() => setCreateOpen(true)}
              {...analyticsAttrs('create-project')}
            >
              {t('Create project')}
            </Button>
          )
        }
      />
      {isLoading ? (
        <div className="min-h-0 flex-1 overflow-y-auto p-4">
          <p className="text-[13px] text-muted-foreground">
            {t('Loading projects...')}
          </p>
        </div>
      ) : projects.length === 0 && pinnedProjects.length === 0 ? (
        <div className="min-h-0 flex-1 overflow-y-auto p-4">
          <EmptyState
            icon={Boxes}
            title={listHasFilters ? undefined : 'No projects yet'}
            description={
              listHasFilters
                ? undefined
                : 'Create a project in the development env and hand it off here, or open the client console.'
            }
            isEmpty={!listHasFilters}
            hasFilters={listHasFilters}
            variant="card"
            action={
              !listHasFilters && canCreate ? (
                <div className="flex items-center gap-2">
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => {
                      setHandoffProjectId(undefined)
                      setTransferOpen(true)
                    }}
                  >
                    {t('Handoff project')}
                  </Button>
                  <Button
                    size="sm"
                    variant="brandCta"
                    onClick={() => setCreateOpen(true)}
                  >
                    {t('Create project')}
                  </Button>
                </div>
              ) : undefined
            }
          />
        </div>
      ) : (
        <PartnerProjectsSpreadsheet
          projects={projects}
          pinnedProjects={pinnedProjects}
          showSettingsTab={showSettingsTab}
          canDeleteProject={canCreate}
          onProjectDeleted={handleProjectDeleted}
          onHandoff={
            canCreate
              ? (projectId) => {
                  setHandoffProjectId(projectId)
                  setTransferOpen(true)
                }
              : undefined
          }
          handoffIcon={Import}
          showUsage={showProjectUsageCharts}
          projectRequestsUsageById={projectRequestsUsageById}
          projectBandwidthUsageById={projectBandwidthUsageById}
          projectExecutionsUsageById={projectExecutionsUsageById}
          projectStorageUsageById={projectStorageUsageById}
          projectComputeUsageById={projectComputeUsageById}
          projectAuthMauUsageById={projectAuthMauUsageById}
          sortBy={sortBy}
          sortOrder={sortOrder}
          onSortColumn={handleSortColumn}
          canPinProjects={canPin}
          pinnedIds={pinnedIds}
          onPinProject={handlePinProject}
          isPinPending={isPinPending}
        />
      )}
      {showPagination ? (
        <PartnerListPaginationFooter
          currentPage={displayedPage}
          totalItems={total}
          pageSize={pageSize}
          onPageChange={setRequestedPage}
          onPageSizeChange={(size) => {
            setRequestedPage(1)
            setDisplayedPage(1)
            setPageSize(size)
          }}
          itemLabel={t('projects')}
        />
      ) : null}
      <CreateProjectDialog
        open={createOpen}
        onOpenChange={setCreateOpen}
        teamId={organizationId}
        organizationPlan={plan}
        currentProjectsCount={displayedQuery.data?.total ?? projects.length}
        openCreatedInNewTab
      />
      <TransferProject
        open={transferOpen}
        onOpenChange={(open) => {
          setTransferOpen(open)
          if (!open) setHandoffProjectId(undefined)
        }}
        partnerId={partnerId}
        targetClientId={organizationId}
        initialResourceId={handoffProjectId}
      />
    </div>
  )
}

function ClientBillingSection({
  organizationId,
}: {
  organizationId: string
}) {
  const t = useT()
  const { features } = useConsoleProfile()
  const { access, isLoading: scopesLoading } = useOrganizationScopes(
    organizationId,
    undefined,
    { projectId: null },
  )
  const canShowBilling = canShowOrgBillingNav(access, features)

  if (!canShowBilling && !(features.orgRoles && scopesLoading)) {
    return (
      <EmptyState
        icon={CreditCard}
        title={t('Billing')}
        description={
          features.billing
            ? t("You don't have permission to view billing.")
            : t('Billing is not available on this console profile.')
        }
        isEmpty
        variant="card"
      />
    )
  }

  return (
    <SettingsSearchProviderLocal>
      <BillingTab organizationId={organizationId} />
    </SettingsSearchProviderLocal>
  )
}

function ClientComplianceSection({
  organizationId,
}: {
  organizationId: string
}) {
  const t = useT()
  const { features } = useConsoleProfile()
  const { access, isLoading: scopesLoading } = useOrganizationScopes(
    organizationId,
  )
  const canShowCompliance =
    features.compliance && canAccessOrgSettingsCompliance(access)

  if (features.orgRoles && scopesLoading) {
    return null
  }

  if (!canShowCompliance) {
    return (
      <EmptyState
        icon={ShieldCheck}
        title={t('Compliance')}
        description={
          features.compliance
            ? t("You don't have permission to view compliance.")
            : t('Compliance is not available on this console profile.')
        }
        isEmpty
        variant="card"
      />
    )
  }

  return (
    <SettingsSearchProviderLocal>
      <ComplianceTab organizationId={organizationId} />
    </SettingsSearchProviderLocal>
  )
}

function ClientAddonsList({ organizationId }: { organizationId: string }) {
  const { data, isLoading } = useQuery(
    organizationAddonsQueryOptions(organizationId),
  )
  const addons = data?.addons ?? []
  if (isLoading) {
    return <p className="text-[13px] text-muted-foreground">Loading addons…</p>
  }
  if (addons.length === 0) {
    return (
      <EmptyState
        icon={Plus}
        title="No addons"
        description="Manage extra resources from the client organization billing page."
        isEmpty
        variant="card"
      />
    )
  }
  return (
    <ul className="space-y-0.5">
      {addons.map((addon) => (
        <li
          key={addon.$id || addon.key}
          className={cn(
            apiNavItemClassName(false),
            'flex cursor-default items-center gap-2',
          )}
        >
          <span className="truncate">{addon.key || addon.$id}</span>
        </li>
      ))}
    </ul>
  )
}

