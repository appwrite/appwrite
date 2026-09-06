import type { Models } from '@appwrite.io/console'
import { fetchOrganizationDomains } from '@/lib/react-query/hooks/domains'
import { fetchOrganizationProjects } from '@/lib/react-query/hooks/organizations'
import { fetchOrganizationMemberships } from '@/lib/react-query/hooks/teams'

const DOWNGRADE_PAGE_SIZE = 100

export async function fetchAllDowngradeProjects(organizationId: string) {
  const all: Models.Project[] = []
  let page = 0
  let total = 0

  do {
    const data = await fetchOrganizationProjects(
      organizationId,
      page,
      DOWNGRADE_PAGE_SIZE,
    )
    all.push(...(data.projects ?? []))
    total = data.total ?? all.length
    page += 1
  } while (all.length < total)

  return all
}

export async function fetchAllDowngradeMemberships(organizationId: string) {
  const all: Models.Membership[] = []
  let page = 0
  let total = 0

  do {
    const data = await fetchOrganizationMemberships(
      organizationId,
      page,
      DOWNGRADE_PAGE_SIZE,
    )
    all.push(...((data.memberships ?? []) as Models.Membership[]))
    total = data.total ?? all.length
    page += 1
  } while (all.length < total)

  return all
}

export async function fetchAllDowngradeDomains(organizationId: string) {
  const all: Models.Domain[] = []
  let page = 0
  let total = 0

  do {
    const data = await fetchOrganizationDomains(
      organizationId,
      page,
      DOWNGRADE_PAGE_SIZE,
    )
    all.push(...((data.domains ?? []) as Models.Domain[]))
    total = data.total ?? all.length
    page += 1
  } while (all.length < total)

  return all
}
