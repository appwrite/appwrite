import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { useT } from '@/lib/i18n/translate'
import { getDowngradePlanLimits } from '@/lib/billing/downgrade-plan-limits'
import {
  deleteDowngradeDomains,
  deleteDowngradeMemberships,
} from '@/lib/billing/delete-downgrade-org-resources'
import { fetchOrganizationDomains } from '@/lib/react-query/hooks/domains'
import { fetchOrganizationProjects } from '@/lib/react-query/hooks/organizations'
import { deleteProject } from '@/lib/react-query/hooks/projects'
import { fetchOrganizationMemberships } from '@/lib/react-query/hooks/teams'
import { toast } from 'sonner'
import { ConfirmDowngradeDeletes } from './ConfirmDowngradeDeletes'
import { DowngradeImpactSummary } from './DowngradeImpactSummary'
import type { ProjectResourceImpact } from './DowngradeImpactSummary'
import { DowngradeLimitSelection } from './DowngradeLimitSelection'
import { DowngradeProjectSelection } from './DowngradeProjectSelection'
import {
  DowngradeResourceValidation,
  type DowngradeResourceValidationHandle,
} from './DowngradeResourceValidation'
import type { DowngradeResourceImpact } from '@/lib/billing/downgrade-plan-limits'
import {
  getOrganizationLimits,
  type PlanChangeLimits,
} from '@/lib/billing/plan-change-compliance'
import type { DeletedOrganizationImpact } from '@/lib/billing/fetch-deleted-org-impact'

const DOWNGRADE_SELECTION_PAGE_SIZE = 5
const DOWNGRADE_DELETE_PAGE_SIZE = 100

export type DowngradeValidationHandle = {
  isValid: () => boolean
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

async function fetchAllDowngradeProjects(organizationId: string) {
  const all: Models.Project[] = []
  let page = 0
  let total = 0

  do {
    const data = await fetchOrganizationProjects(
      organizationId,
      page,
      DOWNGRADE_DELETE_PAGE_SIZE,
    )
    all.push(...(data.projects ?? []))
    total = data.total ?? all.length
    page += 1
  } while (all.length < total)

  return all
}

async function fetchAllDowngradeMemberships(organizationId: string) {
  const all: Models.Membership[] = []
  let page = 0
  let total = 0

  do {
    const data = await fetchOrganizationMemberships(
      organizationId,
      page,
      DOWNGRADE_DELETE_PAGE_SIZE,
    )
    all.push(...((data.memberships ?? []) as Models.Membership[]))
    total = data.total ?? all.length
    page += 1
  } while (all.length < total)

  return all
}

async function fetchAllDowngradeDomains(organizationId: string) {
  const all: Models.Domain[] = []
  let page = 0
  let total = 0

  do {
    const data = await fetchOrganizationDomains(
      organizationId,
      page,
      DOWNGRADE_DELETE_PAGE_SIZE,
    )
    all.push(...((data.domains ?? []) as Models.Domain[]))
    total = data.total ?? all.length
    page += 1
  } while (all.length < total)

  return all
}

function withinLimit(total: number, limit: number | null) {
  return limit === null || total <= limit
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
  const queryClient = useQueryClient()
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
  const [deletingKind, setDeletingKind] = useState<OrgDeleteKind | null>(null)

  const {
    data: remainingProjectsData,
    isLoading: remainingProjectsLoading,
  } = useQuery({
    queryKey: [
      'projects',
      'organization',
      organizationId,
      'downgrade-all',
    ],
    queryFn: () => fetchAllDowngradeProjects(organizationId),
    enabled: !!organizationId,
    staleTime: 30_000,
  })

  const remainingProjects = remainingProjectsData ?? projects
  const remainingProjectsTotal =
    remainingProjectsData?.length ?? projectsTotal

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

  useEffect(() => {
    setSelectedProjectIds(new Set())
    setSelectedMemberIds(new Set())
    setSelectedDomainIds(new Set())
  }, [organizationId])

  const orgSelectionsLoading =
    remainingProjectsLoading ||
    (needsProjectSelection && projectsLoading) ||
    (needsMemberSelection && membershipsLoading) ||
    (needsDomainSelection && domainsLoading)

  const orgWithinLimits =
    !orgSelectionsLoading &&
    withinLimit(currentProjectsTotal, projectsLimit) &&
    withinLimit(membershipsTotal, membersLimit) &&
    withinLimit(domainsTotal, domainsLimit)

  const resourceRef = useRef<DowngradeResourceValidationHandle | null>(null)
  const resourceValidRef = useRef(false)
  const [resourceBlockReason, setResourceBlockReason] = useState<string | null>(
    null,
  )
  const [resourceImpact, setResourceImpact] = useState<DowngradeResourceImpact>(
    {},
  )
  const [projectResourceImpacts, setProjectResourceImpacts] = useState<
    ProjectResourceImpact[]
  >([])
  const [resourceImpactLoading, setResourceImpactLoading] = useState(false)

  const handleResourceImpactChange = useCallback(
    (
      impact: DowngradeResourceImpact,
      loading: boolean,
      projectImpacts: ProjectResourceImpact[],
    ) => {
      setResourceImpact((prev) => {
        if (JSON.stringify(prev) === JSON.stringify(impact)) return prev
        return impact
      })
      setProjectResourceImpacts((prev) => {
        if (JSON.stringify(prev) === JSON.stringify(projectImpacts)) return prev
        return projectImpacts
      })
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

  const refreshAfterOrgDeletes = useCallback(async () => {
    await Promise.all([
      queryClient.invalidateQueries({
        queryKey: ['projects', 'organization', organizationId],
      }),
      queryClient.invalidateQueries({
        queryKey: ['memberships', 'organization', organizationId],
      }),
      queryClient.invalidateQueries({
        queryKey: ['domains', 'organization', organizationId],
      }),
      queryClient.invalidateQueries({
        queryKey: ['plan-estimation', organizationId],
      }),
      queryClient.invalidateQueries({
        queryKey: ['downgrade-resources'],
      }),
    ])
  }, [organizationId, queryClient])

  const confirmOrgDeletes = useCallback(async () => {
    if (!pendingDeleteKind) return
    const kind = pendingDeleteKind
    setDeletingKind(kind)

    try {
      if (kind === 'projects') {
        const ids = Array.from(selectedProjectIds)
        const allProjects = await fetchAllDowngradeProjects(organizationId)
        const toDelete = allProjects.filter((project) =>
          selectedProjectIds.has(project.$id),
        )
        for (const project of toDelete) {
          await deleteProject(project.$id, project.region)
        }
        setSelectedProjectIds((prev) => {
          const next = new Set(prev)
          for (const id of ids) next.delete(id)
          return next
        })
      }

      if (kind === 'members') {
        const allMemberships = await fetchAllDowngradeMemberships(organizationId)
        const toDelete = allMemberships
          .filter((membership) => selectedMemberIds.has(membership.$id))
          .map((membership) => membership.$id)
        const keepIds = allMemberships
          .filter((membership) => !selectedMemberIds.has(membership.$id))
          .map((membership) => membership.$id)
        await deleteDowngradeMemberships(organizationId, toDelete, keepIds)
        setSelectedMemberIds(new Set())
      }

      if (kind === 'domains') {
        const allDomains = await fetchAllDowngradeDomains(organizationId)
        const toDelete = allDomains
          .filter((domain) => selectedDomainIds.has(domain.$id))
          .map((domain) => domain.$id)
        const keepIds = allDomains
          .filter((domain) => !selectedDomainIds.has(domain.$id))
          .map((domain) => domain.$id)
        await deleteDowngradeDomains(organizationId, toDelete, keepIds)
        setSelectedDomainIds(new Set())
      }

      setPendingDeleteKind(null)
      await refreshAfterOrgDeletes()
    } catch (error) {
      toast.error(
        error instanceof Error
          ? error.message
          : t('Failed to delete selected items.'),
      )
    } finally {
      setDeletingKind(null)
    }
  }, [
    organizationId,
    pendingDeleteKind,
    refreshAfterOrgDeletes,
    selectedDomainIds,
    selectedMemberIds,
    selectedProjectIds,
    t,
  ])

  const blockReason = useMemo(() => {
    if (orgSelectionsLoading) return t('Loading organization resources...')

    if (!withinLimit(currentProjectsTotal, projectsLimit) && projectsLimit !== null) {
      return `${t('Delete at least')} ${currentProjectsTotal - projectsLimit} ${
        currentProjectsTotal - projectsLimit === 1 ? t('project') : t('projects')
      } ${t('to fit the selected plan.')}`
    }
    if (!withinLimit(membershipsTotal, membersLimit) && membersLimit !== null) {
      return `${t('Delete at least')} ${membershipsTotal - membersLimit} ${
        membershipsTotal - membersLimit === 1 ? t('member') : t('members')
      } ${t('to fit the selected plan.')}`
    }
    if (!withinLimit(domainsTotal, domainsLimit) && domainsLimit !== null) {
      return `${t('Delete at least')} ${domainsTotal - domainsLimit} ${
        domainsTotal - domainsLimit === 1 ? t('domain') : t('domains')
      } ${t('to fit the selected plan.')}`
    }
    if (remainingProjects.length > 0 && !resourceValidRef.current) {
      return resourceBlockReason
        ? t(resourceBlockReason)
        : t('Finish deleting project resources that exceed the selected plan.')
    }
    return null
  }, [
    t,
    orgSelectionsLoading,
    currentProjectsTotal,
    projectsLimit,
    membershipsTotal,
    membersLimit,
    domainsTotal,
    domainsLimit,
    remainingProjects.length,
    resourceBlockReason,
  ])

  const blockReasonRef = useRef<string | null>(null)
  blockReasonRef.current = blockReason

  const syncValidity = useCallback(() => {
    const resourceValid =
      remainingProjects.length === 0 || resourceValidRef.current
    const valid = orgWithinLimits && resourceValid
    onValidityChange?.(valid, valid ? null : blockReasonRef.current)
  }, [onValidityChange, orgWithinLimits, remainingProjects.length])

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
  const remainingProjectsLengthRef = useRef(remainingProjects.length)

  useEffect(() => {
    onRefRef.current = onRef
  }, [onRef])

  orgWithinLimitsRef.current = orgWithinLimits
  remainingProjectsLengthRef.current = remainingProjects.length

  useEffect(() => {
    onRefRef.current({
      isValid: () => {
        const resourceValid =
          remainingProjectsLengthRef.current === 0 || resourceValidRef.current
        return orgWithinLimitsRef.current && resourceValid
      },
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

  const hasOrgLevelSelections =
    needsProjectSelection || needsMemberSelection || needsDomainSelection

  const showProjectResourceValidation = remainingProjects.length > 0

  const pendingCount =
    pendingDeleteKind === 'projects'
      ? selectedProjectIds.size
      : pendingDeleteKind === 'members'
        ? selectedMemberIds.size
        : pendingDeleteKind === 'domains'
          ? selectedDomainIds.size
          : 0

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
              onToggleProject={toggleProject}
              onDeleteSelected={() => setPendingDeleteKind('projects')}
              onPageChange={setProjectPage}
              loading={projectsLoading && !projectPageData}
              deleting={deletingKind === 'projects'}
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
              onToggle={toggleMember}
              onDeleteSelected={() => setPendingDeleteKind('members')}
              onPageChange={setMemberPage}
              loading={membershipsLoading && !membershipsData}
              deleting={deletingKind === 'members'}
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
              onToggle={toggleDomain}
              onDeleteSelected={() => setPendingDeleteKind('domains')}
              onPageChange={setDomainPage}
              loading={domainsLoading && !domainsData}
              deleting={deletingKind === 'domains'}
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
            projects={remainingProjects}
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
          projectsLimit === null ? 0 : currentProjectsTotal - projectsLimit,
        )}
        membersOverage={Math.max(
          0,
          membersLimit === null ? 0 : membershipsTotal - membersLimit,
        )}
        domainsOverage={Math.max(
          0,
          domainsLimit === null ? 0 : domainsTotal - domainsLimit,
        )}
        resourceImpact={resourceImpact}
        keptProjectResourceImpacts={projectResourceImpacts}
        resourcesLoading={resourceImpactLoading || orgSelectionsLoading}
        deletedOrganizationImpact={deletedOrganizationImpact}
        deletedOrganizationLoading={deletedOrganizationLoading}
        expectDeletedOrganizationImpact={expectDeletedOrganizationImpact}
        keptOrganizationImpactReady={!orgSelectionsLoading}
      />

      <ConfirmDowngradeDeletes
        open={pendingDeleteKind !== null}
        onOpenChange={(open) => {
          if (!open && !deletingKind) setPendingDeleteKind(null)
        }}
        title={confirmTitle}
        count={pendingCount}
        confirming={deletingKind !== null}
        onConfirm={() => {
          void confirmOrgDeletes()
        }}
      />
    </div>
  )
}
