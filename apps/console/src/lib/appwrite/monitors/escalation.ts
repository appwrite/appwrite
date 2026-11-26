/**
 * Escalations belong to an organization and are not in the pinned
 * Console SDK, so these call the routes directly. Pass the client of
 * `sdk.forConsole.organization(organizationId)`, which carries the
 * `X-Appwrite-Organization` header the routes act on.
 */

import type { Client } from '@appwrite.io/console'

import type { Escalation, EscalationInput, EscalationList } from './models'

const PATH = '/organization/escalations'

function call<T>(
  client: Client,
  method: 'get' | 'post' | 'patch' | 'delete',
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

function escalationPath(escalationId: string): string {
  return `${PATH}/${encodeURIComponent(escalationId)}`
}

export function listEscalations(
  client: Client,
  params: { queries?: string[]; total?: boolean } = {},
): Promise<EscalationList> {
  return call(client, 'get', PATH, params)
}

export function getEscalation(
  client: Client,
  escalationId: string,
): Promise<Escalation> {
  return call(client, 'get', escalationPath(escalationId))
}

export function createEscalation(
  client: Client,
  params: { escalationId: string } & EscalationInput,
): Promise<Escalation> {
  return call(client, 'post', PATH, params)
}

/** Sends only the fields passed. */
export function updateEscalation(
  client: Client,
  escalationId: string,
  changes: Partial<EscalationInput>,
): Promise<Escalation> {
  return call(client, 'patch', escalationPath(escalationId), changes)
}

export function deleteEscalation(
  client: Client,
  escalationId: string,
): Promise<void> {
  return call(client, 'delete', escalationPath(escalationId))
}
