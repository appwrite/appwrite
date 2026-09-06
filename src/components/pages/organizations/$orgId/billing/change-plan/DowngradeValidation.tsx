import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { useT } from '@/lib/i18n/translate'
import { getDowngradePlanLimits } from '@/lib/billing/downgrade-plan-limits'
import { fetchAllDowngradeProjects } from '@/lib/billing/fetch-downgrade-org-resources'
import { fetchOrganizationDomains } from '@/lib/react-query/hooks/domains'
import { fetchOrganizationProjects } from '@/lib/react-query/hooks/organizations'
import { formatProjectNameForDisplay } from '@/lib/react-query/hooks/projects'
import { fetchOrganizationMemberships } from '@/lib/react-query/hooks/teams'
import { ConfirmDowngradeDeletes } from './ConfirmDowngradeDeletes'
import { DowngradeImpactSummary } from './DowngradeImpactSummary'
import type { ProjectResourceImpact } from './DowngradeImpactSummary'
import { DowngradeLimitSelection } from './DowngradeLimitSelection'
import { DowngradeProjectSelection } from './DowngradeProjectSelection'
import {
  DowngradeResourceValidation,
  type DowngradeResourceImpactPayload,
  type DowngradeResourceValidationHandle,
} from './DowngradeResourceValidation'
import type { DowngradeResourceImpact } from '@/lib/billing/downgrade-plan-limits'
import type { ResourcesToDelete } from '@/lib/billing/delete-downgrade-resources'
import {
  getNonCompliantProjectIds,
  getOrganizationLimits,
  type PlanChangeLimits,
} from '@/lib/billing/plan-change-compliance'
import type { DeletedOrganizationImpact } from '@/lib/billing/fetch-deleted-org-impact'

const DOWNGRADE_SELECTION_PAGE_SIZE = 5

export type PendingDowngradeDeletions = {
  projectIds: string[]
  membershipIds: string[]
  domainIds: string[]
  resources: ResourcesToDelete
}

export type DowngradeValidationHandle = {
  isValid: () => boolean
  getPendingDeletions: () => PendingDowngradeDeletions
}

export type { DowngradeResourceValidationHandle }

type OrgDeleteKind = 'projects' | 'members' | 'domains'

function findCurrentUserMembership(
  memberships: Models.Membership[],
  account: Models.User | undefined,
) {
  if (!account) return undefined

  return memberships.find(
    (membership) =>
      membership.userId === account.$id ||
      (!!account.email &&
        membership.userEmail?.toLowerCase() === account.email.toLowerCase()),
  )
}

function withinLimit(total: number, limit: number | null) {
  return limit === null || total <= limit
}

/** Labels for ids picked on a page the user may have navigated away from. */
function useSeenItemLabels(items: { id: string; label: string }[]) {
  const seen = useRef(new Map<string, string>())

  useEffect(() => {
    for (const item of items) seen.current.set(item.id, item.label)
  }, [items])

  return seen
}

function labelsForIds(ids: Set<string>, lookup: Map<string, string>) {
  return Array.from(ids, (id) => ({ id, label: lookup.get(id) ?? id }))
}

interface DowngradeValidationProps {
  organizationId: string
  organizationName?: string
  projects: Models.Project[]
  projectsTotal?: number
  targetPlan: Record<string, unknown> | null | undefined
  /** Server-side compliance for the target plan; authoritative over `targetPlan`. */
  planChangeLimits?: PlanChangeLimits | null
  planChangeLimitsLoading?: boolean
  onRef: (ref: DowngradeValidationHandle | null) => void
  onValidityChange?: (valid: boolean, reason?: string | null) => void
  deletedOrganizationImpact?: DeletedOrganizationImpact | null
  deletedOrganizationLoading?: boolean
  expectDeletedOrganizationImpact?: boolean
}

export function DowngradeValidation({
  organizationId,
  organizationName,
  projects,
  projectsTotal = projects.length,
  targetPlan,
  planChangeLimits = null,
  planChangeLimitsLoading = false,
  onRef,
  onValidityChange,
  deletedOrganizationImpact = null,
  deletedOrganizationLoading = false,
  expectDeletedOrganizationImpact = false,
}: DowngradeValidationProps) {
  const t = useT()
  const { account } = useAuth()
  const accountModel = account as Models.User | undefined
  const limits = useMemo(() => getDowngradePlanLimits(targetPlan), [targetPlan])

  const serverOrgLimits = useMemo(
    () => getOrganizationLimits(planChangeLimits),
    [planChangeLimits],
  )
  const projectsLimit = serverOrgLimits?.projects ?? limits.projects
  const membersLimit = serverOrgLimits?.members ?? limits.members
  const domainsLimit = serverOrgLimits?.domains ?? limits.domains

  const [projectPage, setProjectPage] = useState(1)
  const [memberPage, setMemberPage] = useState(1)
  const [domainPage, setDomainPage] = useState(1)
  const [displayedProjectPage, setDisplayedProjectPage] = useState(1)
  const [displayedMemberPage, setDisplayedMemberPage] = useState(1)
  const [displayedDomainPage, setDisplayedDomainPage] = useState(1)
  const [pendingDeleteKind, setPendingDeleteKind] =
    useState<OrgDeleteKind | null>(null)

  const { data: remainingProjectsData, isLoading: remainingProjectsLoading } =
    useQuery({
      queryKey: ['projects', 'organization', organizationId, 'downgrade-all'],
      queryFn: () => fetchAllDowngradeProjects(organizationId),
      enabled: !!organizationId,
      staleTime: 30_000,
    })

  const remainingProjects = remainingProjectsData ?? projects
  const remainingProjectsTotal = remainingProjectsData?.length ?? projectsTotal

  const needsProjectSelection =
    projectsLimit !== null && remainingProjectsTotal > projectsLimit

  const {
    data: projectPageData,
    isLoading: projectsLoading,
    isFetching: projectsFetching,
  } = useQuery({
    queryKey: [
      'projects',
      'organization',
      organizationId,
      'downgrade',
      projectPage,
      DOWNGRADE_SELECTION_PAGE_SIZE,
    ],
    queryFn: () =>
      fetchOrganizationProjects(
        organizationId,
        projectPage - 1,
        DOWNGRADE_SELECTION_PAGE_SIZE,
      ),
    enabled: !!organizationId && needsProjectSelection,
    placeholderData: keepPreviousData,
    staleTime: 30_000,
  })

  const {
    data: membershipsData,
    isLoading: membershipsLoading,
    isFetching: membershipsFetching,
  } = useQuery({
    queryKey: [
      'memberships',
      'organization',
      organizationId,
      'downgrade',
      memberPage,
      DOWNGRADE_SELECTION_PAGE_SIZE,
    ],
    queryFn: () =>
      fetchOrganizationMemberships(
        organizationId,
        memberPage - 1,
        DOWNGRADE_SELECTION_PAGE_SIZE,
      ),
    enabled: !!organizationId,
    placeholderData: keepPreviousData,
    staleTime: 30_000,
  })

  const {
    data: domainsData,
    isLoading: domainsLoading,
    isFetching: domainsFetching,
  } = useQuery({
    queryKey: [
      'domains',
      'organization',
      organizationId,
      'downgrade',
      domainPage,
      DOWNGRADE_SELECTION_PAGE_SIZE,
    ],
    queryFn: () =>
      fetchOrganizationDomains(
        organizationId,
        domainPage - 1,
        DOWNGRADE_SELECTION_PAGE_SIZE,
      ),
    enabled: !!organizationId,
    placeholderData: keepPreviousData,
    staleTime: 30_000,
  })

  useEffect(() => {
    if (projectsFetching || !projectPageData) return
    setDisplayedProjectPage(projectPage)
  }, [projectPage, projectPageData, projectsFetching])

  useEffect(() => {
    if (membershipsFetching || !membershipsData) return
    setDisplayedMemberPage(memberPage)
  }, [memberPage, membershipsData, membershipsFetching])

  useEffect(() => {
    if (domainsFetching || !domainsData) return
    setDisplayedDomainPage(domainPage)
  }, [domainPage, domainsData, domainsFetching])

  const projectPageProjects = projectPageData?.projects ?? []
  const currentProjects = needsProjectSelection
    ? projectPageProjects
    : remainingProjects
  const currentProjectsTotal = needsProjectSelection
    ? (projectPageData?.total ?? remainingProjectsTotal)
    : remainingProjectsTotal

  const memberships = (membershipsData?.memberships ??
    []) as Models.Membership[]
  const membershipsTotal = membershipsData?.total ?? memberships.length
  const domains = (domainsData?.domains ?? []) as Models.Domain[]
  const domainsTotal = domainsData?.total ?? domains.length

  const needsMemberSelection =
    membersLimit !== null &&
    (membershipsLoading || membershipsTotal > membersLimit)
  const needsDomainSelection =
    domainsLimit !== null && (domainsLoading || domainsTotal > domainsLimit)

  const pageCurrentUserMembership = useMemo(
    () => findCurrentUserMembership(memberships, accountModel),
    [memberships, accountModel],
  )
  const { data: currentUserMembershipData } = useQuery({
    queryKey: [
      'memberships',
      'organization',
      organizationId,
      'downgrade',
      'current-user',
      accountModel?.email,
    ],
    queryFn: () =>
      fetchOrganizationMemberships(
        organizationId,
        0,
        1,
        accountModel?.email ?? undefined,
      ),
    enabled: !!organizationId && !!accountModel?.email,
    staleTime: 30_000,
  })
  const queriedCurrentUserMembership = useMemo(
    () =>
      findCurrentUserMembership(
        (currentUserMembershipData?.memberships ?? []) as Models.Membership[],
        accountModel,
      ),
    [currentUserMembershipData?.memberships, accountModel],
  )
  const currentUserMembership =
    pageCurrentUserMembership ?? queriedCurrentUserMembership
  const lockedMembershipId = currentUserMembership?.$id ?? null

  const [selectedProjectIds, setSelectedProjectIds] = useState<Set<string>>(
    () => new Set(),
  )
  const [selectedMemberIds, setSelectedMemberIds] = useState<Set<string>>(
    () => new Set(),
  )
  const [selectedDomainIds, setSelectedDomainIds] = useState<Set<string>>(
    () => new Set(),
  )
  const [confirmedProjectIds, setConfirmedProjectIds] = useState<Set<string>>(
    () => new Set(),
  )
  const [confirmedMemberIds, setConfirmedMemberIds] = useState<Set<string>>(
    () => new Set(),
  )
  const [confirmedDomainIds, setConfirmedDomainIds] = useState<Set<string>>(
    () => new Set(),
  )

  useEffect(() => {
    setSelectedProjectIds(new Set())
    setSelectedMemberIds(new Set())
    setSelectedDomainIds(new Set())
    setConfirmedProjectIds(new Set())
    setConfirmedMemberIds(new Set())
    setConfirmedDomainIds(new Set())
  }, [organizationId])

  const orgSelectionsLoading =
    remainingProjectsLoading ||
    (needsProjectSelection && projectsLoading) ||
    (needsMemberSelection && membershipsLoading) ||
    (needsDomainSelection && domainsLoading)

  // Marked items are only deleted at submit, so every limit check runs against
  // what the organization will hold afterwards.
  const projectsAfterDeletes = currentProjectsTotal - confirmedProjectIds.size
  // A project marked for deletion takes its resources with it, so it drops out
  // of the per-project step entirely rather than gating the plan change.
  const keptProjects = useMemo(
    () =>
      remainingProjects.filter(
        (project) => !confirmedProjectIds.has(project.$id),
      ),
    [remainingProjects, confirmedProjectIds],
  )

  // Inspecting a project costs nine list calls, so only the projects the
  // estimation already flagged are inspected, and only once the user has
  // settled which survive. Submit stays blocked by the project overage for
  // exactly as long as that is pending.
  const projectSelectionSettled =
    !needsProjectSelection || confirmedProjectIds.size > 0
  const projectsToInspect = useMemo(() => {
    if (!planChangeLimits) return keptProjects
    const flagged = new Set(getNonCompliantProjectIds(planChangeLimits))
    return keptProjects.filter((project) => flagged.has(project.$id))
  }, [planChangeLimits, keptProjects])
  const resourceValidationApplies =
    projectSelectionSettled &&
    !planChangeLimitsLoading &&
    projectsToInspect.length > 0
  const membersAfterDeletes = membershipsTotal - confirmedMemberIds.size
  const domainsAfterDeletes = domainsTotal - confirmedDomainIds.size

  const orgWithinLimits =
    !orgSelectionsLoading &&
    withinLimit(projectsAfterDeletes, projectsLimit) &&
    withinLimit(membersAfterDeletes, membersLimit) &&
    withinLimit(domainsAfterDeletes, domainsLimit)

  const resourceRef = useRef<DowngradeResourceValidationHandle | null>(null)
  const resourceValidRef = useRef(false)
  const [resourceBlockReason, setResourceBlockReason] = useState<string | null>(
    null,
  )
  const [resourceImpact, setResourceImpact] = useState<DowngradeResourceImpact>(
    {},
  )
  const [stagedResourceImpact, setStagedResourceImpact] =
    useState<DowngradeResourceImpact>({})
  const [projectResourceImpacts, setProjectResourceImpacts] = useState<
    ProjectResourceImpact[]
  >([])
  const [stagedProjectResourceImpacts, setStagedProjectResourceImpacts] =
    useState<ProjectResourceImpact[]>([])
  const [resourceImpactLoading, setResourceImpactLoading] = useState(false)

  const handleResourceImpactChange = useCallback(
    ({
      impact,
      stagedImpact,
      loading,
      projectImpacts,
      stagedProjectImpacts,
    }: DowngradeResourceImpactPayload) => {
      const keepIfEqual =
        <T,>(next: T) =>
        (prev: T) =>
          JSON.stringify(prev) === JSON.stringify(next) ? prev : next

      setResourceImpact(keepIfEqual(impact))
      setStagedResourceImpact(keepIfEqual(stagedImpact))
      setProjectResourceImpacts(keepIfEqual(projectImpacts))
      setStagedProjectResourceImpacts(keepIfEqual(stagedProjectImpacts))
      setResourceImpactLoading((prev) => (prev === loading ? prev : loading))
    },
    [],
  )

  const toggleProject = useCallback((projectId: string) => {
    setSelectedProjectIds((prev) => {
      const next = new Set(prev)
      if (next.has(projectId)) next.delete(projectId)
      else next.add(projectId)
      return next
    })
  }, [])

  const toggleMember = useCallback(
    (membershipId: string) => {
      if (membershipId === lockedMembershipId) return
      setSelectedMemberIds((prev) => {
        const next = new Set(prev)
        if (next.has(membershipId)) next.delete(membershipId)
        else next.add(membershipId)
        return next
      })
    },
    [lockedMembershipId],
  )

  const toggleDomain = useCallback((domainId: string) => {
    setSelectedDomainIds((prev) => {
      const next = new Set(prev)
      if (next.has(domainId)) next.delete(domainId)
      else next.add(domainId)
      return next
    })
  }, [])

  const confirmOrgSelection = useCallback(() => {
    if (pendingDeleteKind === 'projects') {
      setConfirmedProjectIds(new Set(selectedProjectIds))
    }
    if (pendingDeleteKind === 'members') {
      setConfirmedMemberIds(new Set(selectedMemberIds))
    }
    if (pendingDeleteKind === 'domains') {
      setConfirmedDomainIds(new Set(selectedDomainIds))
    }
    setPendingDeleteKind(null)
  }, [
    pendingDeleteKind,
    selectedDomainIds,
    selectedMemberIds,
    selectedProjectIds,
  ])

  const editProjectSelection = useCallback(
    () => setConfirmedProjectIds(new Set()),
    [],
  )
  const editMemberSelection = useCallback(
    () => setConfirmedMemberIds(new Set()),
    [],
  )
  const editDomainSelection = useCallback(
    () => setConfirmedDomainIds(new Set()),
    [],
  )

  const blockReason = useMemo(() => {
    if (orgSelectionsLoading) return t('Loading organization resources...')

    if (
      !withinLimit(projectsAfterDeletes, projectsLimit) &&
      projectsLimit !== null
    ) {
      return `${t('Delete at least')} ${projectsAfterDeletes - projectsLimit} ${
        projectsAfterDeletes - projectsLimit === 1
          ? t('project')
          : t('projects')
      } ${t('to fit the selected plan.')}`
    }
    if (
      !withinLimit(membersAfterDeletes, membersLimit) &&
      membersLimit !== null
    ) {
      return `${t('Delete at least')} ${membersAfterDeletes - membersLimit} ${
        membersAfterDeletes - membersLimit === 1 ? t('member') : t('members')
      } ${t('to fit the selected plan.')}`
    }
    if (
      !withinLimit(domainsAfterDeletes, domainsLimit) &&
      domainsLimit !== null
    ) {
      return `${t('Delete at least')} ${domainsAfterDeletes - domainsLimit} ${
        domainsAfterDeletes - domainsLimit === 1 ? t('domain') : t('domains')
      } ${t('to fit the selected plan.')}`
    }
    if (resourceValidationApplies && !resourceValidRef.current) {
      return resourceBlockReason
        ? t(resourceBlockReason)
        : t('Finish deleting project resources that exceed the selected plan.')
    }
    return null
  }, [
    t,
    orgSelectionsLoading,
    projectsAfterDeletes,
    projectsLimit,
    membersAfterDeletes,
    membersLimit,
    domainsAfterDeletes,
    domainsLimit,
    resourceValidationApplies,
    resourceBlockReason,
  ])

  const blockReasonRef = useRef<string | null>(null)
  blockReasonRef.current = blockReason

  const syncValidity = useCallback(() => {
    const resourceValid = !resourceValidationApplies || resourceValidRef.current
    const valid = orgWithinLimits && resourceValid
    onValidityChange?.(valid, valid ? null : blockReasonRef.current)
  }, [onValidityChange, orgWithinLimits, resourceValidationApplies])

  const handleResourceRef = useCallback(
    (ref: DowngradeResourceValidationHandle | null) => {
      resourceRef.current = ref
    },
    [],
  )

  const handleResourceValidityChange = useCallback(
    (resourceValid: boolean, reason?: string | null) => {
      resourceValidRef.current = resourceValid
      setResourceBlockReason(reason ?? null)
      syncValidity()
    },
    [syncValidity],
  )

  useEffect(() => {
    syncValidity()
  }, [orgWithinLimits, blockReason, syncValidity])

  const onRefRef = useRef(onRef)
  const orgWithinLimitsRef = useRef(orgWithinLimits)
  const resourceValidationAppliesRef = useRef(resourceValidationApplies)
  const confirmedProjectIdsRef = useRef(confirmedProjectIds)
  const confirmedMemberIdsRef = useRef(confirmedMemberIds)
  const confirmedDomainIdsRef = useRef(confirmedDomainIds)

  useEffect(() => {
    onRefRef.current = onRef
  }, [onRef])

  orgWithinLimitsRef.current = orgWithinLimits
  resourceValidationAppliesRef.current = resourceValidationApplies
  confirmedProjectIdsRef.current = confirmedProjectIds
  confirmedMemberIdsRef.current = confirmedMemberIds
  confirmedDomainIdsRef.current = confirmedDomainIds

  useEffect(() => {
    onRefRef.current({
      isValid: () => {
        const resourceValid =
          !resourceValidationAppliesRef.current || resourceValidRef.current
        return orgWithinLimitsRef.current && resourceValid
      },
      getPendingDeletions: () => ({
        projectIds: Array.from(confirmedProjectIdsRef.current),
        membershipIds: Array.from(confirmedMemberIdsRef.current),
        domainIds: Array.from(confirmedDomainIdsRef.current),
        resources: resourceRef.current?.getPendingResourceDeletions() ?? {},
      }),
    })

    return () => {
      onRefRef.current(null)
    }
  }, [])

  const memberItems = useMemo(
    () =>
      memberships.map((membership) => ({
        id: membership.$id,
        label: membership.userName || membership.userEmail || membership.$id,
        description: membership.userEmail || undefined,
        locked: membership.$id === lockedMembershipId,
      })),
    [memberships, lockedMembershipId],
  )

  const domainItems = useMemo(
    () =>
      domains.map((domain) => ({
        id: domain.$id,
        label: domain.domain,
      })),
    [domains],
  )

  const projectLabelById = useMemo(() => {
    const map = new Map<string, string>()
    for (const project of remainingProjects) {
      map.set(project.$id, formatProjectNameForDisplay(project.name))
    }
    return map
  }, [remainingProjects])
  const memberLabels = useSeenItemLabels(memberItems)
  const domainLabels = useSeenItemLabels(domainItems)

  const hasOrgLevelSelections =
    needsProjectSelection || needsMemberSelection || needsDomainSelection

  const showProjectResourceValidation = resourceValidationApplies

  const pendingItems =
    pendingDeleteKind === 'projects'
      ? labelsForIds(selectedProjectIds, projectLabelById)
      : pendingDeleteKind === 'members'
        ? labelsForIds(selectedMemberIds, memberLabels.current)
        : pendingDeleteKind === 'domains'
          ? labelsForIds(selectedDomainIds, domainLabels.current)
          : []

  const confirmTitle =
    pendingDeleteKind === 'projects'
      ? t('Delete selected projects')
      : pendingDeleteKind === 'members'
        ? t('Delete selected members')
        : pendingDeleteKind === 'domains'
          ? t('Delete selected domains')
          : t('Delete selected')

  if (
    remainingProjects.length === 0 &&
    !needsMemberSelection &&
    !needsDomainSelection
  ) {
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Organization resources')}
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            {t('This organization has no projects.')}
          </p>
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-4">
      {hasOrgLevelSelections ? (
        <div className="space-y-4">
          <div>
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Organization resources')}
            </h3>
            <p className="text-[13px] text-muted-foreground mt-2">
              {t(
                'Compare organization usage with the selected plan. Mark extras to delete. Only selected items are removed after you confirm.',
              )}
            </p>
          </div>

          {needsProjectSelection && projectsLimit !== null ? (
            <DowngradeProjectSelection
              projects={currentProjects}
              total={currentProjectsTotal}
              page={displayedProjectPage}
              projectsLimit={projectsLimit}
              selectedProjectIds={selectedProjectIds}
              confirmedIds={confirmedProjectIds}
              confirmedLabels={labelsForIds(
                confirmedProjectIds,
                projectLabelById,
              ).map(({ label }) => label)}
              onToggleProject={toggleProject}
              onConfirmSelection={() => setPendingDeleteKind('projects')}
              onEditSelection={editProjectSelection}
              onPageChange={setProjectPage}
              loading={projectsLoading && !projectPageData}
              paginationDisabled={projectsFetching}
            />
          ) : null}

          {needsMemberSelection && membersLimit !== null ? (
            <DowngradeLimitSelection
              title={t('Members')}
              description={`${t('The selected plan allows')} ${membersLimit} ${
                membersLimit === 1 ? t('member') : t('members')
              }. ${t(
                'Mark the extras you want to remove. Only selected items are deleted after you confirm.',
              )}`}
              resourceLabel="members"
              limit={membersLimit}
              items={memberItems}
              total={membershipsTotal}
              page={displayedMemberPage}
              selectedIds={selectedMemberIds}
              confirmedIds={confirmedMemberIds}
              confirmedLabels={labelsForIds(
                confirmedMemberIds,
                memberLabels.current,
              ).map(({ label }) => label)}
              onToggle={toggleMember}
              onConfirmSelection={() => setPendingDeleteKind('members')}
              onEditSelection={editMemberSelection}
              onPageChange={setMemberPage}
              loading={membershipsLoading && !membershipsData}
              paginationDisabled={membershipsFetching}
            />
          ) : null}

          {needsDomainSelection && domainsLimit !== null ? (
            <DowngradeLimitSelection
              title={t('Domains')}
              description={`${t('The selected plan allows')} ${domainsLimit} ${
                domainsLimit === 1 ? t('domain') : t('domains')
              }. ${t(
                'Mark the extras you want to remove. Only selected items are deleted after you confirm.',
              )}`}
              resourceLabel="domains"
              limit={domainsLimit}
              items={domainItems}
              total={domainsTotal}
              page={displayedDomainPage}
              selectedIds={selectedDomainIds}
              confirmedIds={confirmedDomainIds}
              confirmedLabels={labelsForIds(
                confirmedDomainIds,
                domainLabels.current,
              ).map(({ label }) => label)}
              onToggle={toggleDomain}
              onConfirmSelection={() => setPendingDeleteKind('domains')}
              onEditSelection={editDomainSelection}
              onPageChange={setDomainPage}
              loading={domainsLoading && !domainsData}
              paginationDisabled={domainsFetching}
            />
          ) : null}
        </div>
      ) : null}

      {showProjectResourceValidation ? (
        <div className="space-y-4">
          <div>
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Project resources')}
            </h3>
            <p className="text-[13px] text-muted-foreground mt-2">
              {t(
                'Compare each remaining project with the selected plan. Mark extras to delete. Only selected items are removed after you confirm.',
              )}
            </p>
          </div>
          <DowngradeResourceValidation
            projects={projectsToInspect}
            targetPlan={targetPlan}
            planChangeLimits={planChangeLimits}
            planChangeLimitsLoading={planChangeLimitsLoading}
            onRef={handleResourceRef}
            onValidityChange={handleResourceValidityChange}
            onImpactChange={handleResourceImpactChange}
          />
        </div>
      ) : null}

      <DowngradeImpactSummary
        keptOrganizationName={organizationName}
        projectsOverage={Math.max(
          0,
          projectsLimit === null ? 0 : projectsAfterDeletes - projectsLimit,
        )}
        membersOverage={Math.max(
          0,
          membersLimit === null ? 0 : membersAfterDeletes - membersLimit,
        )}
        domainsOverage={Math.max(
          0,
          domainsLimit === null ? 0 : domainsAfterDeletes - domainsLimit,
        )}
        projectsStaged={confirmedProjectIds.size}
        membersStaged={confirmedMemberIds.size}
        domainsStaged={confirmedDomainIds.size}
        resourceImpact={resourceValidationApplies ? resourceImpact : {}}
        stagedResourceImpact={stagedResourceImpact}
        keptProjectResourceImpacts={
          resourceValidationApplies ? projectResourceImpacts : []
        }
        keptProjectStagedImpacts={stagedProjectResourceImpacts}
        resourcesLoading={
          (resourceValidationApplies && resourceImpactLoading) ||
          orgSelectionsLoading
        }
        deletedOrganizationImpact={deletedOrganizationImpact}
        deletedOrganizationLoading={deletedOrganizationLoading}
        expectDeletedOrganizationImpact={expectDeletedOrganizationImpact}
        keptOrganizationImpactReady={!orgSelectionsLoading}
      />

      <ConfirmDowngradeDeletes
        open={pendingDeleteKind !== null}
        onOpenChange={(open) => {
          if (!open) setPendingDeleteKind(null)
        }}
        title={confirmTitle}
        items={pendingItems}
        confirming={false}
        onConfirm={confirmOrgSelection}
      />
    </div>
  )
}
