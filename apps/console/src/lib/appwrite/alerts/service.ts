/**
 * Hand-written Alerts client: the Console SDK has no `Alerts` service yet.
 * Delete this folder for the generated one once it ships.
 */

import type { Client } from '@appwrite.io/console'

import type {
  AlertChannel,
  AlertComparator,
  AlertMetric,
  AlertResourceType,
  AlertRule,
  AlertRuleList,
} from './models'

export type AlertRuleInput = {
  name: string
  metric: AlertMetric
  comparator: AlertComparator
  threshold: number
  resourceType?: AlertResourceType
  resourceId?: string
  window?: number
  cooldown?: number
  channels?: AlertChannel[]
  webhookUrl?: string
  enabled?: boolean
}

export class Alerts {
  client: Client

  constructor(client: Client) {
    this.client = client
  }

  private request<T>(
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

  /**
   * List the project's alert rules.
   */
  listRules(
    params: { queries?: string[]; search?: string; total?: boolean } = {},
  ): Promise<AlertRuleList> {
    return this.request('get', '/alerts/rules', params)
  }

  /**
   * Create an alert rule. `ruleId` accepts `ID.unique()`.
   */
  createRule(params: { ruleId: string } & AlertRuleInput): Promise<AlertRule> {
    return this.request('post', '/alerts/rules', params)
  }

  getRule(params: { ruleId: string }): Promise<AlertRule> {
    return this.request(
      'get',
      `/alerts/rules/${encodeURIComponent(params.ruleId)}`,
    )
  }

  /**
   * Update an alert rule. Only the fields passed change.
   */
  updateRule(
    params: { ruleId: string } & Partial<AlertRuleInput>,
  ): Promise<AlertRule> {
    const { ruleId, ...changes } = params
    return this.request(
      'put',
      `/alerts/rules/${encodeURIComponent(ruleId)}`,
      changes,
    )
  }

  /** An organization's rules on its billing, where the edition has them. */
  listOrganizationRules(params: {
    organizationId: string
    queries?: string[]
  }): Promise<AlertRuleList> {
    const { organizationId, ...rest } = params
    return this.request(
      'get',
      `/organizations/${encodeURIComponent(organizationId)}/alerts/rules`,
      rest,
    )
  }

  updateOrganizationRule(
    params: { organizationId: string; ruleId: string } & Partial<
      Pick<AlertRuleInput, 'channels' | 'webhookUrl' | 'enabled'>
    >,
  ): Promise<AlertRule> {
    const { organizationId, ruleId, ...changes } = params
    return this.request(
      'put',
      `/organizations/${encodeURIComponent(organizationId)}/alerts/rules/${encodeURIComponent(ruleId)}`,
      changes,
    )
  }

  deleteRule(params: { ruleId: string }): Promise<Record<string, never>> {
    return this.request(
      'delete',
      `/alerts/rules/${encodeURIComponent(params.ruleId)}`,
    )
  }
}
