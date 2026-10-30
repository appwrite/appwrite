/**
 * Organization monitors exist only in editions that add them, so the
 * generated `Monitors` service, built from this repository's spec, has no
 * routes for them. Editions without them answer with an error.
 */

import type { Client, MonitorChannel } from '@appwrite.io/console'

import type { MonitorList, Monitor } from './models'

function call<T>(
  client: Client,
  method: 'get' | 'put',
  path: string,
  payload: Record<string, unknown>,
): Promise<T> {
  const uri = new URL(client.config.endpoint + path)
  const headers: Record<string, string> = {
    'X-Appwrite-Project': client.config.project,
    accept: 'application/json',
  }
  if (method !== 'get') headers['content-type'] = 'application/json'
  return client.call(method, uri, headers, payload) as Promise<T>
}

export function listOrganizationMonitors(
  client: Client,
  params: { organizationId: string; queries?: string[] },
): Promise<MonitorList> {
  const { organizationId, ...payload } = params
  return call(
    client,
    'get',
    `/organizations/${encodeURIComponent(organizationId)}/monitors`,
    payload,
  )
}

/** Billing owns a budget monitor's thresholds, so only its delivery changes. */
export function updateBudgetMonitor(
  client: Client,
  params: {
    organizationId: string
    monitorId: string
    channels?: MonitorChannel[]
    webhookUrl?: string
  },
): Promise<Monitor> {
  const { organizationId, monitorId, ...changes } = params
  const payload = Object.fromEntries(
    Object.entries(changes).filter(([, value]) => value !== undefined),
  )
  return call(
    client,
    'put',
    `/organizations/${encodeURIComponent(organizationId)}/monitors/budget/${encodeURIComponent(monitorId)}`,
    payload,
  )
}
