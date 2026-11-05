/**
 * Organization monitors exist only in editions that add them, so the
 * generated `Monitors` service, built from this repository's spec, has no
 * routes for them. Editions without them answer with an error.
 */

import type { Client } from '@appwrite.io/console'

import type { MonitorList } from './models'

function get<T>(
  client: Client,
  path: string,
  payload: Record<string, unknown>,
): Promise<T> {
  const uri = new URL(client.config.endpoint + path)
  return client.call(
    'get',
    uri,
    { 'X-Appwrite-Project': client.config.project, accept: 'application/json' },
    payload,
  ) as Promise<T>
}

export function listOrganizationMonitors(
  client: Client,
  params: { organizationId: string; queries?: string[] },
): Promise<MonitorList> {
  const { organizationId, ...payload } = params
  return get(
    client,
    `/organizations/${encodeURIComponent(organizationId)}/monitors`,
    payload,
  )
}
