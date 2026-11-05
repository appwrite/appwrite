/**
 * Google / FCM specifics for the messaging FCM quick setup. Everything that is
 * provider-agnostic (the OAuth2 round trip, token expiry, the minted-credential
 * tracker) is reused from `src/lib/smtp/quick-setup.ts`; this module holds only
 * what is specific to provisioning an FCM service account through Google Cloud.
 *
 * Safe to import from both the browser and the `_api` server routes.
 */

/**
 * Managing service accounts, their keys, and the project IAM policy all sit
 * under one broad scope. `cloud-platform` is what lets the console create the
 * sending credential on the user's behalf; a narrower scope cannot create keys.
 */
export const GOOGLE_OAUTH_SCOPES = [
  'https://www.googleapis.com/auth/cloud-platform',
]

/** Where the user manages Firebase projects, shown when none are reachable. */
export const FIREBASE_CONSOLE_URL = 'https://console.firebase.google.com'

/**
 * IAM role the minted service account is granted on the project so the key it
 * carries can send Firebase Cloud Messaging messages. Granting it needs
 * `resourcemanager.projects.setIamPolicy`, one of the preflight permissions.
 */
export const FCM_SENDER_ROLE = 'roles/firebasecloudmessaging.admin'

/**
 * Permissions the provisioning flow needs on the project. Checked up front with
 * `testIamPermissions`, which any project member may call, so a missing one is
 * reported cleanly instead of surfacing as a mid-flow failure. Org policy
 * (`iam.disableServiceAccountKeyCreation`) is deliberately not here: it is not
 * an IAM permission and reading it needs rights most developers lack, so it can
 * only be discovered when key creation itself fails.
 *
 * `serviceusage.services.use` is required because provisioning calls bill the
 * project through `X-Goog-User-Project`, which Google rejects without it.
 */
export const FCM_PROVISIONING_PERMISSIONS = [
  'iam.serviceAccounts.create',
  'iam.serviceAccountKeys.create',
  'resourcemanager.projects.setIamPolicy',
  'serviceusage.services.use',
] as const

/**
 * Services the flow enables on the selected project before provisioning, so a
 * user who owns the project never has to turn them on by hand in the Google
 * console. The listing call hits `firebase.googleapis.com` against the OAuth
 * client's project before a project is picked, so that one stays an operator
 * prerequisite and is not enabled here.
 */
export const FCM_REQUIRED_SERVICES = [
  'cloudresourcemanager.googleapis.com',
  'iam.googleapis.com',
  'fcm.googleapis.com',
] as const

/** Why the one-click flow could not provision a key and fell back to manual. */
export type FcmProvisioningBlock =
  /** Consent returned without the cloud-platform scope; re-consent is needed. */
  | 'scope'
  /** The account lacks one of {@link FCM_PROVISIONING_PERMISSIONS}. */
  | 'insufficient-iam'
  /** Org policy disables service account key creation for this project. */
  | 'org-policy'

/** Up to 30 chars, must start with a letter, then lowercase letters/digits/`-`. */
export function buildServiceAccountId(
  randomSuffix: string = randomAccountSuffix(),
): string {
  return `appwrite-fcm-${randomSuffix}`
}

/**
 * Stable identity of a service account key — its `private_key_id` — from the
 * key JSON (a string or already-parsed object). Used to decide whether a saved
 * credential is still the minted one even after the user reformats the JSON, so
 * the unmount cleanup never revokes a key the new provider actually uses.
 * Returns '' when it can't be determined, which never matches a real key id.
 */
export function serviceAccountKeyId(json: unknown): string {
  try {
    const parsed = typeof json === 'string' ? JSON.parse(json) : json
    const id = (parsed as { private_key_id?: unknown })?.private_key_id
    return typeof id === 'string' ? id : ''
  } catch {
    return ''
  }
}

/**
 * What the one-click panel does once provisioning resolves. `cleanup` means the
 * user left while it was in flight, so the account must be revoked and the form
 * left alone; `fill` applies the credential, defaulting the provider name only
 * when the latest form value is still empty (a name typed during setup wins).
 */
export type ProvisionCompletion =
  | { kind: 'cleanup' }
  | { kind: 'fill'; name?: string }

export function planProvisionCompletion(input: {
  mounted: boolean
  currentName: string
  defaultName: string
}): ProvisionCompletion {
  if (!input.mounted) return { kind: 'cleanup' }
  return {
    kind: 'fill',
    name: input.currentName.trim() ? undefined : input.defaultName,
  }
}

/** 8 lowercase-alphanumeric chars, keeping the whole id inside IAM's 30-char cap. */
function randomAccountSuffix(): string {
  const alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789'
  let suffix = ''
  const randomValues = new Uint32Array(8)
  crypto.getRandomValues(randomValues)
  for (const value of randomValues) {
    suffix += alphabet[value % alphabet.length]
  }
  return suffix
}

/** Human-readable label on the service account, trimmed to IAM's 100-char cap. */
export function buildServiceAccountDisplayName(projectName: string): string {
  const prefix = 'Appwrite FCM'
  const trimmed = projectName.trim()
  const name = trimmed ? `${prefix}: ${trimmed}` : prefix
  return name.length <= 100 ? name : name.slice(0, 100).trimEnd()
}

/**
 * The `service` segments the `_api/google` relay forwards to, mapped to their
 * Google API host. The relay only reaches these hosts, and only with the
 * caller's own bearer token, so it adds a CORS bridge and an origin guard
 * without widening what the token can already do.
 */
export const GOOGLE_RELAY_HOSTS = {
  firebase: 'firebase.googleapis.com',
  crm: 'cloudresourcemanager.googleapis.com',
  iam: 'iam.googleapis.com',
  serviceusage: 'serviceusage.googleapis.com',
} as const

export type GoogleRelayService = keyof typeof GOOGLE_RELAY_HOSTS

export function isGoogleRelayService(
  value: string,
): value is GoogleRelayService {
  return value in GOOGLE_RELAY_HOSTS
}
