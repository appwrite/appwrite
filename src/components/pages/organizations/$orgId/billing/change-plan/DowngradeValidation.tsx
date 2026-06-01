import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { getDowngradePlanLimits } from '@/lib/billing/downgrade-plan-limits'
import {
  deleteDowngradeDomains,
  deleteDowngradeMemberships,
} from '@/lib/billing/delete-downgrade-org-resources'
import { fetchOrganizationDomains } from '@/lib/react-query/hooks/domains'
import { fetchOrganizationMemberships } from '@/lib/react-query/hooks/teams'
import { DowngradeImpactSummary } from './DowngradeImpactSummary'
import { DowngradeLimitSelection } from './DowngradeLimitSelection'
import { DowngradeProjectSelection } from './DowngradeProjectSelection'
import {
  DowngradeResourceValidation,
  type DowngradeResourceValidationHandle,
} from './DowngradeResourceValidation'
import type { DowngradeResourceImpact } from '@/lib/billing/downgrade-plan-limits'
import type { DeletedOrganizationImpact } from '@/lib/billing/fetch-deleted-org-impact'

const DOWNGRADE_ORG_LIST_LIMIT = 1000

export type DowngradeValidationHandle = {
  getSelectedProjects: () => string[]
  getSelectedMembershipIds: () => string[]
  getSelectedDomainIds: () => string[]
  isValid: () => boolean
  deleteMarkedResources: () => Promise<void>
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

interface DowngradeValidationProps {
  organizationId: string
  organizationName?: string
  projects: Models.Project[]
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
  targetPlan,
  onRef,
  onValidityChange,
  deletedOrganizationImpact = null,
  deletedOrganizationLoading = false,
  expectDeletedOrganizationImpact = false,
}: DowngradeValidationProps) {
  const { account } = useAuth()
  const limits = useMemo(() => getDowngradePlanLimits(targetPlan), [targetPlan])
  const projectsLimit = limits.projects
  const membersLimit = limits.members
  const domainsLimit = limits.domains

  const needsProjectSelection =
    projectsLimit !== null && projects.length > projectsLimit

  const { data: membershipsData, isLoading: membershipsLoading } = useQuery({
    queryKey: [
      'memberships',
      'organization',
      organizationId,
      'downgrade',
      DOWNGRADE_ORG_LIST_LIMIT,
    ],
    queryFn: () =>
      fetchOrganizationMemberships(
        organizationId,
        0,
        DOWNGRADE_ORG_LIST_LIMIT,
      ),
    enabled: !!organizationId,
    staleTime: 30_000,
  })

  const { data: domainsData, isLoading: domainsLoading } = useQuery({
    queryKey: [
      'domains',
      'organization',
      organizationId,
      'downgrade',
      DOWNGRADE_ORG_LIST_LIMIT,
    ],
    queryFn: () =>
      fetchOrganizationDomains(
        organizationId,
        0,
        DOWNGRADE_ORG_LIST_LIMIT,
      ),
    enabled: !!organizationId,
    staleTime: 30_000,
  })

  const memberships = membershipsData?.memberships ?? []
  const membershipsTotal = membershipsData?.total ?? memberships.length
  const domains = domainsData?.domains ?? []
  const domainsTotal = domainsData?.total ?? domains.length

  const needsMemberSelection =
    membersLimit !== null && membershipsTotal > membersLimit
  const needsDomainSelection =
    domainsLimit !== null && domainsTotal > domainsLimit

  const currentUserMembership = useMemo(
    () => findCurrentUserMembership(memberships, account),
    [memberships, account],
  )
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

  useEffect(() => {
    if (!needsMemberSelection || !lockedMembershipId) return

    setSelectedMemberIds((prev) => {
      if (prev.has(lockedMembershipId)) return prev
      const next = new Set(prev)
      next.add(lockedMembershipId)
      return next
    })
  }, [needsMemberSelection, lockedMembershipId])

  const keptProjects = useMemo(() => {
    if (needsProjectSelection) {
      return projects.filter((project) => selectedProjectIds.has(project.$id))
    }
    return projects
  }, [needsProjectSelection, projects, selectedProjectIds])

  const keptMemberships = useMemo(() => {
    if (needsMemberSelection) {
      return memberships.filter((membership) =>
        selectedMemberIds.has(membership.$id),
      )
    }
    return memberships
  }, [needsMemberSelection, memberships, selectedMemberIds])

  const keptDomains = useMemo(() => {
    if (needsDomainSelection) {
      return domains.filter((domain) => selectedDomainIds.has(domain.$id))
    }
    return domains
  }, [needsDomainSelection, domains, selectedDomainIds])

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
    (needsMemberSelection && membershipsLoading) ||
    (needsDomainSelection && domainsLoading)

  const orgSelectionsValid =
    !orgSelectionsLoading && memberSelectionValid && domainSelectionValid

  const resourceRef = useRef<DowngradeResourceValidationHandle | null>(null)
  const resourceValidRef = useRef(false)
  const [resourceImpact, setResourceImpact] = useState<DowngradeResourceImpact>(
    {},
  )
  const [resourceImpactLoading, setResourceImpactLoading] = useState(false)

  const handleResourceImpactChange = useCallback(
    (impact: DowngradeResourceImpact, loading: boolean) => {
      setResourceImpact((prev) => {
        if (JSON.stringify(prev) === JSON.stringify(impact)) return prev
        return impact
      })
      setResourceImpactLoading((prev) => (prev === loading ? prev : loading))
    },
    [],
  )

  const toggleProject = useCallback(
    (projectId: string) => {
      if (!needsProjectSelection || projectsLimit === null) return

      setSelectedProjectIds((prev) => {
        const next = new Set(prev)
        if (next.has(projectId)) {
          next.delete(projectId)
        } else if (next.size < projectsLimit) {
          next.add(projectId)
        }
        return next
      })
    },
    [needsProjectSelection, projectsLimit],
  )

  const toggleMember = useCallback(
    (membershipId: string) => {
      if (!needsMemberSelection || membersLimit === null) return
      if (membershipId === lockedMembershipId) return

      setSelectedMemberIds((prev) => {
        const next = new Set(prev)
        if (next.has(membershipId)) {
          next.delete(membershipId)
        } else if (next.size < membersLimit) {
          next.add(membershipId)
        }
        return next
      })
    },
    [needsMemberSelection, membersLimit, lockedMembershipId],
  )

  const toggleDomain = useCallback(
    (domainId: string) => {
      if (!needsDomainSelection || domainsLimit === null) return

      setSelectedDomainIds((prev) => {
        const next = new Set(prev)
        if (next.has(domainId)) {
          next.delete(domainId)
        } else if (next.size < domainsLimit) {
          next.add(domainId)
        }
        return next
      })
    },
    [needsDomainSelection, domainsLimit],
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

  const deleteMarkedResources = useCallback(async () => {
    const membershipIdsToDelete = membershipsRef.current
      .filter((membership) => !selectedMemberIdsRef.current.has(membership.$id))
      .map((membership) => membership.$id)

    const domainIdsToDelete = domainsRef.current
      .filter((domain) => !selectedDomainIdsRef.current.has(domain.$id))
      .map((domain) => domain.$id)

    if (membershipIdsToDelete.length > 0) {
      await deleteDowngradeMemberships(
        organizationId,
        membershipIdsToDelete,
        Array.from(selectedMemberIdsRef.current),
      )
    }

    if (domainIdsToDelete.length > 0) {
      await deleteDowngradeDomains(
        organizationId,
        domainIdsToDelete,
        Array.from(selectedDomainIdsRef.current),
      )
    }

    await resourceRef.current?.deleteMarkedResources()
  }, [organizationId])

  const deleteMarkedResourcesRef = useRef(deleteMarkedResources)

  deleteMarkedResourcesRef.current = deleteMarkedResources

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
            Adjust resources for the target plan
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            This organization has no projects.
          </p>
        </div>
      </div>
    )
  }

  return (
    <div className="space-y-4">
      {needsProjectSelection && projectsLimit !== null ? (
        <DowngradeProjectSelection
          projects={projects}
          projectsLimit={projectsLimit}
          selectedProjectIds={selectedProjectIds}
          onToggleProject={toggleProject}
        />
      ) : null}

      {needsMemberSelection && membersLimit !== null ? (
        <DowngradeLimitSelection
          title="Choose members to keep"
          description={`The target plan allows ${membersLimit} member${membersLimit === 1 ? '' : 's'}. Unselected members will be removed from the organization.`}
          resourceLabel="members"
          limit={membersLimit}
          items={memberItems}
          selectedIds={selectedMemberIds}
          onToggle={toggleMember}
          loading={membershipsLoading}
        />
      ) : null}

      {needsDomainSelection && domainsLimit !== null ? (
        <DowngradeLimitSelection
          title="Choose domains to keep"
          description={`The target plan allows ${domainsLimit} domain${domainsLimit === 1 ? '' : 's'}. Unselected domains will be deleted.`}
          resourceLabel="domains"
          limit={domainsLimit}
          items={domainItems}
          selectedIds={selectedDomainIds}
          onToggle={toggleDomain}
          loading={domainsLoading}
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
              Adjust resources for the target plan
            </h3>
            <p className="text-[13px] text-muted-foreground mt-2">
              {orgSelectionsLoading
                ? 'Loading organization resources...'
                : needsProjectSelection && keptProjects.length === 0
                  ? 'Select projects above to review their resources.'
                  : 'Complete the selections above to review project resources.'}
            </p>
          </div>
        </div>
      ) : showProjectResourceValidation ? null : hasOrgLevelSelections &&
        orgSelectionReady &&
        keptProjects.length === 0 ? (
        <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
          <div className="px-6 py-4">
            <h3 className="text-[15px] font-semibold text-foreground">
              Adjust resources for the target plan
            </h3>
            <p className="text-[13px] text-muted-foreground mt-2">
              No projects to review. Confirm your member and domain selections
              above, then continue.
            </p>
          </div>
        </div>
      ) : null}
    </div>
  )
}
