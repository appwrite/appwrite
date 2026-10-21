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

/**
 * Appwrite-owned testing address, `{functionId}@{projectId}.{zone}`.
 *
 * `zone` is `_APP_DOMAIN_EMAIL` from console variables. Project IDs are
 * lowercase-only; the function ID keeps its case because Edge matches the
 * local-part against the real ID. Returns null when any part is missing, so
 * the console never shows an address the region does not serve.
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
