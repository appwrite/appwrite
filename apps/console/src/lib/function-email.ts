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

/** Local part shown with an address. Edge accepts any local part. */
export const FUNCTION_EMAIL_LOCAL_PART = 'contact'

/** A DNS label is at most 63 characters. */
const MAX_DNS_LABEL_LENGTH = 63

/** Length of the random part of a suggested label. */
const SUGGESTION_SUFFIX_LENGTH = 6

const LABEL_CHARS = 'abcdefghijklmnopqrstuvwxyz0123456789'

function randomSuffix(): string {
  const bytes = new Uint8Array(SUGGESTION_SUFFIX_LENGTH)
  crypto.getRandomValues(bytes)
  let suffix = ''
  for (const byte of bytes) suffix += LABEL_CHARS[byte % LABEL_CHARS.length]
  return suffix
}

/**
 * Suggested label for a function's `{label}.{zone}` email domain: the
 * function name as a DNS label plus a random part, the way Function and Site
 * domains are suggested. Labels are stored as SMTP rules and first come first
 * served, so the random part keeps the suggestion free and hard to guess. The
 * user can change it before it is saved.
 */
export function suggestFunctionEmailLabel(
  name: string | null | undefined,
  suffix: string = randomSuffix(),
): string {
  let base = ''
  for (const char of (name ?? '').toLowerCase()) {
    const isLabelChar =
      (char >= 'a' && char <= 'z') || (char >= '0' && char <= '9')
    if (isLabelChar) base += char
    else if (base !== '' && !base.endsWith('-')) base += '-'
  }
  const maxBase = MAX_DNS_LABEL_LENGTH - suffix.length - 1
  base = base.slice(0, maxBase)
  while (base.endsWith('-')) base = base.slice(0, -1)
  return base === '' ? `mail-${suffix}` : `${base}-${suffix}`
}

/** The address shown for an email domain. Any local part works. */
export function functionEmailAddress(domain: string): string {
  return `${FUNCTION_EMAIL_LOCAL_PART}@${domain}`
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
