import type { Models } from '@appwrite.io/console'
import {
  DOWNGRADE_RESOURCE_TYPES,
  type DowngradeResourceImpact,
} from '@/lib/billing/downgrade-plan-limits'
import { fetchProjectDowngradeResources } from '@/lib/billing/fetch-project-downgrade-resources'
import { fetchOrganizationDomains } from '@/lib/react-query/hooks/domains'
import { fetchOrganizationProjects } from '@/lib/react-query/hooks/organizations'
import { fetchOrganizationMemberships } from '@/lib/react-query/hooks/teams'

const DELETED_ORG_LIST_LIMIT = 1000

export type DeletedOrganizationImpact = {
  organizationId: string
  organizationName: string
  projects: Models.Project[]
  memberships: Models.Membership[]
  domains: Models.Domain[]
  resourceImpact: DowngradeResourceImpact
  projectResourceImpacts: {
    projectId: string
    projectName: string
    resourceImpact: DowngradeResourceImpact
  }[]
}

export async function fetchDeletedOrganizationImpact(
  organizationId: string,
  organizationName: string,
  fallbackProjects: Models.Project[] = [],
): Promise<DeletedOrganizationImpact> {
  const [projectsData, membershipsData, domainsData] = await Promise.all([
    fetchOrganizationProjects(organizationId),
    fetchOrganizationMemberships(
      organizationId,
      0,
      DELETED_ORG_LIST_LIMIT,
    ),
    fetchOrganizationDomains(
      organizationId,
      0,
      DELETED_ORG_LIST_LIMIT,
    ),
  ])

  const fetchedProjects = projectsData.projects ?? []
  const projects =
    fetchedProjects.length > 0 ? fetchedProjects : fallbackProjects
  const resourceImpacts = await Promise.all(
    projects.map((project) => fetchProjectDowngradeResources(project.$id)),
  )

  const resourceImpact: DowngradeResourceImpact = {}
  const projectResourceImpacts = projects.map((project, index) => {
    const resources = resourceImpacts[index]
    const projectImpact: DowngradeResourceImpact = {}

    for (const { id } of DOWNGRADE_RESOURCE_TYPES) {
      resourceImpact[id] = (resourceImpact[id] ?? 0) + resources[id].total
      projectImpact[id] = resources[id].total
    }

    return {
      projectId: project.$id,
      projectName: project.name || project.$id,
      resourceImpact: projectImpact,
    }
  })

  return {
    organizationId,
    organizationName,
    projects,
    memberships: membershipsData.memberships ?? [],
    domains: domainsData.domains ?? [],
    resourceImpact,
    projectResourceImpacts,
  }
}
