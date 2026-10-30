/**
 * Alert email opt-outs. The console's unsubscribe page holds the token from
 * the email instead of a session, and the routes are in no SDK, so they are
 * called directly.
 */

import type { Client } from '@appwrite.io/console'

/** Prefix of a token's scope that covers every monitor of a type. */
const TYPE_SCOPE = 'type:'

/** What a token stops: one monitor's emails, or every alert email of a type. */
export type UnsubscribeScope =
  | { kind: 'monitor'; monitorId: string }
  | { kind: 'type'; type: string }

/**
 * Reads the scope from the token's claims for the page's wording. The server
 * verifies the signature; nothing here is trusted beyond display.
 */
export function unsubscribeScope(token: string): UnsubscribeScope | null {
  const claims = token.split('.')[1]
  if (!claims) return null
  try {
    const json = atob(claims.replace(/-/g, '+').replace(/_/g, '/'))
    const scope = (JSON.parse(json) as { scope?: unknown }).scope
    if (typeof scope !== 'string' || scope === '') return null
    return scope.startsWith(TYPE_SCOPE)
      ? { kind: 'type', type: scope.slice(TYPE_SCOPE.length) }
      : { kind: 'monitor', monitorId: scope }
  } catch {
    return null
  }
}

/** Stops the emails the token names (`post`), or resumes them (`delete`). */
export function setAlertEmails(
  client: Client,
  method: 'post' | 'delete',
  token: string,
): Promise<unknown> {
  return client.call(
    method,
    new URL(client.config.endpoint + '/console/alerts/unsubscribe'),
    { 'X-Appwrite-Project': 'console', 'content-type': 'application/json' },
    { token },
  )
}
