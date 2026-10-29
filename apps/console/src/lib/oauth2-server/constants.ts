/**
 * Limits and defaults of a project's OAuth2 server, mirrored from the cloud
 * backend (`app/init/constants.php`). The API validates against these values,
 * so keep them in sync when the server changes.
 */

/** Token lifetimes (seconds) accepted by the API. */
export const OAUTH2_SERVER_MIN_TOKEN_EXPIRY = 60
export const OAUTH2_SERVER_MAX_TOKEN_EXPIRY = 31536000

/** Defaults applied by the server when a lifetime is left empty. */
export const OAUTH2_SERVER_ACCESS_TOKEN_EXPIRY = 28800
export const OAUTH2_SERVER_REFRESH_TOKEN_EXPIRY = 31536000
export const OAUTH2_SERVER_PUBLIC_ACCESS_TOKEN_EXPIRY = 3600
export const OAUTH2_SERVER_PUBLIC_REFRESH_TOKEN_EXPIRY = 2592000
export const OAUTH2_SERVER_INSTALLATION_ACCESS_TOKEN_EXPIRY = 3600

/** Device authorization grant (RFC 8628). */
export const OAUTH2_SERVER_DEVICE_CODE_EXPIRY = 600
export const OAUTH2_SERVER_DEVICE_CODE_MIN_EXPIRY = 60
export const OAUTH2_SERVER_DEVICE_CODE_MAX_EXPIRY = 1800
export const OAUTH2_SERVER_USER_CODE_LENGTH = 8
export const OAUTH2_SERVER_USER_CODE_MIN_LENGTH = 6
export const OAUTH2_SERVER_USER_CODE_MAX_LENGTH = 12

export const OAUTH2_SERVER_USER_CODE_FORMATS = [
  'alphanumeric',
  'numeric',
  'alphabetic',
] as const
export type OAuth2ServerUserCodeFormat =
  (typeof OAUTH2_SERVER_USER_CODE_FORMATS)[number]
export const OAUTH2_SERVER_USER_CODE_FORMAT: OAuth2ServerUserCodeFormat =
  'alphanumeric'

/** Labels and hints are translated at the render site. */
export const OAUTH2_SERVER_USER_CODE_FORMAT_OPTIONS: {
  value: OAuth2ServerUserCodeFormat
  label: string
  hint: string
}[] = [
  {
    value: 'alphanumeric',
    label: 'Alphanumeric',
    hint: 'Letters and digits. Highest entropy per character.',
  },
  {
    value: 'numeric',
    label: 'Numeric',
    hint: 'Digits only. Best for numeric keypads and TV remotes.',
  },
  {
    value: 'alphabetic',
    label: 'Alphabetic',
    hint: 'Letters only.',
  },
]

export function normalizeOAuth2UserCodeFormat(
  value: string | null | undefined,
): OAuth2ServerUserCodeFormat {
  return (OAUTH2_SERVER_USER_CODE_FORMATS as readonly string[]).includes(
    value ?? '',
  )
    ? (value as OAuth2ServerUserCodeFormat)
    : OAUTH2_SERVER_USER_CODE_FORMAT
}

/**
 * Scope-like list params (scopes, default scopes, installation scopes,
 * authorization_details types): APP_LIMIT_ARRAY_PARAMS_SIZE items of Text(128).
 */
export const OAUTH2_SERVER_MAX_LIST_ITEMS = 100
export const OAUTH2_SERVER_MAX_LIST_ITEM_LENGTH = 128

export function isOAuth2ListWithinLimits(values: string[]): boolean {
  return (
    values.length <= OAUTH2_SERVER_MAX_LIST_ITEMS &&
    values.every((value) => value.length <= OAUTH2_SERVER_MAX_LIST_ITEM_LENGTH)
  )
}
