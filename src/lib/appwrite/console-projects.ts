import type { Models } from '@appwrite.io/console'
import type { Region } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'

type ListConsoleProjectsParams = {
  queries?: string[]
  search?: string
  total?: boolean
}

/**
 * List projects via the console Organization SDK
 * (`GET /organization/projects`). Filter by organization with
 * `Query.equal('teamId', orgId)` in `queries`.
 */
export function listConsoleProjects(
  params?: ListConsoleProjectsParams,
): Promise<Models.ProjectList> {
  return sdk.forConsole.organization.listProjects({
    queries: params?.queries,
    search: params?.search,
    total: params?.total,
  })
}

/**
 * Create a project via the console Organization SDK
 * (`POST /organization/projects`), then assign it to `teamId` when needed
 * (`PATCH /projects/{id}/team`).
 */
export async function createConsoleProject(params: {
  projectId: string
  name: string
  region?: Region
  teamId: string
}): Promise<Models.Project> {
  const project = await sdk.forConsole.organization.createProject({
    projectId: params.projectId,
    name: params.name,
    ...(params.region !== undefined ? { region: params.region } : {}),
  })

  if (project.teamId === params.teamId) {
    return project
  }

  return sdk.forConsole.projects.updateTeam({
    projectId: project.$id,
    teamId: params.teamId,
  })
}

export function getConsoleProject(params: {
  projectId: string
}): Promise<Models.Project> {
  return sdk.forConsole.organization.getProject({
    projectId: params.projectId,
  })
}

export function updateConsoleProject(params: {
  projectId: string
  name: string
}): Promise<Models.Project> {
  return sdk.forConsole.organization.updateProject({
    projectId: params.projectId,
    name: params.name,
  })
}

export function deleteConsoleProject(params: {
  projectId: string
}): Promise<{}> {
  return sdk.forConsole.organization.deleteProject({
    projectId: params.projectId,
  })
}
