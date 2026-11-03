/**
 * Google Cloud REST adapter for the FCM quick setup (browser side).
 *
 * Calls go through the console's own `_api/google/*` relay (see
 * `google-proxy.ts`), which forwards the provider access token to the Google
 * API hosts and never persists it. The orchestration mirrors the Resend quick
 * setup: list what the user can target, mint a sending credential, and hand
 * back something the wizard can store — here the service account's key JSON.
 */

import {
  FCM_PROVISIONING_PERMISSIONS,
  FCM_REQUIRED_SERVICES,
  FCM_SENDER_ROLE,
  buildServiceAccountDisplayName,
  buildServiceAccountId,
  type FcmProvisioningBlock,
} from './google'

/** Mount point of the `src/routes/_api/google/*` relay. */
const GOOGLE_PROXY_BASE_PATH = '/google'

export class GoogleApiError extends Error {
  status: number
  /**
   * Google's machine-readable reason from the error details, e.g.
   * `SERVICE_DISABLED` or `ACCESS_TOKEN_SCOPE_INSUFFICIENT`, falling back to the
   * coarser top-level status (`PERMISSION_DENIED`). Steers the message shown.
   */
  reason?: string
  /** A console link Google points to, e.g. the API activation page. */
  helpUrl?: string
  /** On SERVICE_DISABLED, the disabled service (e.g. `firebase.googleapis.com`). */
  service?: string
  /** On SERVICE_DISABLED, the consumer project the service must be enabled on. */
  consumerProject?: string

  constructor(
    message: string,
    status: number,
    reason?: string,
    helpUrl?: string,
    service?: string,
    consumerProject?: string,
  ) {
    super(message)
    this.name = 'GoogleApiError'
    this.status = status
    this.reason = reason
    this.helpUrl = helpUrl
    this.service = service
    this.consumerProject = consumerProject
  }
}

/** A gate from the design doc failed, so the flow falls back to manual upload. */
export class FcmProvisioningBlockedError extends Error {
  block: FcmProvisioningBlock

  constructor(block: FcmProvisioningBlock, message: string) {
    super(message)
    this.name = 'FcmProvisioningBlockedError'
    this.block = block
  }
}

/** Token rejected outright: a new authorization is the only fix (reconnect). */
export function isGoogleUnauthorizedError(error: unknown): boolean {
  return error instanceof GoogleApiError && error.status === 401
}

/** A Firebase project the user can target, normalized to what the UI needs. */
export interface FirebaseProject {
  id: string
  displayName: string
}

type GoogleErrorDetail = {
  '@type'?: unknown
  reason?: unknown
  metadata?: { activationUrl?: unknown; service?: unknown; consumer?: unknown }
  links?: Array<{ url?: unknown }>
}

type GoogleErrorPayload = {
  error?: { message?: unknown; status?: unknown; details?: unknown }
}

/**
 * Pull out the message, the precise machine reason, any help link, and — for a
 * disabled service — which service and consumer project. Google nests the useful
 * reason (`SERVICE_DISABLED`) and that metadata in an `ErrorInfo` detail, not the
 * top-level `status` (`PERMISSION_DENIED`), so they are read from `details`.
 */
function readGoogleError(payload: unknown): {
  message?: string
  reason?: string
  helpUrl?: string
  service?: string
  consumerProject?: string
} {
  if (!payload || typeof payload !== 'object') return {}
  const error = (payload as GoogleErrorPayload).error
  if (!error || typeof error !== 'object') return {}

  const message = typeof error.message === 'string' ? error.message : undefined
  let reason = typeof error.status === 'string' ? error.status : undefined
  let helpUrl: string | undefined
  let service: string | undefined
  let consumerProject: string | undefined

  const details = Array.isArray(error.details)
    ? (error.details as GoogleErrorDetail[])
    : []
  for (const detail of details) {
    if (typeof detail.reason === 'string') reason = detail.reason
    const activationUrl = detail.metadata?.activationUrl
    if (typeof activationUrl === 'string') helpUrl ??= activationUrl
    const link = detail.links?.[0]?.url
    if (typeof link === 'string') helpUrl ??= link
    if (typeof detail.metadata?.service === 'string') {
      service ??= detail.metadata.service
    }
    // `consumer` is like `projects/581598096606`; keep just the id/number.
    if (typeof detail.metadata?.consumer === 'string') {
      consumerProject ??= detail.metadata.consumer.replace(/^projects\//, '')
    }
  }

  return { message, reason, helpUrl, service, consumerProject }
}

async function googleFetch<T>(
  service: string,
  path: string,
  accessToken: string,
  init: RequestInit = {},
  quotaProject?: string,
  // When a call fails because its API is disabled, enable it and retry once.
  // The enablement calls themselves pass false to avoid recursing.
  autoEnable = true,
): Promise<T> {
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  headers.set('Authorization', `Bearer ${accessToken}`)
  if (init.body !== undefined && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json')
  }
  // Bill the call to the user's own project and check API-enablement there,
  // rather than the OAuth client's project (the relay forwards this).
  if (quotaProject) {
    headers.set('X-Goog-User-Project', quotaProject)
  }

  const response = await fetch(`${GOOGLE_PROXY_BASE_PATH}/${service}${path}`, {
    ...init,
    headers,
    credentials: 'same-origin',
    cache: 'no-store',
  })

  const text = await response.text()
  let payload: unknown = null
  if (text) {
    try {
      payload = JSON.parse(text)
    } catch {
      payload = null
    }
  }

  if (!response.ok) {
    const parsed = readGoogleError(payload)
    const error = new GoogleApiError(
      parsed.message ?? `Google request failed (${response.status})`,
      response.status,
      parsed.reason,
      parsed.helpUrl,
      parsed.service,
      parsed.consumerProject,
    )
    // The API this call needs is off: enable it on its consumer project and
    // retry once.
    if (
      autoEnable &&
      error.reason === 'SERVICE_DISABLED' &&
      error.service &&
      error.consumerProject
    ) {
      try {
        await enableServices(accessToken, error.consumerProject, [
          error.service,
        ])
      } catch {
        // Enablement is not permitted (e.g. the OAuth client's project on
        // Cloud). Re-throw the original SERVICE_DISABLED so the UI keeps the
        // activation link and operator-setup message, rather than the
        // enablement permission error.
        throw error
      }
      return googleFetch<T>(
        service,
        path,
        accessToken,
        init,
        quotaProject,
        false,
      )
    }
    throw error
  }
  return payload as T
}

/**
 * A required Google API is not enabled on the console's Google Cloud project.
 * Operator setup, not an end-user problem, so it is surfaced with the activation
 * link rather than folded into the manual-fallback gates.
 */
export function isServiceDisabledError(error: unknown): boolean {
  return error instanceof GoogleApiError && error.reason === 'SERVICE_DISABLED'
}

/**
 * Consent that skipped the cloud-platform scope comes back as a 403 whose
 * message calls out the missing scope, distinct from an IAM permission the
 * account simply lacks. Re-consent (not a different account) is the fix.
 */
export function isScopeError(error: unknown): boolean {
  if (!(error instanceof GoogleApiError) || error.status !== 403) return false
  if (error.reason === 'ACCESS_TOKEN_SCOPE_INSUFFICIENT') return true
  const message = error.message.toLowerCase()
  return message.includes('scope') && message.includes('insufficient')
}

/**
 * Org policy `iam.disableServiceAccountKeyCreation` blocks key creation. It is
 * invisible to the preflight, so it only shows up here, as a failed-precondition
 * or permission-denied naming the disabled key creation.
 */
function isKeyCreationBlocked(error: unknown): boolean {
  if (!(error instanceof GoogleApiError)) return false
  if (error.status !== 400 && error.status !== 403) return false
  const message = error.message.toLowerCase()
  return (
    message.includes('key creation') ||
    message.includes('disableserviceaccountkeycreation')
  )
}

/** Every Firebase project the user can access, active ones only. */
export async function listFirebaseProjects(
  accessToken: string,
): Promise<FirebaseProject[]> {
  const projects: FirebaseProject[] = []
  let pageToken: string | undefined

  // Bounded like the Resend domain listing: enough pages for any real account,
  // never an unbounded loop on a malformed nextPageToken.
  for (let page = 0; page < 10; page++) {
    const params = new URLSearchParams({ pageSize: '100' })
    if (pageToken) params.set('pageToken', pageToken)

    const result = await googleFetch<{
      results?: Array<{
        projectId?: unknown
        displayName?: unknown
        state?: unknown
      }>
      nextPageToken?: unknown
    }>('firebase', `/v1beta1/projects?${params.toString()}`, accessToken)

    for (const raw of result.results ?? []) {
      if (typeof raw.projectId !== 'string') continue
      if (raw.state && raw.state !== 'ACTIVE') continue
      projects.push({
        id: raw.projectId,
        displayName:
          typeof raw.displayName === 'string' && raw.displayName.trim()
            ? raw.displayName
            : raw.projectId,
      })
    }

    pageToken =
      typeof result.nextPageToken === 'string' && result.nextPageToken
        ? result.nextPageToken
        : undefined
    if (!pageToken) break
  }

  return projects
}

export interface ProvisioningPreflight {
  ok: boolean
  granted: string[]
  missing: string[]
}

/**
 * Preflight {@link FCM_PROVISIONING_PERMISSIONS} with `testIamPermissions`. Any
 * project member may call it, so the check itself never errors on a permission
 * the account lacks; the lacking one simply comes back as missing.
 */
export async function checkProvisioningPermissions(
  accessToken: string,
  projectId: string,
): Promise<ProvisioningPreflight> {
  // No quota project here: sending one requires `serviceusage.services.use`,
  // which is one of the permissions being checked, so a user without it would
  // get a 403 instead of the permission gate.
  const result = await googleFetch<{ permissions?: string[] }>(
    'crm',
    `/v1/projects/${encodeURIComponent(projectId)}:testIamPermissions`,
    accessToken,
    {
      method: 'POST',
      body: JSON.stringify({ permissions: FCM_PROVISIONING_PERMISSIONS }),
    },
  )
  const granted = new Set(result.permissions ?? [])
  const missing = FCM_PROVISIONING_PERMISSIONS.filter((p) => !granted.has(p))
  return { ok: missing.length === 0, granted: [...granted], missing }
}

/**
 * Enable the services provisioning needs on the user's own project, so a
 * `SERVICE_DISABLED` never interrupts the flow when the user owns the project.
 * Callers treat a failure as "not enabled" (the user may lack permission, or the
 * project may be the OAuth client's), and the manual "Enable the API" fallback
 * still covers whatever stays disabled. Enablement is a long-running operation,
 * so this polls until Google reports it done, bounded so it never hangs. It
 * throws when the operation fails, never returns, or does not finish in time.
 */
export async function enableServices(
  accessToken: string,
  projectId: string,
  services: readonly string[] = FCM_REQUIRED_SERVICES,
): Promise<void> {
  // autoEnable:false on these calls — enabling can't itself trigger enablement.
  const operation = await googleFetch<EnableOperation>(
    'serviceusage',
    `/v1/projects/${encodeURIComponent(projectId)}/services:batchEnable`,
    accessToken,
    {
      method: 'POST',
      body: JSON.stringify({ serviceIds: services }),
    },
    projectId,
    false,
  )

  throwIfOperationFailed(operation)
  if (operation.done === true) return
  if (typeof operation.name !== 'string') {
    throw new GoogleApiError(
      'Google did not return the enablement operation',
      502,
    )
  }

  // Poll the operation to completion (~30s cap); enablement is usually seconds.
  const name = operation.name
  for (let attempt = 0; attempt < 20; attempt++) {
    await delay(1500)
    const status = await googleFetch<EnableOperation>(
      'serviceusage',
      `/v1/${name}`,
      accessToken,
      {},
      projectId,
      false,
    )
    throwIfOperationFailed(status)
    if (status.done === true) return
  }
  throw new GoogleApiError(
    'Enabling the Google API did not finish in time',
    504,
  )
}

/** A Service Usage long-running operation; a failed one is `done` with an `error`. */
type EnableOperation = {
  name?: unknown
  done?: unknown
  error?: { message?: unknown; status?: unknown }
}

function throwIfOperationFailed(operation: EnableOperation): void {
  const error = operation.error
  if (!error || typeof error !== 'object') return
  throw new GoogleApiError(
    typeof error.message === 'string'
      ? error.message
      : 'Enabling the Google API failed',
    // Not an HTTP status from Google; kept off 403 so the scope and IAM
    // classifiers do not misread it.
    400,
    typeof error.status === 'string' ? error.status : undefined,
  )
}

function delay(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

/** The provisioned credential, shaped like what the minted tracker owns. */
export interface FcmServiceAccountKey {
  /**
   * The dedicated service account's email. Cleanup deletes the whole account
   * (which cascades to its key), so an abandoned or replaced provider leaves no
   * account or key behind consuming project quota.
   */
  serviceAccount: string
  /** Service account JSON (pretty-printed), stored as the provider credential. */
  secret: string
}

type IamBinding = { role?: string; members?: string[]; condition?: unknown }
type IamPolicy = {
  version?: number
  etag?: string
  bindings?: IamBinding[]
}

/**
 * Provision a sending credential for FCM: create a dedicated service account,
 * grant it the FCM sender role on the project, and mint a JSON key. Returns the
 * decoded key JSON for the wizard to store.
 *
 * Mapped to the design doc's manual-fallback gates: a missing scope raises a
 * `scope` block, org policy on key creation an `org-policy` block. An IAM
 * permission gap is caught earlier by {@link checkProvisioningPermissions}.
 */
export async function provisionServiceAccountKey(
  accessToken: string,
  input: { projectId: string; projectName: string },
): Promise<FcmServiceAccountKey> {
  const { projectId, projectName } = input
  let email: string | null = null
  try {
    const account = await googleFetch<{ email?: unknown }>(
      'iam',
      `/v1/projects/${encodeURIComponent(projectId)}/serviceAccounts`,
      accessToken,
      {
        method: 'POST',
        body: JSON.stringify({
          accountId: buildServiceAccountId(),
          serviceAccount: {
            displayName: buildServiceAccountDisplayName(projectName),
          },
        }),
      },
      projectId,
    )
    if (typeof account.email !== 'string') {
      throw new GoogleApiError('Google did not return a service account', 502)
    }
    email = account.email

    // The account is eventually consistent: granting the role or minting a key
    // right away can transiently fail because it is not visible yet, so retry
    // those steps with backoff rather than failing and orphaning the account.
    await withPropagationRetry(() =>
      grantSenderRole(accessToken, projectId, email!),
    )

    const key = await withPropagationRetry(() =>
      googleFetch<{ name?: unknown; privateKeyData?: unknown }>(
        'iam',
        `/v1/projects/${encodeURIComponent(projectId)}/serviceAccounts/${encodeURIComponent(email!)}/keys`,
        accessToken,
        {
          method: 'POST',
          body: JSON.stringify({
            privateKeyType: 'TYPE_GOOGLE_CREDENTIALS_FILE',
            keyAlgorithm: 'KEY_ALG_RSA_2048',
          }),
        },
        projectId,
      ),
    )
    if (typeof key.privateKeyData !== 'string') {
      throw new GoogleApiError(
        'Google did not return a service account key',
        502,
      )
    }

    return { serviceAccount: email, secret: decodeKeyJson(key.privateKeyData) }
  } catch (error) {
    // The account was created but provisioning did not finish (blocked key
    // creation, a transient that never cleared, cancellation): remove it so
    // retries don't pile up accounts against the project quota.
    if (email) {
      await deleteServiceAccount(accessToken, projectId, email).catch(() => {})
    }
    if (isScopeError(error)) {
      throw new FcmProvisioningBlockedError(
        'scope',
        'Google was authorized without the access needed to create the credential.',
      )
    }
    if (isKeyCreationBlocked(error)) {
      throw new FcmProvisioningBlockedError(
        'org-policy',
        'This project blocks service account key creation.',
      )
    }
    throw error
  }
}

/**
 * Retry a step that depends on a just-created service account, which Google
 * creates eventually-consistently: a not-yet-visible account surfaces as a
 * not-found error. Only those transient failures are retried, with backoff.
 */
async function withPropagationRetry<T>(run: () => Promise<T>): Promise<T> {
  const backoffMs = [1000, 2000, 4000]
  for (let attempt = 0; ; attempt++) {
    try {
      return await run()
    } catch (error) {
      if (attempt >= backoffMs.length || !isTransientNotFound(error))
        throw error
      await delay(backoffMs[attempt]!)
    }
  }
}

/** A just-created service account that Google does not report as existing yet. */
function isTransientNotFound(error: unknown): boolean {
  if (!(error instanceof GoogleApiError)) return false
  if (error.status === 404) return true
  if (error.status !== 400 && error.status !== 403) return false
  const message = error.message.toLowerCase()
  return message.includes('does not exist') || message.includes('not found')
}

/** Read-modify-write the project IAM policy to add the service account to the FCM role. */
async function grantSenderRole(
  accessToken: string,
  projectId: string,
  email: string,
): Promise<void> {
  // Request v3 so conditional bindings come back intact; with the default
  // version they are masked as `_withcond_*` roles and writing the policy back
  // fails on any project that uses IAM Conditions.
  const policy = await googleFetch<IamPolicy>(
    'crm',
    `/v1/projects/${encodeURIComponent(projectId)}:getIamPolicy`,
    accessToken,
    {
      method: 'POST',
      body: JSON.stringify({ options: { requestedPolicyVersion: 3 } }),
    },
    projectId,
  )

  const member = `serviceAccount:${email}`
  const bindings = policy.bindings ?? []
  // Only ever add to an unconditional binding, leaving conditional ones as-is.
  const existing = bindings.find(
    (binding) => binding.role === FCM_SENDER_ROLE && !binding.condition,
  )
  if (existing) {
    const members = existing.members ?? []
    if (members.includes(member)) return // Already bound; nothing to write.
    existing.members = [...members, member]
  } else {
    bindings.push({ role: FCM_SENDER_ROLE, members: [member] })
  }

  await googleFetch(
    'crm',
    `/v1/projects/${encodeURIComponent(projectId)}:setIamPolicy`,
    accessToken,
    {
      method: 'POST',
      body: JSON.stringify({ policy: { ...policy, version: 3, bindings } }),
    },
    projectId,
  )
}

/** Read-modify-write the project IAM policy to take the service account out of the FCM role. */
async function revokeSenderRole(
  accessToken: string,
  projectId: string,
  email: string,
): Promise<void> {
  const policy = await googleFetch<IamPolicy>(
    'crm',
    `/v1/projects/${encodeURIComponent(projectId)}:getIamPolicy`,
    accessToken,
    {
      method: 'POST',
      body: JSON.stringify({ options: { requestedPolicyVersion: 3 } }),
    },
    projectId,
  )

  const member = `serviceAccount:${email}`
  let changed = false
  const bindings = (policy.bindings ?? []).flatMap((binding) => {
    // Mirror grantSenderRole: only the unconditional binding is ever touched.
    if (binding.role !== FCM_SENDER_ROLE || binding.condition) return [binding]
    const members = binding.members ?? []
    if (!members.includes(member)) return [binding]
    changed = true
    const rest = members.filter((m) => m !== member)
    return rest.length > 0 ? [{ ...binding, members: rest }] : []
  })
  if (!changed) return

  await googleFetch(
    'crm',
    `/v1/projects/${encodeURIComponent(projectId)}:setIamPolicy`,
    accessToken,
    {
      method: 'POST',
      body: JSON.stringify({ policy: { ...policy, version: 3, bindings } }),
    },
    projectId,
  )
}

/**
 * Cleanup when a provisioned credential is abandoned or replaced: remove the
 * account's FCM role binding, then delete the account (which cascades to its
 * key). Google keeps a deleted account's bindings as `deleted:` principals, so
 * the binding goes first. Billed to the same project provisioning used.
 */
export async function deleteServiceAccount(
  accessToken: string,
  projectId: string,
  email: string,
): Promise<void> {
  // Best effort: a failed policy write must not keep the account (and its live
  // key) around, so the delete still runs.
  await revokeSenderRole(accessToken, projectId, email).catch(() => {})
  await googleFetch(
    'iam',
    `/v1/projects/${encodeURIComponent(projectId)}/serviceAccounts/${encodeURIComponent(email)}`,
    accessToken,
    { method: 'DELETE' },
    projectId,
  )
}

/** `privateKeyData` is base64 of the UTF-8 key JSON; decode then pretty-print. */
function decodeKeyJson(privateKeyData: string): string {
  const binary = atob(privateKeyData)
  const bytes = Uint8Array.from(binary, (char) => char.charCodeAt(0))
  const json = new TextDecoder().decode(bytes)
  return JSON.stringify(JSON.parse(json), null, 2)
}
