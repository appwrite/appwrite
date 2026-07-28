import type { Models } from '@appwrite.io/console'
import type { Region } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'

type ListConsoleProjectsParams = {
  /** Organization / team id. Required for `/organization/projects`. */
  organizationId?: string
  queries?: string[]
  search?: string
  total?: boolean
}

/**
 * Pull `teamId` from a Query.equal('teamId', …) string so existing callers
 * that only pass queries keep working with the org-scoped Organization API.
 */
function extractOrganizationIdFromQueries(
  queries?: string[],
): string | undefined {
  if (!queries?.length) return undefined
  for (const query of queries) {
    try {
      const parsed = JSON.parse(query) as {
        method?: string
        attribute?: string
        values?: unknown[]
      }
      if (
        parsed.method === 'equal' &&
        parsed.attribute === 'teamId' &&
        Array.isArray(parsed.values) &&
        typeof parsed.values[0] === 'string' &&
        parsed.values[0].trim()
      ) {
        return parsed.values[0].trim()
      }
    } catch {
      // Ignore non-JSON / unexpected query shapes.
    }
  }
  return undefined
}

function resolveOrganizationId(params?: {
  organizationId?: string
  teamId?: string
  queries?: string[]
}): string {
  const fromParams =
    params?.organizationId?.trim() || params?.teamId?.trim() || ''
  if (fromParams) return fromParams
  const fromQueries = extractOrganizationIdFromQueries(params?.queries)
  if (fromQueries) return fromQueries
  throw new Error(
    'Organization ID is required for organization project API calls',
  )
}

/**
 * List projects via the org-scoped console Organization SDK
 * (`GET /organization/projects` with `X-Appwrite-Organization`).
 * Pass `organizationId` or include `Query.equal('teamId', orgId)` in `queries`.
 */
export function listConsoleProjects(
  params?: ListConsoleProjectsParams,
): Promise<Models.ProjectList> {
  const organizationId = resolveOrganizationId(params)
  return sdk.forConsole.organization(organizationId).listProjects({
    queries: params?.queries,
    search: params?.search,
    total: params?.total,
  })
}

/**
 * Create a project via the org-scoped console Organization SDK
 * (`POST /organization/projects` with `X-Appwrite-Organization`).
 * The org header assigns the project to `teamId`; no separate team update.
 */
export function createConsoleProject(params: {
  projectId: string
  name: string
  region?: Region
  teamId: string
}): Promise<Models.Project> {
  return sdk.forConsole.organization(params.teamId).createProject({
    projectId: params.projectId,
    name: params.name,
    ...(params.region !== undefined ? { region: params.region } : {}),
  })
}

export function getConsoleProject(params: {
  projectId: string
  organizationId: string
}): Promise<Models.Project> {
  return sdk.forConsole.organization(params.organizationId).getProject({
    projectId: params.projectId,
  })
}

export function updateConsoleProject(params: {
  projectId: string
  name: string
  organizationId: string
}): Promise<Models.Project> {
  return sdk.forConsole.organization(params.organizationId).updateProject({
    projectId: params.projectId,
    name: params.name,
  })
}

export function deleteConsoleProject(params: {
  projectId: string
  organizationId: string
}): Promise<{}> {
  return sdk.forConsole.organization(params.organizationId).deleteProject({
    projectId: params.projectId,
  })
}
