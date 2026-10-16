import type { ReactNode } from 'react'
import {
  DOWNGRADE_RESOURCE_TYPES,
  getTotalResourceDeletions,
  type DowngradeResourceImpact,
} from '@/lib/billing/downgrade-plan-limits'
import type { DeletedOrganizationImpact } from '@/lib/billing/fetch-deleted-org-impact'
import { formatProjectNameForDisplay } from '@/lib/react-query/hooks/projects'
import { useT } from '@/lib/i18n/translate'

interface DowngradeImpactSummaryProps {
  keptOrganizationName?: string
  projectsOverage?: number
  membersOverage?: number
  domainsOverage?: number
  projectsStaged?: number
  membersStaged?: number
  domainsStaged?: number
  resourceImpact: DowngradeResourceImpact
  stagedResourceImpact?: DowngradeResourceImpact
  keptProjectResourceImpacts?: ProjectResourceImpact[]
  keptProjectStagedImpacts?: ProjectResourceImpact[]
  resourcesLoading?: boolean
  deletedOrganizationImpact?: DeletedOrganizationImpact | null
  deletedOrganizationLoading?: boolean
  /** When true, wait for deleted-org impact before showing the empty state. */
  expectDeletedOrganizationImpact?: boolean
  keptOrganizationImpactReady?: boolean
}

export type ProjectResourceImpact = {
  projectId: string
  projectName: string
  resourceImpact: DowngradeResourceImpact
}

/** Staged deletions read muted; only what is left over the limit reads red. */
function ImpactCounts({
  staged,
  overage,
}: {
  staged: number
  overage: number
}) {
  const t = useT()

  return (
    <span className="flex shrink-0 flex-wrap justify-end gap-x-2">
      {staged > 0 ? (
        <span className="text-muted-foreground">
          {staged} {t('will be deleted')}
        </span>
      ) : null}
      {overage > 0 ? (
        <span className="text-red-600 dark:text-red-400">
          {overage} {t('still over limit')}
        </span>
      ) : null}
    </span>
  )
}

function ResourceImpactSection({
  title,
  resourceImpact,
  stagedImpact,
}: {
  title: string
  resourceImpact: DowngradeResourceImpact
  stagedImpact?: DowngradeResourceImpact
}) {
  const t = useT()
  const resourceLines = DOWNGRADE_RESOURCE_TYPES.filter(
    ({ id }) => (resourceImpact[id] ?? 0) > 0 || (stagedImpact?.[id] ?? 0) > 0,
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
            <span className="text-foreground">{t(label)}</span>
            <ImpactCounts
              staged={stagedImpact?.[id] ?? 0}
              overage={resourceImpact[id] ?? 0}
            />
          </li>
        ))}
      </ul>
    </div>
  )
}

function ProjectResourceImpactSection({
  projects,
  stagedProjects = [],
}: {
  projects: ProjectResourceImpact[]
  stagedProjects?: ProjectResourceImpact[]
}) {
  const t = useT()
  const stagedByProjectId = new Map(
    stagedProjects.map(({ projectId, resourceImpact }) => [
      projectId,
      resourceImpact,
    ]),
  )
  const projectsWithResources = projects.filter(
    ({ projectId, resourceImpact }) =>
      getTotalResourceDeletions(resourceImpact) > 0 ||
      getTotalResourceDeletions(stagedByProjectId.get(projectId) ?? {}) > 0,
  )

  if (projectsWithResources.length === 0) return null

  return (
    <div className="space-y-2">
      <p className="text-[13px] font-medium text-foreground">
        {t('Resources by project')}
      </p>
      <div className="space-y-2">
        {projectsWithResources.map(
          ({ projectId, projectName, resourceImpact }) => {
            const stagedImpact = stagedByProjectId.get(projectId) ?? {}
            const resourceLines = DOWNGRADE_RESOURCE_TYPES.filter(
              ({ id }) =>
                (resourceImpact[id] ?? 0) > 0 || (stagedImpact[id] ?? 0) > 0,
            )

            return (
              <div
                key={projectId}
                className="rounded-lg border border-border bg-card/50 p-3"
              >
                <p
                  className="truncate text-[13px] font-medium leading-normal text-foreground"
                  title={projectName}
                >
                  {formatProjectNameForDisplay(projectName)}
                </p>
                <ul className="mt-2 space-y-1.5">
                  {resourceLines.map(({ id, label }) => (
                    <li
                      key={id}
                      className="flex items-start justify-between gap-3 text-[13px] leading-normal"
                    >
                      <span className="text-foreground">{t(label)}</span>
                      <ImpactCounts
                        staged={stagedImpact[id] ?? 0}
                        overage={resourceImpact[id] ?? 0}
                      />
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

function OrganizationImpactSection({
  name,
  description,
  children,
}: {
  name: string
  description: string
  children: ReactNode
}) {
  return (
    <div className="rounded-lg border border-border bg-background/60 p-4 space-y-4">
      <div>
        <p className="text-[13px] font-semibold leading-normal text-foreground">
          {name}
        </p>
        <p className="text-[13px] leading-normal text-muted-foreground mt-1">
          {description}
        </p>
      </div>
      {children}
    </div>
  )
}

function ImpactLine({
  label,
  overage,
  staged = 0,
}: {
  label: string
  overage: number
  staged?: number
}) {
  const t = useT()
  if (overage <= 0 && staged <= 0) return null

  return (
    <div className="space-y-1">
      {staged > 0 ? (
        <p className="text-[13px] leading-normal text-muted-foreground">
          {staged} {t(label)} {t('will be deleted')}
        </p>
      ) : null}
      {overage > 0 ? (
        <p className="text-[13px] leading-normal text-red-600 dark:text-red-400">
          {overage} {t(label)} {t('still over limit')}
        </p>
      ) : null}
    </div>
  )
}

export function DowngradeImpactSummary({
  keptOrganizationName,
  projectsOverage = 0,
  membersOverage = 0,
  domainsOverage = 0,
  projectsStaged = 0,
  membersStaged = 0,
  domainsStaged = 0,
  resourceImpact,
  stagedResourceImpact = {},
  keptProjectResourceImpacts = [],
  keptProjectStagedImpacts = [],
  resourcesLoading = false,
  deletedOrganizationImpact = null,
  deletedOrganizationLoading = false,
  expectDeletedOrganizationImpact = false,
  keptOrganizationImpactReady = true,
}: DowngradeImpactSummaryProps) {
  const t = useT()
  const keptResourceDeletions = getTotalResourceDeletions(resourceImpact)
  const stagedResourceDeletions =
    getTotalResourceDeletions(stagedResourceImpact)
  const deletedResourceDeletions = deletedOrganizationImpact
    ? getTotalResourceDeletions(deletedOrganizationImpact.resourceImpact)
    : 0

  const hasKeptOverage =
    projectsOverage > 0 ||
    membersOverage > 0 ||
    domainsOverage > 0 ||
    keptResourceDeletions > 0
  const hasKeptStaged =
    projectsStaged > 0 ||
    membersStaged > 0 ||
    domainsStaged > 0 ||
    stagedResourceDeletions > 0

  const hasKeptOrgImpact =
    keptOrganizationImpactReady && (hasKeptOverage || hasKeptStaged)

  const hasDeletedOrgImpact = !!deletedOrganizationImpact
  const deletedSectionLoading =
    expectDeletedOrganizationImpact && deletedOrganizationLoading
  const keptSectionLoading = keptOrganizationImpactReady && resourcesLoading
  const hasImpact = hasKeptOrgImpact || hasDeletedOrgImpact

  const showDeletedSection = hasDeletedOrgImpact || deletedSectionLoading
  const showKeptSection = hasKeptOrgImpact || keptSectionLoading
  const showEmptyState =
    !hasImpact && !deletedSectionLoading && !keptSectionLoading

  const keptOrgLabel = keptOrganizationName ?? t('Organization being downgraded')

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Downgrade impact')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t(
            'Remaining extras that still exceed the selected plan. Nothing else is deleted.',
          )}
        </p>
      </div>

      <div className="border-t border-border" />

      <div className="px-6 py-4 space-y-4">
        {showEmptyState ? (
          <p className="text-[13px] text-muted-foreground">
            {t('Usage fits the selected plan. No further deletions are required.')}
          </p>
        ) : (
          <>
            {showDeletedSection ? (
              deletedSectionLoading && !hasDeletedOrgImpact ? (
                <p className="text-[13px] text-muted-foreground">
                  {t(
                    'Calculating impact for the organization that will be removed...',
                  )}
                </p>
              ) : hasDeletedOrgImpact && deletedOrganizationImpact ? (
                <OrganizationImpactSection
                  name={deletedOrganizationImpact.organizationName}
                  description={t(
                    'This entire organization will be deleted, including all of its projects and resources.',
                  )}
                >
                  <p className="text-[13px] leading-normal text-red-600 dark:text-red-400">
                    {deletedOrganizationImpact.projects.length}{' '}
                    {deletedOrganizationImpact.projects.length === 1
                      ? t('project')
                      : t('projects')}
                    , {deletedOrganizationImpact.memberships.length}{' '}
                    {deletedOrganizationImpact.memberships.length === 1
                      ? t('member')
                      : t('members')}
                    , {deletedOrganizationImpact.domains.length}{' '}
                    {deletedOrganizationImpact.domains.length === 1
                      ? t('domain')
                      : t('domains')}
                  </p>

                  <ResourceImpactSection
                    title={t('Resources in all projects')}
                    resourceImpact={deletedOrganizationImpact.resourceImpact}
                  />

                  <ProjectResourceImpactSection
                    projects={
                      deletedOrganizationImpact.projectResourceImpacts ?? []
                    }
                  />
                </OrganizationImpactSection>
              ) : null
            ) : null}

            {hasDeletedOrgImpact && (hasKeptOrgImpact || keptSectionLoading) ? (
              <div className="border-t border-border" />
            ) : null}

            {showKeptSection ? (
              keptSectionLoading && !hasKeptOrgImpact ? (
                <p className="text-[13px] text-muted-foreground">
                  {t('Calculating remaining extras...')}
                </p>
              ) : hasKeptOrgImpact ? (
                <OrganizationImpactSection
                  name={keptOrgLabel}
                  description={t(
                    'Extras still over the selected plan. Delete only the items you mark.',
                  )}
                >
                  <ImpactLine
                    label="projects"
                    overage={projectsOverage}
                    staged={projectsStaged}
                  />
                  <ImpactLine
                    label="members"
                    overage={membersOverage}
                    staged={membersStaged}
                  />
                  <ImpactLine
                    label="domains"
                    overage={domainsOverage}
                    staged={domainsStaged}
                  />

                  <ResourceImpactSection
                    title={t('Project resources')}
                    resourceImpact={resourceImpact}
                    stagedImpact={stagedResourceImpact}
                  />

                  <ProjectResourceImpactSection
                    projects={keptProjectResourceImpacts}
                    stagedProjects={keptProjectStagedImpacts}
                  />
                </OrganizationImpactSection>
              ) : null
            ) : null}

            {hasImpact ? (
              <div className="border-t border-border pt-4">
                <p className="text-[13px] font-medium text-foreground">
                  {t('Total impact')}
                </p>
                <ul className="mt-2 space-y-1.5 text-[13px] text-muted-foreground">
                  {hasDeletedOrgImpact && deletedOrganizationImpact ? (
                    <li>
                      <span className="font-medium text-foreground">
                        {deletedOrganizationImpact.organizationName}
                      </span>
                      : {t('entire organization deleted')} (
                      {deletedOrganizationImpact.projects.length}{' '}
                      {deletedOrganizationImpact.projects.length === 1
                        ? t('project')
                        : t('projects')}
                      , {deletedOrganizationImpact.memberships.length}{' '}
                      {deletedOrganizationImpact.memberships.length === 1
                        ? t('member')
                        : t('members')}
                      , {deletedOrganizationImpact.domains.length}{' '}
                      {deletedOrganizationImpact.domains.length === 1
                        ? t('domain')
                        : t('domains')}
                      , {deletedResourceDeletions}{' '}
                      {deletedResourceDeletions === 1
                        ? t('resource')
                        : t('resources')}
                      )
                    </li>
                  ) : null}
                  {hasKeptStaged ? (
                    <li>
                      <span className="font-medium text-foreground">
                        {keptOrgLabel}
                      </span>
                      : {projectsStaged} {t('projects')}, {membersStaged}{' '}
                      {t('members')}, {domainsStaged} {t('domains')},{' '}
                      {stagedResourceDeletions}{' '}
                      {stagedResourceDeletions === 1
                        ? t('resource')
                        : t('resources')}{' '}
                      {t('will be deleted')}
                    </li>
                  ) : null}
                  {hasKeptOverage ? (
                    <li>
                      <span className="font-medium text-foreground">
                        {keptOrgLabel}
                      </span>
                      : {projectsOverage} {t('projects')}, {membersOverage}{' '}
                      {t('members')}, {domainsOverage} {t('domains')},{' '}
                      {keptResourceDeletions}{' '}
                      {keptResourceDeletions === 1
                        ? t('resource')
                        : t('resources')}{' '}
                      {t('still over limit')}
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
