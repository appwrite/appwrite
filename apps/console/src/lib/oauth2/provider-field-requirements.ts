/**
 * OAuth2 provider field requirements aligned with Appwrite server:
 * `appwrite/src/Appwrite/Platform/Modules/Project/Http/Project/OAuth2/`
 *
 * PATCH params are all nullable (partial updates). Requirements below apply when
 * enabling a provider (`enabled: true`) or on first-time setup.
 */

export type OAuth2CatalogParameter = {
  $id: string
  name: string
}

export const OIDC_WELL_KNOWN_PARAM_ID = 'wellKnownURL'

/** Manual OIDC discovery endpoints - only used when well-known URL is not set. */
export const OIDC_MANUAL_DISCOVERY_PARAM_IDS = [
  'authorizationURL',
  'tokenURL',
  'userInfoURL',
] as const

export function isOidcManualDiscoveryParam(paramId: string): boolean {
  return (OIDC_MANUAL_DISCOVERY_PARAM_IDS as readonly string[]).includes(
    paramId,
  )
}

export function hasOidcWellKnownUrl(
  formFields: Record<string, string>,
  initialFields: Record<string, string> = {},
): boolean {
  return (
    formFields[OIDC_WELL_KNOWN_PARAM_ID]?.trim() ||
    initialFields[OIDC_WELL_KNOWN_PARAM_ID]?.trim() ||
    ''
  ).length > 0
}

/** Catalog parameter holding the audiences accepted for native ID token sign-in. */
export const NATIVE_CLIENT_IDS_PARAM_ID = 'nativeClientIds'

/**
 * Catalog parameters that carry the browser flow's client ID. The server also
 * accepts that value as a native audience, so it can stand in for the list.
 */
const CLIENT_ID_PARAM_IDS = new Set(['clientId', 'serviceId'])

/** Params that are never required in the console form (server supplies defaults). */
const ALWAYS_OPTIONAL_PARAM_IDS = new Set([
  'authorizationServerId',
  'prompt',
  'tenant',
  NATIVE_CLIENT_IDS_PARAM_ID,
])

/**
 * The server lists the native audience parameter only for providers whose ID
 * tokens it can verify, so the catalog is the source of truth for support.
 */
export function supportsNativeSignIn(
  parameters: OAuth2CatalogParameter[],
): boolean {
  return parameters.some((p) => p.$id === NATIVE_CLIENT_IDS_PARAM_ID)
}

/**
 * Mirrors the server's rule for switching native sign-in on. Tokens are matched
 * to the app through an accepted audience, so a client ID or at least one
 * native client ID must exist. Nothing else is required: this flow never
 * redeems an authorization code, so it has no use for a client secret.
 */
export function getOAuth2NativeSignInError(input: {
  parameters: OAuth2CatalogParameter[]
  nativeEnabled: boolean
  nativeClientIds: string[]
  formFields: Record<string, string>
  initialFields?: Record<string, string>
}): string | undefined {
  if (!input.nativeEnabled) return undefined
  if (input.nativeClientIds.some((id) => id.trim())) return undefined

  const clientIdParam = input.parameters.find((p) =>
    CLIENT_ID_PARAM_IDS.has(p.$id),
  )
  if (
    clientIdParam &&
    effectiveField(
      clientIdParam.$id,
      input.formFields,
      input.initialFields ?? {},
    )
  ) {
    return undefined
  }

  return 'Add at least one native client ID, or set the client ID, so tokens can be matched to your app.'
}

/** Credential fields that may be omitted on update when the provider was already enabled. */
export function isOAuth2SecretParameter(paramId: string): boolean {
  if (paramId === 'p8File') return true
  return /^(clientSecret|appSecret|secret|applicationSecret|secretKey)$/i.test(
    paramId,
  ) || /Secret$/.test(paramId)
}

export function isOAuth2ParameterAlwaysOptional(paramId: string): boolean {
  return ALWAYS_OPTIONAL_PARAM_IDS.has(paramId)
}

/** Whether the field must be filled before enabling / first-time setup. */
export function isOAuth2ParameterRequiredWhenEnabling(
  providerId: string,
  paramId: string,
  initialEnabled: boolean,
): boolean {
  if (isOAuth2ParameterAlwaysOptional(paramId)) return false
  if (providerId === 'oidc' && isOAuth2ParameterOptionalInForm(providerId, paramId)) {
    return false
  }
  if (initialEnabled && isOAuth2SecretParameter(paramId)) return false
  return true
}

export function isOAuth2ParameterOptionalInForm(
  providerId: string,
  paramId: string,
): boolean {
  if (isOAuth2ParameterAlwaysOptional(paramId)) return true
  if (providerId === 'oidc' && isOidcManualDiscoveryParam(paramId)) {
    return true
  }
  return false
}

function effectiveField(
  paramId: string,
  formFields: Record<string, string>,
  initialFields: Record<string, string>,
): string {
  return (formFields[paramId] ?? initialFields[paramId] ?? '').trim()
}

export type OAuth2ProviderFieldErrors = Record<string, string>

export function hasOAuth2ProviderFieldErrors(
  errors: OAuth2ProviderFieldErrors,
): boolean {
  return Object.keys(errors).length > 0
}

/**
 * Per-field validation for the OAuth2 provider drawer.
 * Use `_form` for cross-field messages (e.g. OIDC discovery URLs).
 */
export function getOAuth2ProviderFieldErrors(input: {
  providerId: string
  parameters: OAuth2CatalogParameter[]
  formEnabled: boolean
  formFields: Record<string, string>
  initialEnabled: boolean
  initialFields: Record<string, string>
}): OAuth2ProviderFieldErrors {
  const {
    providerId,
    parameters,
    formEnabled,
    formFields,
    initialEnabled,
    initialFields,
  } = input

  const errors: OAuth2ProviderFieldErrors = {}

  if (!formEnabled) return errors

  /** Provider was already enabled when the drawer opened (secrets may stay blank). */
  const isUpdatingWhileEnabled = initialEnabled

  if (providerId === 'oidc') {
    const wellKnown = effectiveField(OIDC_WELL_KNOWN_PARAM_ID, formFields, initialFields)
    const authUrl = effectiveField('authorizationURL', formFields, initialFields)
    const tokenUrl = effectiveField('tokenURL', formFields, initialFields)
    const userInfoUrl = effectiveField('userInfoURL', formFields, initialFields)
    const hasWellKnown = wellKnown.length > 0
    const hasAllDiscovery =
      authUrl.length > 0 && tokenUrl.length > 0 && userInfoUrl.length > 0
    const hasAnyManualDiscovery =
      authUrl.length > 0 || tokenUrl.length > 0 || userInfoUrl.length > 0

    if (!hasWellKnown && !hasAllDiscovery) {
      if (hasAnyManualDiscovery) {
        for (const paramId of OIDC_MANUAL_DISCOVERY_PARAM_IDS) {
          const raw = effectiveField(paramId, formFields, initialFields)
          if (raw) continue
          const label =
            parameters.find((p) => p.$id === paramId)?.name ?? paramId
          errors[paramId] = `${label} is required when not using a well-known URL`
        }
      } else {
        errors[OIDC_WELL_KNOWN_PARAM_ID] =
          'Well-known URL is required, or set endpoint URLs under Advanced configuration.'
      }
    }
  }

  if (providerId === 'okta') {
    const domain = effectiveField('domain', formFields, initialFields)
    if (!domain) {
      errors.domain = 'Domain is required when enabling this provider.'
    }
  }

  for (const p of parameters) {
    if (isOAuth2ParameterAlwaysOptional(p.$id)) continue
    if (
      providerId === 'oidc' &&
      (isOAuth2ParameterOptionalInForm(providerId, p.$id) ||
        p.$id === OIDC_WELL_KNOWN_PARAM_ID)
    ) {
      continue
    }

    const raw = effectiveField(p.$id, formFields, initialFields)
    if (raw) continue

    if (isUpdatingWhileEnabled && isOAuth2SecretParameter(p.$id)) {
      continue
    }

    errors[p.$id] = `${p.name} is required`
  }

  return errors
}

