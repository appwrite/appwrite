import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
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
import { fetchOrganizationMemberships } from '@/lib/react-query/hooks/teams'
import { DowngradeImpactSummary } from './DowngradeImpactSummary'
import type { ProjectResourceImpact } from './DowngradeImpactSummary'
import { DowngradeLimitSelection } from './DowngradeLimitSelection'
import { DowngradeProjectSelection } from './DowngradeProjectSelection'
import {
  DowngradeResourceValidation,
  type DowngradeResourceValidationHandle,
} from './DowngradeResourceValidation'
import type { DowngradeResourceImpact } from '@/lib/billing/downgrade-plan-limits'
import type { DeletedOrganizationImpact } from '@/lib/billing/fetch-deleted-org-impact'

const DOWNGRADE_SELECTION_PAGE_SIZE = 5
const DOWNGRADE_DELETE_PAGE_SIZE = 100

export type DowngradeValidationHandle = {
  getSelectedProjects: () => string[]
  getSelectedMembershipIds: () => string[]
  getSelectedDomainIds: () => string[]
  isValid: () => boolean
  deleteMarkedResources: () => Promise<void>
  deleteMarkedMemberships: () => Promise<void>
}

export type { DowngradeResourceValidationHandle }

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

interface DowngradeValidationProps {
  organizationId: string
  organizationName?: string
  projects: Models.Project[]
  projectsTotal?: number
  targetPlan: Record<string, unknown> | null | undefined
  onRef: (ref: DowngradeValidationHandle | null) => void
  onValidityChange?: (valid: boolean) => void
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
  const projectsLimit = limits.projects
  const membersLimit = limits.members
  const domainsLimit = limits.domains

  const needsProjectSelection =
    projectsLimit !== null && projectsTotal > projectsLimit

  const [projectPage, setProjectPage] = useState(1)
  const [memberPage, setMemberPage] = useState(1)
  const [domainPage, setDomainPage] = useState(1)
  const [displayedProjectPage, setDisplayedProjectPage] = useState(1)
  const [displayedMemberPage, setDisplayedMemberPage] = useState(1)
  const [displayedDomainPage, setDisplayedDomainPage] = useState(1)

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
  const currentProjects = needsProjectSelection ? projectPageProjects : projects
  const currentProjectsTotal = needsProjectSelection
    ? (projectPageData?.total ?? projectsTotal)
    : projectsTotal

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
    () => findCurrentUserMembership(
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
  const [selectedProjectsById, setSelectedProjectsById] = useState<
    Map<string, Models.Project>
  >(() => new Map())
  const [selectedMembershipsById, setSelectedMembershipsById] = useState<
    Map<string, Models.Membership>
  >(() => new Map())
  const [selectedDomainsById, setSelectedDomainsById] = useState<
    Map<string, Models.Domain>
  >(() => new Map())

  useEffect(() => {
    setSelectedProjectIds(new Set())
    setSelectedMemberIds(new Set())
    setSelectedDomainIds(new Set())
    setSelectedProjectsById(new Map())
    setSelectedMembershipsById(new Map())
    setSelectedDomainsById(new Map())
  }, [organizationId])

  useEffect(() => {
    if (!needsMemberSelection || !lockedMembershipId || !currentUserMembership)
      return

    setSelectedMemberIds((prev) => {
      if (prev.has(lockedMembershipId)) return prev
      const next = new Set(prev)
      next.add(lockedMembershipId)
      return next
    })
    setSelectedMembershipsById((prev) => {
      if (prev.has(lockedMembershipId)) return prev
      const next = new Map(prev)
      next.set(lockedMembershipId, currentUserMembership)
      return next
    })
  }, [needsMemberSelection, lockedMembershipId, currentUserMembership])

  const keptProjects = useMemo(() => {
    if (needsProjectSelection) {
      return Array.from(selectedProjectsById.values())
    }
    return projects
  }, [needsProjectSelection, projects, selectedProjectsById])

  const keptMemberships = useMemo(() => {
    if (needsMemberSelection) {
      return Array.from(selectedMembershipsById.values())
    }
    return memberships
  }, [needsMemberSelection, memberships, selectedMembershipsById])

  const keptDomains = useMemo(() => {
    if (needsDomainSelection) {
      return Array.from(selectedDomainsById.values())
    }
    return domains
  }, [needsDomainSelection, domains, selectedDomainsById])

  const projectSelectionValid =
    !needsProjectSelection ||
    (projectsLimit !== null && selectedProjectIds.size === projectsLimit)

  const memberSelectionValid =
    !needsMemberSelection ||
    (membersLimit !== null &&
      selectedMemberIds.size === membersLimit &&
      (!lockedMembershipId || selectedMemberIds.has(lockedMembershipId)))

  const domainSelectionValid =
    !needsDomainSelection ||
    (domainsLimit !== null && selectedDomainIds.size === domainsLimit)

  const orgSelectionsLoading =
    (needsProjectSelection && projectsLoading) ||
    (needsMemberSelection && membershipsLoading) ||
    (needsDomainSelection && domainsLoading)

  const orgSelectionsValid =
    !orgSelectionsLoading &&
    projectSelectionValid &&
    memberSelectionValid &&
    domainSelectionValid

  const resourceRef = useRef<DowngradeResourceValidationHandle | null>(null)
  const resourceValidRef = useRef(false)
  const [resourceImpact, setResourceImpact] = useState<DowngradeResourceImpact>(
    {},
  )
  const [keptProjectResourceImpacts, setKeptProjectResourceImpacts] = useState<
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
      setKeptProjectResourceImpacts((prev) => {
        if (JSON.stringify(prev) === JSON.stringify(projectImpacts)) return prev
        return projectImpacts
      })
      setResourceImpactLoading((prev) => (prev === loading ? prev : loading))
    },
    [],
  )

  const toggleProject = useCallback(
    (projectId: string) => {
      if (!needsProjectSelection || projectsLimit === null) return
      const project = currentProjects.find((item) => item.$id === projectId)

      setSelectedProjectIds((prev) => {
        const next = new Set(prev)
        if (next.has(projectId)) {
          next.delete(projectId)
        } else if (next.size < projectsLimit) {
          next.add(projectId)
        }
        return next
      })
      setSelectedProjectsById((prev) => {
        const next = new Map(prev)
        if (next.has(projectId)) {
          next.delete(projectId)
        } else if (next.size < projectsLimit && project) {
          next.set(projectId, project)
        }
        return next
      })
    },
    [currentProjects, needsProjectSelection, projectsLimit],
  )

  const toggleMember = useCallback(
    (membershipId: string) => {
      if (!needsMemberSelection || membersLimit === null) return
      if (membershipId === lockedMembershipId) return
      const membership = memberships.find((item) => item.$id === membershipId)

      setSelectedMemberIds((prev) => {
        const next = new Set(prev)
        if (next.has(membershipId)) {
          next.delete(membershipId)
        } else if (next.size < membersLimit) {
          next.add(membershipId)
        }
        return next
      })
      setSelectedMembershipsById((prev) => {
        const next = new Map(prev)
        if (next.has(membershipId)) {
          next.delete(membershipId)
        } else if (next.size < membersLimit && membership) {
          next.set(membershipId, membership)
        }
        return next
      })
    },
    [memberships, needsMemberSelection, membersLimit, lockedMembershipId],
  )

  const toggleDomain = useCallback(
    (domainId: string) => {
      if (!needsDomainSelection || domainsLimit === null) return
      const domain = domains.find((item) => item.$id === domainId)

      setSelectedDomainIds((prev) => {
        const next = new Set(prev)
        if (next.has(domainId)) {
          next.delete(domainId)
        } else if (next.size < domainsLimit) {
          next.add(domainId)
        }
        return next
      })
      setSelectedDomainsById((prev) => {
        const next = new Map(prev)
        if (next.has(domainId)) {
          next.delete(domainId)
        } else if (next.size < domainsLimit && domain) {
          next.set(domainId, domain)
        }
        return next
      })
    },
    [domains, needsDomainSelection, domainsLimit],
  )

  const syncValidity = useCallback(() => {
    const resourceValid =
      keptProjects.length === 0 || resourceValidRef.current
    onValidityChange?.(
      projectSelectionValid && orgSelectionsValid && resourceValid,
    )
  }, [onValidityChange, projectSelectionValid, orgSelectionsValid, keptProjects.length])

  const handleResourceRef = useCallback(
    (ref: DowngradeResourceValidationHandle | null) => {
      resourceRef.current = ref
    },
    [],
  )

  const handleResourceValidityChange = useCallback(
    (resourceValid: boolean) => {
      resourceValidRef.current = resourceValid
      syncValidity()
    },
    [syncValidity],
  )

  useEffect(() => {
    syncValidity()
  }, [projectSelectionValid, orgSelectionsValid, syncValidity])

  const onRefRef = useRef(onRef)
  const keptProjectsRef = useRef(keptProjects)
  const keptMembershipsRef = useRef(keptMemberships)
  const keptDomainsRef = useRef(keptDomains)
  const projectSelectionValidRef = useRef(projectSelectionValid)
  const orgSelectionsValidRef = useRef(orgSelectionsValid)
  const membershipsRef = useRef(memberships)
  const domainsRef = useRef(domains)
  const selectedMemberIdsRef = useRef(selectedMemberIds)
  const selectedDomainIdsRef = useRef(selectedDomainIds)

  useEffect(() => {
    onRefRef.current = onRef
  }, [onRef])

  keptProjectsRef.current = keptProjects
  keptMembershipsRef.current = keptMemberships
  keptDomainsRef.current = keptDomains
  projectSelectionValidRef.current = projectSelectionValid
  orgSelectionsValidRef.current = orgSelectionsValid
  membershipsRef.current = memberships
  domainsRef.current = domains
  selectedMemberIdsRef.current = selectedMemberIds
  selectedDomainIdsRef.current = selectedDomainIds

  const deleteMarkedMemberships = useCallback(async () => {
    if (!needsMemberSelection) return

    const allMemberships = await fetchAllDowngradeMemberships(organizationId)
    const membershipIdsToDelete = allMemberships
      .filter((membership) => !selectedMemberIdsRef.current.has(membership.$id))
      .map((membership) => membership.$id)

    if (membershipIdsToDelete.length > 0) {
      await deleteDowngradeMemberships(
        organizationId,
        membershipIdsToDelete,
        Array.from(selectedMemberIdsRef.current),
      )
    }
  }, [needsMemberSelection, organizationId])

  const deleteMarkedResources = useCallback(async () => {
    const allDomains = needsDomainSelection
      ? await fetchAllDowngradeDomains(organizationId)
      : []

    const domainIdsToDelete = allDomains
      .filter((domain) => !selectedDomainIdsRef.current.has(domain.$id))
      .map((domain) => domain.$id)

    if (domainIdsToDelete.length > 0) {
      await deleteDowngradeDomains(
        organizationId,
        domainIdsToDelete,
        Array.from(selectedDomainIdsRef.current),
      )
    }

    await resourceRef.current?.deleteMarkedResources()
  }, [needsDomainSelection, organizationId])

  const deleteMarkedResourcesRef = useRef(deleteMarkedResources)
  const deleteMarkedMembershipsRef = useRef(deleteMarkedMemberships)

  deleteMarkedResourcesRef.current = deleteMarkedResources
  deleteMarkedMembershipsRef.current = deleteMarkedMemberships

  useEffect(() => {
    onRefRef.current({
      getSelectedProjects: () =>
        keptProjectsRef.current.map((project) => project.$id),
      getSelectedMembershipIds: () =>
        keptMembershipsRef.current.map((membership) => membership.$id),
      getSelectedDomainIds: () =>
        keptDomainsRef.current.map((domain) => domain.$id),
      isValid: () => {
        const resourceValid =
          keptProjectsRef.current.length === 0 || resourceValidRef.current
        return (
          projectSelectionValidRef.current &&
          orgSelectionsValidRef.current &&
          resourceValid
        )
      },
      deleteMarkedResources: () => deleteMarkedResourcesRef.current(),
      deleteMarkedMemberships: () => deleteMarkedMembershipsRef.current(),
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

  const orgSelectionReady = orgSelectionsValid && !orgSelectionsLoading

  const showProjectResourceValidation =
    orgSelectionReady && keptProjects.length > 0

  const showImpactSummary =
    deletedOrganizationLoading ||
    !!deletedOrganizationImpact ||
    (orgSelectionReady && (hasOrgLevelSelections || keptProjects.length > 0))

  if (
    projects.length === 0 &&
    !needsMemberSelection &&
    !needsDomainSelection
  ) {
    return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Adjust resources for the target plan')}
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
      {needsProjectSelection && projectsLimit !== null ? (
        <DowngradeProjectSelection
          projects={currentProjects}
          total={currentProjectsTotal}
          page={displayedProjectPage}
          projectsLimit={projectsLimit}
          selectedProjectIds={selectedProjectIds}
          onToggleProject={toggleProject}
          onPageChange={setProjectPage}
          loading={projectsLoading && !projectPageData}
          paginationDisabled={projectsFetching}
        />
      ) : null}

      {needsMemberSelection && membersLimit !== null ? (
        <DowngradeLimitSelection
          title={t('Choose members to keep')}
          description={`${t('The target plan allows')} ${membersLimit} ${membersLimit === 1 ? t('member') : t('members')}. ${t('Unselected members will be removed from the organization.')}`}
          resourceLabel="members"
          limit={membersLimit}
          items={memberItems}
          total={membershipsTotal}
          page={displayedMemberPage}
          selectedIds={selectedMemberIds}
          onToggle={toggleMember}
          onPageChange={setMemberPage}
          loading={membershipsLoading && !membershipsData}
          paginationDisabled={membershipsFetching}
        />
      ) : null}

      {needsDomainSelection && domainsLimit !== null ? (
        <DowngradeLimitSelection
          title={t('Choose domains to keep')}
          description={`${t('The target plan allows')} ${domainsLimit} ${domainsLimit === 1 ? t('domain') : t('domains')}. ${t('Unselected domains will be deleted.')}`}
          resourceLabel="domains"
          limit={domainsLimit}
          items={domainItems}
          total={domainsTotal}
          page={displayedDomainPage}
          selectedIds={selectedDomainIds}
          onToggle={toggleDomain}
          onPageChange={setDomainPage}
          loading={domainsLoading && !domainsData}
          paginationDisabled={domainsFetching}
        />
      ) : null}

      {showProjectResourceValidation ? (
        <DowngradeResourceValidation
          projects={keptProjects}
          targetPlan={targetPlan}
          onRef={handleResourceRef}
          onValidityChange={handleResourceValidityChange}
          onImpactChange={handleResourceImpactChange}
        />
      ) : null}

      {showImpactSummary ? (
        <DowngradeImpactSummary
          keptOrganizationName={organizationName}
          allProjects={projects}
          keptProjects={keptProjects}
          allMemberships={memberships}
          keptMemberships={keptMemberships}
          allDomains={domains}
          keptDomains={keptDomains}
          resourceImpact={resourceImpact}
          keptProjectResourceImpacts={keptProjectResourceImpacts}
          resourcesLoading={resourceImpactLoading || orgSelectionsLoading}
          deletedOrganizationImpact={deletedOrganizationImpact}
          deletedOrganizationLoading={deletedOrganizationLoading}
          expectDeletedOrganizationImpact={expectDeletedOrganizationImpact}
          keptOrganizationImpactReady={orgSelectionReady}
        />
      ) : null}

      {hasOrgLevelSelections && !orgSelectionReady ? (
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Adjust resources for the target plan')}
            </h3>
            <p className="text-[13px] text-muted-foreground mt-2">
              {orgSelectionsLoading
                ? t('Loading organization resources...')
                : needsProjectSelection && keptProjects.length === 0
                  ? t('Select projects above to review their resources.')
                  : t('Complete the selections above to review project resources.')}
            </p>
          </div>
        </div>
      ) : showProjectResourceValidation ? null : hasOrgLevelSelections &&
        orgSelectionReady &&
        keptProjects.length === 0 ? (
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Adjust resources for the target plan')}
            </h3>
            <p className="text-[13px] text-muted-foreground mt-2">
              {t(
                'No projects to review. Confirm your member and domain selections above, then continue.',
              )}
            </p>
          </div>
        </div>
      ) : null}
    </div>
  )
}
