/**
 * Escalation policies belong to an organization and are not in the pinned
 * Console SDK, so these call the routes directly. Pass the client of
 * `sdk.forConsole.organization(organizationId)`, which carries the
 * `X-Appwrite-Organization` header the routes act on.
 */

import type { Client } from '@appwrite.io/console'

import type {
  EscalationPolicy,
  EscalationPolicyInput,
  EscalationPolicyList,
} from './models'

const PATH = '/organization/escalation-policies'

function call<T>(
  client: Client,
  method: 'get' | 'post' | 'put' | 'delete',
  path: string,
  payload: Record<string, unknown> = {},
): Promise<T> {
  return client.call(
    method,
    new URL(client.config.endpoint + path),
    {
      'X-Appwrite-Project': client.config.project,
      'content-type': 'application/json',
      accept: 'application/json',
    },
    payload,
  ) as Promise<T>
}

function policyPath(escalationPolicyId: string): string {
  return `${PATH}/${encodeURIComponent(escalationPolicyId)}`
}

export function listEscalationPolicies(
  client: Client,
  params: { queries?: string[]; total?: boolean } = {},
): Promise<EscalationPolicyList> {
  return call(client, 'get', PATH, params)
}

export function getEscalationPolicy(
  client: Client,
  escalationPolicyId: string,
): Promise<EscalationPolicy> {
  return call(client, 'get', policyPath(escalationPolicyId))
}

export function createEscalationPolicy(
  client: Client,
  params: { escalationPolicyId: string } & EscalationPolicyInput,
): Promise<EscalationPolicy> {
  return call(client, 'post', PATH, params)
}

/** Sends only the fields passed. */
export function updateEscalationPolicy(
  client: Client,
  escalationPolicyId: string,
  changes: Partial<EscalationPolicyInput>,
): Promise<EscalationPolicy> {
  return call(client, 'put', policyPath(escalationPolicyId), changes)
}

export function deleteEscalationPolicy(
  client: Client,
  escalationPolicyId: string,
): Promise<void> {
  return call(client, 'delete', policyPath(escalationPolicyId))
}
