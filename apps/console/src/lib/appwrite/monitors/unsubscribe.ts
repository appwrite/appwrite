/**
 * Alert email opt-outs. The console's unsubscribe page holds the token from
 * the email instead of a session, and the routes are in no SDK, so they are
 * called directly.
 */

import type { Client } from '@appwrite.io/console'

/** What the page shows: what the email named, and what is stopped already. */
export type AlertsUnsubscribe = {
  monitorName: string
  projectName: string
  type: string
  /** Whether every alert email of the monitor's type can be stopped at once. */
  optOut: boolean
  /** This monitor's emails are stopped. */
  monitor: boolean
  /** Every alert email of the type is stopped. */
  all: boolean
}

export type UnsubscribeScope = 'monitor' | 'all'

function call<T>(
  client: Client,
  method: 'get' | 'post' | 'delete',
  payload: Record<string, string>,
): Promise<T> {
  const headers: Record<string, string> = { 'X-Appwrite-Project': 'console' }
  if (method !== 'get') headers['content-type'] = 'application/json'
  return client.call(
    method,
    new URL(client.config.endpoint + '/console/alerts/unsubscribe'),
    headers,
    payload,
  ) as Promise<T>
}

export function getAlertsUnsubscribe(
  client: Client,
  token: string,
): Promise<AlertsUnsubscribe> {
  return call(client, 'get', { token })
}

/** Stops the emails `scope` names (`post`), or resumes them (`delete`). */
export function setAlertEmails(
  client: Client,
  method: 'post' | 'delete',
  token: string,
  scope: UnsubscribeScope,
): Promise<unknown> {
  return call(client, method, { token, scope })
}
