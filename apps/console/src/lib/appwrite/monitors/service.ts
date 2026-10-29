/**
 * Hand-written Monitors and Alerts clients: the Console SDK has neither
 * service yet. Delete this folder for the generated ones once they ship.
 */

import type { Client } from '@appwrite.io/console'

import type {
  Alert,
  AlertChannel,
  AlertList,
  Monitor,
  MonitorList,
} from './models'

export type MonitorInput = {
  name: string
  /** Metric comparisons; build them with `buildRules`. */
  rules: string[]
  /** Ignored for gauge metrics. */
  window?: number
  cooldown?: number
  channels?: AlertChannel[]
  webhookUrl?: string
  enabled?: boolean
}

type ListParams = { queries?: string[]; search?: string; total?: boolean }

abstract class Service {
  client: Client

  constructor(client: Client) {
    this.client = client
  }

  protected request<T>(
    method: 'get' | 'post' | 'put' | 'delete',
    path: string,
    payload: Record<string, unknown> = {},
  ): Promise<T> {
    const uri = new URL(this.client.config.endpoint + path)
    const headers: Record<string, string> = {
      'X-Appwrite-Project': this.client.config.project,
      accept: 'application/json',
    }
    if (method !== 'get') {
      headers['content-type'] = 'application/json'
    }
    const body = Object.fromEntries(
      Object.entries(payload).filter(([, value]) => value !== undefined),
    )
    return this.client.call(method, uri, headers, body)
  }
}

const organization = (organizationId: string) =>
  `/organizations/${encodeURIComponent(organizationId)}`

export class Monitors extends Service {
  list(params: ListParams = {}): Promise<MonitorList> {
    return this.request('get', '/monitors', params)
  }

  get(params: { monitorId: string }): Promise<Monitor> {
    return this.request(
      'get',
      `/monitors/${encodeURIComponent(params.monitorId)}`,
    )
  }

  delete(params: { monitorId: string }): Promise<Record<string, never>> {
    return this.request(
      'delete',
      `/monitors/${encodeURIComponent(params.monitorId)}`,
    )
  }

  /** `monitorId` accepts `ID.unique()`. */
  createUsageMonitor(
    params: { monitorId: string } & MonitorInput,
  ): Promise<Monitor> {
    return this.request('post', '/monitors/usage', params)
  }

  /** Only the fields passed change. */
  updateUsageMonitor(
    params: { monitorId: string } & Partial<MonitorInput>,
  ): Promise<Monitor> {
    const { monitorId, ...changes } = params
    return this.request(
      'put',
      `/monitors/usage/${encodeURIComponent(monitorId)}`,
      changes,
    )
  }

  /** An organization's monitors on its billing, where the edition has them. */
  listForOrganization(
    params: { organizationId: string } & ListParams,
  ): Promise<MonitorList> {
    const { organizationId, ...rest } = params
    return this.request('get', `${organization(organizationId)}/monitors`, rest)
  }

  /** Budget monitors: billing owns their thresholds, so only delivery changes. */
  updateBudgetMonitor(
    params: { organizationId: string; monitorId: string } & Partial<
      Pick<MonitorInput, 'channels' | 'webhookUrl' | 'enabled'>
    >,
  ): Promise<Monitor> {
    const { organizationId, monitorId, ...changes } = params
    return this.request(
      'put',
      `${organization(organizationId)}/monitors/budget/${encodeURIComponent(monitorId)}`,
      changes,
    )
  }
}

export class Alerts extends Service {
  list(params: ListParams = {}): Promise<AlertList> {
    return this.request('get', '/alerts', params)
  }

  get(params: { alertId: string }): Promise<Alert> {
    return this.request('get', `/alerts/${encodeURIComponent(params.alertId)}`)
  }

  listForOrganization(
    params: { organizationId: string } & ListParams,
  ): Promise<AlertList> {
    const { organizationId, ...rest } = params
    return this.request('get', `${organization(organizationId)}/alerts`, rest)
  }
}
