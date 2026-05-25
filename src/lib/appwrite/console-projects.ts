import type { Models } from '@appwrite.io/console'
import type { Region } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'

type ListConsoleProjectsParams = {
  queries?: string[]
  search?: string
  total?: boolean
}

function projectsUrl(path = ''): URL {
  const endpoint = sdk.forConsole.client.config.endpoint as string
  return new URL(`${endpoint}/projects${path}`)
}

function listPayload(params?: ListConsoleProjectsParams): Record<string, unknown> {
  const payload: Record<string, unknown> = {}
  if (params?.queries !== undefined) payload.queries = params.queries
  if (params?.search !== undefined) payload.search = params.search
  if (params?.total !== undefined) payload.total = params.total
  return payload
}

/**
 * List projects via GET /projects — matches production console and cloud.appwrite.io.
 *
 * The b1cfedd SDK types project listing on `organization.listProjects` (`GET
 * /organization/projects`), but cloud still serves the legacy route used by the
 * reference console (`GET /projects` with `teamId` in queries).
 */
export function listConsoleProjects(
  params?: ListConsoleProjectsParams,
): Promise<Models.ProjectList> {
  return sdk.forConsole.client.call(
    'get',
    projectsUrl(),
    {},
    listPayload(params),
  ) as Promise<Models.ProjectList>
}

/** Create a project via POST /projects (requires `teamId` on cloud). */
export function createConsoleProject(params: {
  projectId: string
  name: string
  region?: Region
  teamId: string
}): Promise<Models.Project> {
  const payload: Record<string, unknown> = {
    projectId: params.projectId,
    name: params.name,
    teamId: params.teamId,
  }
  if (params.region !== undefined) payload.region = params.region

  return sdk.forConsole.client.call(
    'post',
    projectsUrl(),
    { 'content-type': 'application/json' },
    payload,
  ) as Promise<Models.Project>
}

export function getConsoleProject(params: {
  projectId: string
}): Promise<Models.Project> {
  return sdk.forConsole.client.call(
    'get',
    projectsUrl(`/${params.projectId}`),
    {},
    {},
  ) as Promise<Models.Project>
}

export function updateConsoleProject(params: {
  projectId: string
  name: string
}): Promise<Models.Project> {
  return sdk.forConsole.client.call(
    'patch',
    projectsUrl(`/${params.projectId}`),
    { 'content-type': 'application/json' },
    { name: params.name },
  ) as Promise<Models.Project>
}

export function deleteConsoleProject(params: {
  projectId: string
}): Promise<{}> {
  return sdk.forConsole.client.call(
    'delete',
    projectsUrl(`/${params.projectId}`),
    { 'content-type': 'application/json' },
    {},
  ) as Promise<{}>
}
