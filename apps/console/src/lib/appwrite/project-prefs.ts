/**
 * `project.getPrefs` and `project.updatePrefs`, which the pinned Console SDK does
 * not carry yet: appwrite/appwrite#15120 added them and the SDK is generated from
 * Cloud's spec on its next release. Delete this for `project.getPrefs()` and
 * `project.updatePrefs({ prefs })` once the pin has them.
 */

import type { Client } from '@appwrite.io/console'
import type { ProjectPrefs } from '@/lib/project-prefs-keys'

function apiHeaders(client: Client): Record<string, string> {
  return {
    'X-Appwrite-Project': client.config.project,
    'content-type': 'application/json',
    accept: 'application/json',
  }
}

export async function getProjectPrefs(client: Client): Promise<ProjectPrefs> {
  const uri = new URL(client.config.endpoint + '/project/prefs')
  return (await client.call('get', uri, apiHeaders(client))) as ProjectPrefs
}

/** Replaces the whole prefs object, as the endpoint does: merge before calling. */
export async function updateProjectPrefs(
  client: Client,
  prefs: ProjectPrefs,
): Promise<ProjectPrefs> {
  const uri = new URL(client.config.endpoint + '/project/prefs')
  return (await client.call('put', uri, apiHeaders(client), {
    prefs,
  })) as ProjectPrefs
}
