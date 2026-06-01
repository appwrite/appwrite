import type { Models } from '@appwrite.io/console'
import {
  DOWNGRADE_RESOURCE_TYPES,
  getTotalResourceDeletions,
  type DowngradeResourceImpact,
} from '@/lib/billing/downgrade-plan-limits'
import type { DeletedOrganizationImpact } from '@/lib/billing/fetch-deleted-org-impact'

interface DowngradeImpactSummaryProps {
  keptOrganizationName?: string
  allProjects: Models.Project[]
  keptProjects: Models.Project[]
  allMemberships?: Models.Membership[]
  keptMemberships?: Models.Membership[]
  allDomains?: Models.Domain[]
  keptDomains?: Models.Domain[]
  resourceImpact: DowngradeResourceImpact
  resourcesLoading?: boolean
  deletedOrganizationImpact?: DeletedOrganizationImpact | null
  deletedOrganizationLoading?: boolean
  /** When true, wait for deleted-org impact before showing the empty state. */
  expectDeletedOrganizationImpact?: boolean
  keptOrganizationImpactReady?: boolean
}

function ImpactListSection({
  title,
  items,
  getLabel,
}: {
  title: string
  items: { $id: string }[]
  getLabel: (item: { $id: string }) => string
}) {
  if (items.length === 0) return null

  return (
    <div className="space-y-2">
      <p className="text-[13px] font-medium text-foreground">{title}</p>
      <ul className="space-y-1.5">
        {items.map((item) => (
          <li
            key={item.$id}
            className="text-[13px] leading-normal text-red-600 dark:text-red-400"
          >
            {getLabel(item)}
          </li>
        ))}
      </ul>
    </div>
  )
}

function ResourceImpactSection({
  title,
  resourceImpact,
}: {
  title: string
  resourceImpact: DowngradeResourceImpact
}) {
  const resourceLines = DOWNGRADE_RESOURCE_TYPES.filter(
    ({ id }) => (resourceImpact[id] ?? 0) > 0,
  )

  if (resourceLines.length === 0) return null

  return (
    <div className="space-y-2">
      <p className="text-[13px] font-medium text-foreground">{title}</p>
      <ul className="space-y-1.5">
        {resourceLines.map(({ id, label }) => (
          <li
            key={id}
            className="flex items-start justify-between gap-3 text-[13px] leading-normal"
          >
            <span className="text-foreground">{label}</span>
            <span className="text-red-600 dark:text-red-400 shrink-0">
              {resourceImpact[id]} to delete
            </span>
          </li>
        ))}
      </ul>
    </div>
  )
}

function DeletedProjectResourceSection({
  projects,
}: {
  projects: DeletedOrganizationImpact['projectResourceImpacts']
}) {
  const projectsWithResources = projects.filter(
    ({ resourceImpact }) => getTotalResourceDeletions(resourceImpact) > 0,
  )

  if (projectsWithResources.length === 0) return null

  return (
    <div className="space-y-2">
      <p className="text-[13px] font-medium text-foreground">
        Resources by project
      </p>
      <div className="space-y-2">
        {projectsWithResources.map(
          ({ projectId, projectName, resourceImpact }) => {
            const resourceLines = DOWNGRADE_RESOURCE_TYPES.filter(
              ({ id }) => (resourceImpact[id] ?? 0) > 0,
            )

            return (
              <div
                key={projectId}
                className="rounded-lg border border-border bg-background/60 p-3"
              >
                <p className="truncate text-[13px] font-medium leading-normal text-foreground">
                  {projectName}
                </p>
                <ul className="mt-2 space-y-1.5">
                  {resourceLines.map(({ id, label }) => (
                    <li
                      key={id}
                      className="flex items-start justify-between gap-3 text-[13px] leading-normal"
                    >
                      <span className="text-foreground">{label}</span>
                      <span className="shrink-0 text-red-600 dark:text-red-400">
                        {resourceImpact[id]} to delete
                      </span>
                    </li>
                  ))}
                </ul>
              </div>
            )
          },
        )}
      </div>
    </div>
  )
}

export function DowngradeImpactSummary({
  keptOrganizationName,
  allProjects,
  keptProjects,
  allMemberships = [],
  keptMemberships = [],
  allDomains = [],
  keptDomains = [],
  resourceImpact,
  resourcesLoading = false,
  deletedOrganizationImpact = null,
  deletedOrganizationLoading = false,
  expectDeletedOrganizationImpact = false,
  keptOrganizationImpactReady = true,
}: DowngradeImpactSummaryProps) {
  const keptProjectIds = new Set(keptProjects.map((project) => project.$id))
  const projectsToDelete = allProjects.filter(
    (project) => !keptProjectIds.has(project.$id),
  )

  const keptMembershipIds = new Set(
    keptMemberships.map((membership) => membership.$id),
  )
  const membersToDelete = allMemberships.filter(
    (membership) => !keptMembershipIds.has(membership.$id),
  )

  const keptDomainIds = new Set(keptDomains.map((domain) => domain.$id))
  const domainsToDelete = allDomains.filter(
    (domain) => !keptDomainIds.has(domain.$id),
  )

  const keptResourceDeletions = getTotalResourceDeletions(resourceImpact)
  const deletedResourceDeletions = deletedOrganizationImpact
    ? getTotalResourceDeletions(deletedOrganizationImpact.resourceImpact)
    : 0

  const hasKeptOrgImpact =
    keptOrganizationImpactReady &&
    (projectsToDelete.length > 0 ||
      membersToDelete.length > 0 ||
      domainsToDelete.length > 0 ||
      keptResourceDeletions > 0)

  const hasDeletedOrgImpact = !!deletedOrganizationImpact
  const deletedSectionLoading =
    expectDeletedOrganizationImpact && deletedOrganizationLoading
  const keptSectionLoading = keptOrganizationImpactReady && resourcesLoading
  const hasImpact = hasKeptOrgImpact || hasDeletedOrgImpact

  const showDeletedSection = hasDeletedOrgImpact || deletedSectionLoading
  const showKeptSection = hasKeptOrgImpact || keptSectionLoading
  const showEmptyState =
    !hasImpact && !deletedSectionLoading && !keptSectionLoading

  const keptOrgLabel = keptOrganizationName ?? 'Organization being downgraded'

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          Downgrade impact
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          Summary of everything that will be deleted when you change plan.
        </p>
      </div>

      <div className="border-t border-border" />

      <div className="px-6 py-4 space-y-4">
        {showEmptyState ? (
          <p className="text-[13px] text-muted-foreground">
            No projects, members, domains, or resources will be deleted.
          </p>
        ) : (
          <>
            {showDeletedSection ? (
              deletedSectionLoading && !hasDeletedOrgImpact ? (
                <p className="text-[13px] text-muted-foreground">
                  Calculating impact for the organization that will be
                  removed...
                </p>
              ) : hasDeletedOrgImpact && deletedOrganizationImpact ? (
              <div className="space-y-4">
                <div>
                  <p className="text-[13px] font-semibold text-foreground">
                    {deletedOrganizationImpact.organizationName}
                  </p>
                  <p className="text-[13px] text-muted-foreground mt-1">
                    This entire organization will be deleted, including all of
                    its projects and resources.
                  </p>
                </div>

                <ImpactListSection
                  title={`Projects (${deletedOrganizationImpact.projects.length})`}
                  items={deletedOrganizationImpact.projects}
                  getLabel={(project) =>
                    (project as Models.Project).name || project.$id
                  }
                />

                <ImpactListSection
                  title={`Members (${deletedOrganizationImpact.memberships.length})`}
                  items={deletedOrganizationImpact.memberships}
                  getLabel={(membership) => {
                    const member = membership as Models.Membership
                    return member.userName || member.userEmail || member.$id
                  }}
                />

                <ImpactListSection
                  title={`Domains (${deletedOrganizationImpact.domains.length})`}
                  items={deletedOrganizationImpact.domains}
                  getLabel={(domain) =>
                    (domain as Models.Domain).domain || domain.$id
                  }
                />

                <ResourceImpactSection
                  title="Resources in all projects"
                  resourceImpact={deletedOrganizationImpact.resourceImpact}
                />

                <DeletedProjectResourceSection
                  projects={deletedOrganizationImpact.projectResourceImpacts ?? []}
                />
              </div>
              ) : null
            ) : null}

            {hasDeletedOrgImpact && (hasKeptOrgImpact || keptSectionLoading) ? (
              <div className="border-t border-border" />
            ) : null}

            {showKeptSection ? (
              keptSectionLoading && !hasKeptOrgImpact ? (
                <p className="text-[13px] text-muted-foreground">
                  Calculating impact for resources to remove...
                </p>
              ) : hasKeptOrgImpact ? (
              <div className="space-y-4">
                <div>
                  <p className="text-[13px] font-semibold text-foreground">
                    {keptOrgLabel}
                  </p>
                  <p className="text-[13px] text-muted-foreground mt-1">
                    Resources removed to fit the target plan limits.
                  </p>
                </div>

                <ImpactListSection
                  title={`Projects (${projectsToDelete.length})`}
                  items={projectsToDelete}
                  getLabel={(project) =>
                    (project as Models.Project).name || project.$id
                  }
                />

                <ImpactListSection
                  title={`Members (${membersToDelete.length})`}
                  items={membersToDelete}
                  getLabel={(membership) => {
                    const member = membership as Models.Membership
                    return member.userName || member.userEmail || member.$id
                  }}
                />

                <ImpactListSection
                  title={`Domains (${domainsToDelete.length})`}
                  items={domainsToDelete}
                  getLabel={(domain) =>
                    (domain as Models.Domain).domain || domain.$id
                  }
                />

                <ResourceImpactSection
                  title="Resources in kept projects"
                  resourceImpact={resourceImpact}
                />
              </div>
              ) : null
            ) : null}

            {hasImpact ? (
            <div className="border-t border-border pt-4">
              <p className="text-[13px] font-medium text-foreground">
                Total impact
              </p>
              <ul className="mt-2 space-y-1.5 text-[13px] text-muted-foreground">
                {hasDeletedOrgImpact && deletedOrganizationImpact ? (
                  <li>
                    <span className="font-medium text-foreground">
                      {deletedOrganizationImpact.organizationName}
                    </span>
                    : entire organization deleted (
                    {deletedOrganizationImpact.projects.length} project
                    {deletedOrganizationImpact.projects.length === 1 ? '' : 's'}
                    , {deletedOrganizationImpact.memberships.length} member
                    {deletedOrganizationImpact.memberships.length === 1
                      ? ''
                      : 's'}
                    , {deletedOrganizationImpact.domains.length} domain
                    {deletedOrganizationImpact.domains.length === 1 ? '' : 's'}
                    , {deletedResourceDeletions} resource
                    {deletedResourceDeletions === 1 ? '' : 's'})
                  </li>
                ) : null}
                {hasKeptOrgImpact ? (
                  <li>
                    <span className="font-medium text-foreground">
                      {keptOrgLabel}
                    </span>
                    : {projectsToDelete.length} project
                    {projectsToDelete.length === 1 ? '' : 's'},{' '}
                    {membersToDelete.length} member
                    {membersToDelete.length === 1 ? '' : 's'},{' '}
                    {domainsToDelete.length} domain
                    {domainsToDelete.length === 1 ? '' : 's'},{' '}
                    {keptResourceDeletions} resource
                    {keptResourceDeletions === 1 ? '' : 's'} removed
                  </li>
                ) : null}
              </ul>
            </div>
            ) : null}
          </>
        )}
      </div>
    </div>
  )
}
