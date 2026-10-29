import type { Models } from '@appwrite.io/console'

/**
 * Inbound email (SMTP) trigger for Cloud functions.
 *
 * `emailEnabled` is opt-in (default false). While it is off, Edge rejects
 * RCPT for the function's addresses.
 */
export type FunctionWithEmail = Models.Function & {
  emailEnabled?: boolean
}

export function isFunctionEmailEnabled(
  func: FunctionWithEmail | null | undefined,
): boolean {
  return func?.emailEnabled === true
}

/** Local part of the default address. Edge accepts any local part. */
export const FUNCTION_EMAIL_LOCAL_PART = 'contact'

/** A DNS label is at most 63 characters of `a-z`, `0-9` and `-`. */
const MAX_DNS_LABEL_LENGTH = 63
const DNS_LABEL_PART = /^[a-z0-9-]+$/

/**
 * Appwrite-owned testing address, `contact@{functionId}-{projectId}.{zone}`.
 *
 * The function and project IDs share one DNS label, so the local part is
 * free: senders can use any mailbox name or plus-tag
 * (`orders+eu@{functionId}-{projectId}.{zone}`). DNS is case-insensitive, so
 * the label is lowercased; Edge matches IDs case-insensitively.
 *
 * A function ID with `.` or `_`, or IDs too long for one label, cannot go in
 * the host, so those fall back to the earlier `{functionId}@{projectId}.{zone}`
 * form, which Edge still routes and where the function ID keeps its case.
 *
 * `zone` is `_APP_DOMAIN_EMAIL` from console variables. Returns null when any
 * part is missing, so the console never shows an address the region does not
 * serve.
 */
export function buildFunctionEmailAddress(
  projectId: string | null | undefined,
  functionId: string | null | undefined,
  zone: string | null | undefined,
): string | null {
  const project = projectId?.trim().toLowerCase() ?? ''
  const fn = functionId?.trim() ?? ''
  const domain = zone?.trim().toLowerCase() ?? ''
  if (project === '' || fn === '' || domain === '') return null

  const label = `${fn.toLowerCase()}-${project}`
  if (DNS_LABEL_PART.test(label) && label.length <= MAX_DNS_LABEL_LENGTH) {
    return `${FUNCTION_EMAIL_LOCAL_PART}@${label}.${domain}`
  }
  return `${fn}@${project}.${domain}`
}

/**
 * Body for PUT /v1/functions/:functionId, keeping every key that is set.
 *
 * `@appwrite.io/console` `functions.update()` copies a fixed key list and
 * drops `emailEnabled`, so the email card sends this body through
 * `client.call` instead. Cloud treats an omitted `emailEnabled` as "leave as
 * is", so the SDK `functions.update()` calls in other cards keep the stored
 * value.
 */
export function functionUpdateBody(
  params: { functionId: string } & Record<string, unknown>,
): Record<string, unknown> {
  const body: Record<string, unknown> = {}
  for (const [key, value] of Object.entries(params)) {
    if (key === 'functionId') continue
    if (typeof value === 'undefined') continue
    body[key] = value
  }
  return body
}

/** The subset of the SDK `Client` that `putFunction` needs. */
export type FunctionUpdateClient = {
  config: { endpoint: string; project: string }
  call: (
    method: string,
    url: URL,
    headers?: Record<string, string>,
    params?: Record<string, unknown>,
  ) => Promise<unknown>
}

/** PUT /v1/functions/:functionId with the full body, `emailEnabled` included. */
export function putFunction(
  client: FunctionUpdateClient,
  params: { functionId: string } & Record<string, unknown>,
): Promise<FunctionWithEmail> {
  const uri = new URL(
    client.config.endpoint +
      '/functions/' +
      encodeURIComponent(params.functionId),
  )
  return client.call(
    'put',
    uri,
    {
      'X-Appwrite-Project': client.config.project,
      'content-type': 'application/json',
      accept: 'application/json',
    },
    functionUpdateBody(params),
  ) as Promise<FunctionWithEmail>
}
