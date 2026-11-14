/**
 * The environment routes of a project, called directly: the pinned Console SDK
 * answers them with `Models.Project`, from before an environment stopped
 * carrying a project ID of its own. Cloud returns the Environment model now,
 * keyed by the environment ID clients address it with. Delete this for
 * `project.listEnvironments()` and the rest once the pin carries the model.
 *
 * Every call is on the project itself, never inside one of its environments:
 * callers pass the project client with no environment applied.
 */

import type { Client } from '@appwrite.io/console'

export interface ProjectEnvironment {
  /** The environment ID, as passed in `X-Appwrite-Environment` */
  $id: string
  $createdAt: string
  $updatedAt: string
  /** The project the environment belongs to */
  projectId: string
  name: string
}

export interface ProjectEnvironmentList {
  total: number
  environments: ProjectEnvironment[]
}

function apiHeaders(client: Client): Record<string, string> {
  return {
    'X-Appwrite-Project': client.config.project,
    'content-type': 'application/json',
    accept: 'application/json',
  }
}

export async function listProjectEnvironments(
  client: Client,
  params: { queries?: string[]; total?: boolean } = {},
): Promise<ProjectEnvironmentList> {
  const uri = new URL(client.config.endpoint + '/project/environments')
  if (params.queries) {
    for (const query of params.queries) {
      uri.searchParams.append('queries[]', query)
    }
  }
  if (params.total !== undefined) {
    uri.searchParams.set('total', String(params.total))
  }
  return (await client.call(
    'get',
    uri,
    apiHeaders(client),
  )) as ProjectEnvironmentList
}

export async function createProjectEnvironment(
  client: Client,
  params: { environmentId: string; name: string; resources?: string[] },
): Promise<ProjectEnvironment> {
  const uri = new URL(client.config.endpoint + '/project/environments')
  return (await client.call(
    'post',
    uri,
    apiHeaders(client),
    params,
  )) as ProjectEnvironment
}

export async function updateProjectEnvironment(
  client: Client,
  params: { environmentId: string; name: string },
): Promise<ProjectEnvironment> {
  const uri = new URL(
    client.config.endpoint +
      '/project/environments/' +
      encodeURIComponent(params.environmentId),
  )
  return (await client.call('patch', uri, apiHeaders(client), {
    name: params.name,
  })) as ProjectEnvironment
}

export async function deleteProjectEnvironment(
  client: Client,
  params: { environmentId: string },
): Promise<void> {
  const uri = new URL(
    client.config.endpoint +
      '/project/environments/' +
      encodeURIComponent(params.environmentId),
  )
  await client.call('delete', uri, apiHeaders(client))
}
