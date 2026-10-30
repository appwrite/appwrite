/**
 * `createEnvironment` with `resources`, which the pinned Console SDK does not
 * carry yet: Cloud took it in appwrite-labs/cloud#6970 and the SDK is generated
 * from Cloud's spec on its next release. Delete this for
 * `project.createEnvironment({ resources })` once the pin has it.
 */

import type { Client, Models } from '@appwrite.io/console'

export async function createProjectEnvironment(
  client: Client,
  params: { environmentId: string; name: string; resources: string[] },
): Promise<Models.Project> {
  const uri = new URL(client.config.endpoint + '/project/environments')
  const apiHeaders: Record<string, string> = {
    'X-Appwrite-Project': client.config.project,
    'content-type': 'application/json',
    accept: 'application/json',
  }
  return (await client.call('post', uri, apiHeaders, params)) as Models.Project
}
