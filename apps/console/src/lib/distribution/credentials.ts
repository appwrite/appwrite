import type { DistributionGoogleServiceAccount } from '@/lib/appwrite/distribution'

export const APP_STORE_CONNECT_KEYS_URL =
  'https://appstoreconnect.apple.com/access/integrations/api'

export const ANDROID_PUBLISHER_API_URL =
  'https://console.cloud.google.com/apis/library/androidpublisher.googleapis.com'

export const SERVICE_ACCOUNTS_URL =
  'https://console.cloud.google.com/iam-admin/serviceaccounts'

export const PLAY_CONSOLE_URL = 'https://play.google.com/console'

const APPLE_KEY_ID_LENGTH = 10
const UUID_GROUP_LENGTHS = [8, 4, 4, 4, 12]
const HEX_DIGITS = '0123456789abcdefABCDEF'
const UPPERCASE_ALPHANUMERIC = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'

function consistsOf(value: string, characters: string): boolean {
  return [...value].every((character) => characters.includes(character))
}

export function appleKeyIdError(keyId: string): string | null {
  const value = keyId.trim()
  if (!value) return 'Enter the key ID.'
  if (
    value.length !== APPLE_KEY_ID_LENGTH ||
    !consistsOf(value, UPPERCASE_ALPHANUMERIC)
  ) {
    return 'A key ID is 10 uppercase letters and digits, like 2X9R4HXF34.'
  }
  return null
}

export function appleIssuerIdError(issuerId: string): string | null {
  const value = issuerId.trim()
  if (!value) {
    return 'Enter the issuer ID. Individual keys have none, so use a team key.'
  }
  const groups = value.split('-')
  const valid =
    groups.length === UUID_GROUP_LENGTHS.length &&
    groups.every(
      (group, index) =>
        group.length === UUID_GROUP_LENGTHS[index] &&
        consistsOf(group, HEX_DIGITS),
    )
  return valid
    ? null
    : 'An issuer ID looks like 69a6de7e-0b3c-47e3-e053-5b8c7c11a4d1.'
}

export function applePrivateKeyError(privateKey: string): string | null {
  const value = privateKey.trim()
  if (!value) return 'Add the .p8 private key.'
  if (
    !value.includes('-----BEGIN PRIVATE KEY-----') ||
    !value.includes('-----END PRIVATE KEY-----')
  ) {
    return 'The .p8 file must contain a PEM private key.'
  }
  return null
}

export type ServiceAccountResult =
  | { serviceAccount: DistributionGoogleServiceAccount; error: null }
  | { serviceAccount: null; error: string }

export function parseServiceAccount(text: string): ServiceAccountResult {
  const value = text.trim()
  if (!value) {
    return { serviceAccount: null, error: 'Add the service account JSON key.' }
  }
  let parsed: unknown
  try {
    parsed = JSON.parse(value)
  } catch {
    return { serviceAccount: null, error: 'The key is not valid JSON.' }
  }
  if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
    return { serviceAccount: null, error: 'The key is not valid JSON.' }
  }
  const key = parsed as Record<string, unknown>
  if (key.type !== undefined && key.type !== 'service_account') {
    return {
      serviceAccount: null,
      error:
        'This is not a service account key. Create a JSON key for a service account.',
    }
  }
  if (
    typeof key.client_email !== 'string' ||
    !key.client_email ||
    typeof key.private_key !== 'string' ||
    !key.private_key
  ) {
    return {
      serviceAccount: null,
      error:
        'The key needs client_email and private_key. Download a new JSON key.',
    }
  }
  const serviceAccount: DistributionGoogleServiceAccount = {
    client_email: key.client_email,
    private_key: key.private_key,
    ...(typeof key.project_id === 'string' && key.project_id
      ? { project_id: key.project_id }
      : {}),
  }
  return { serviceAccount, error: null }
}
