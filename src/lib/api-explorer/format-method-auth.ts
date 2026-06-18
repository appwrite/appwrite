import type { ApiExplorerMethod } from './types'

export const IMPERSONATION_DOCS_HREF = '/docs/products/auth/impersonation'

const USER_AUTH_SCHEMES = new Set(['Session', 'JWT', 'Cookie'])

const IMPERSONATION_SCHEMES = new Set([
  'ImpersonateUserId',
  'ImpersonateUserEmail',
  'ImpersonateUserPhone',
])

function getAuthMap(method: ApiExplorerMethod): Record<string, string[]> {
  return method.xAppwrite?.auth ?? {}
}

function getSecurityMap(method: ApiExplorerMethod): Record<string, string[]> {
  return method.security?.[0] ?? {}
}

function getRequiredSchemeNames(method: ApiExplorerMethod): string[] {
  const auth = getAuthMap(method)
  if (Object.keys(auth).length > 0) {
    return Object.keys(auth)
  }

  const security = getSecurityMap(method)
  if (Object.keys(security).length > 0) {
    return Object.keys(security)
  }

  return ['Project']
}

function getUserAuthOptions(method: ApiExplorerMethod): string[] {
  const auth = getAuthMap(method)
  const security = getSecurityMap(method)
  const options: string[] = []
  const seen = new Set<string>()

  for (const scheme of [...Object.keys(auth), ...Object.keys(security)]) {
    if (!USER_AUTH_SCHEMES.has(scheme) || seen.has(scheme)) continue
    seen.add(scheme)
    options.push(scheme)
  }

  return options
}

function getImpersonationSchemes(method: ApiExplorerMethod): string[] {
  const schemes: string[] = []
  const seen = new Set<string>()

  for (const scheme of [
    ...Object.keys(getAuthMap(method)),
    ...Object.keys(getSecurityMap(method)),
  ]) {
    if (!IMPERSONATION_SCHEMES.has(scheme) || seen.has(scheme)) continue
    seen.add(scheme)
    schemes.push(scheme)
  }

  return schemes
}

function securityHasOnlyProject(method: ApiExplorerMethod): boolean {
  const schemes = Object.keys(getSecurityMap(method))
  return (
    schemes.length > 0 &&
    schemes.every((scheme) => scheme === 'Project' || scheme === 'ProjectPath')
  )
}

function isProjectOnlyGuestEndpoint(method: ApiExplorerMethod): boolean {
  if (['avatars', 'locale'].includes(method.service)) {
    return true
  }

  if (method.service !== 'account') {
    return false
  }

  const { path, httpMethod } = method

  if (path === '/account' && httpMethod === 'post') return true
  if (path.startsWith('/account/recovery')) return true
  if (path.startsWith('/account/tokens/') && httpMethod === 'post') return true
  if (path.startsWith('/account/sessions/') && httpMethod === 'post') return true
  if (path.startsWith('/account/sessions/oauth2/') && httpMethod === 'get') {
    return true
  }
  if (path === '/account/sessions/magic-url' && httpMethod === 'put') return true
  if (path === '/account/sessions/phone' && httpMethod === 'put') return true

  return false
}

function requiresKeyInAuth(method: ApiExplorerMethod): boolean {
  return 'Key' in getAuthMap(method)
}

function allowsApiKeyInSecurity(method: ApiExplorerMethod): boolean {
  return 'Key' in getSecurityMap(method)
}

function isClientPlatform(platform?: string): boolean {
  return platform === 'client' || Boolean(platform?.startsWith('client-'))
}

function isServerPlatform(platform?: string): boolean {
  return platform === 'server' || Boolean(platform?.startsWith('server-'))
}

function isConsolePlatform(platform?: string): boolean {
  return platform === 'console'
}

function allowsApiKeyAlternative(
  method: ApiExplorerMethod,
  platform?: string,
): boolean {
  if (!isServerPlatform(platform) || !allowsApiKeyInSecurity(method)) {
    return false
  }

  return !requiresKeyInAuth(method)
}

function requiresUserAuthentication(
  method: ApiExplorerMethod,
  platform?: string,
): boolean {
  const auth = getAuthMap(method)

  if (Object.keys(auth).some((scheme) => USER_AUTH_SCHEMES.has(scheme))) {
    return true
  }

  if ('Key' in auth && !('Session' in auth) && !('JWT' in auth)) {
    return false
  }

  if (isProjectOnlyGuestEndpoint(method)) {
    return false
  }

  if (platform === 'console' && securityHasOnlyProject(method)) {
    return false
  }

  return getUserAuthOptions(method).length > 0
}

type AuthDescriptionContext = {
  requiresProject: boolean
  requiresKey: boolean
  allowsApiKeyAlt: boolean
  requiresUser: boolean
  allowsUserWithKey: boolean
  userAuthOptions: string[]
  impersonationSchemes: string[]
}

function buildAuthDescriptionContext(
  method: ApiExplorerMethod,
  platform?: string,
): AuthDescriptionContext {
  const required = getRequiredSchemeNames(method)
  const userAuthOptions = getUserAuthOptions(method)

  return {
    requiresProject:
      required.includes('Project') || required.includes('ProjectPath'),
    requiresKey: requiresKeyInAuth(method),
    allowsApiKeyAlt: allowsApiKeyAlternative(method, platform),
    requiresUser: requiresUserAuthentication(method, platform),
    allowsUserWithKey:
      requiresKeyInAuth(method) &&
      userAuthOptions.length > 0 &&
      !requiresUserAuthentication(method, platform),
    userAuthOptions,
    impersonationSchemes: getImpersonationSchemes(method),
  }
}

function formatRestSentence(
  ctx: AuthDescriptionContext,
  platform?: string,
): string | null {
  if (isClientPlatform(platform)) {
    return null
  }

  const {
    requiresProject,
    requiresKey,
    requiresUser,
    allowsApiKeyAlt,
    allowsUserWithKey,
    userAuthOptions,
  } = ctx

  if (!requiresUser && !requiresKey && !allowsApiKeyAlt) {
    return null
  }

  const project = requiresProject ? '`X-Appwrite-Project`' : null

  if (requiresKey && !requiresUser && !allowsUserWithKey && project) {
    return `For direct REST calls, send ${project} and \`X-Appwrite-Key\`.`
  }

  if (requiresProject && requiresUser && allowsApiKeyAlt && userAuthOptions.length > 0) {
    return `For direct REST calls, send ${project} with either \`X-Appwrite-Key\` or session/JWT headers.`
  }

  if (requiresProject && requiresUser && userAuthOptions.length > 0) {
    return `For direct REST calls, send ${project} with session/JWT headers.`
  }

  if (requiresKey && allowsUserWithKey && userAuthOptions.length > 0 && project) {
    return `For direct REST calls, send ${project} with either \`X-Appwrite-Key\` or session/JWT headers.`
  }

  return null
}

function buildAuthSummary(
  ctx: AuthDescriptionContext,
  method: ApiExplorerMethod,
  platform?: string,
): string {
  const {
    requiresProject,
    requiresKey,
    allowsApiKeyAlt,
    requiresUser,
    allowsUserWithKey,
    userAuthOptions,
  } = ctx

  const sentences: string[] = []

  if (!requiresProject && requiresKey) {
    sentences.push(
      'Initialize the Appwrite client with a server API key (`setKey()`).',
    )
  } else if (requiresKey && allowsUserWithKey && userAuthOptions.length > 0) {
    sentences.push(
      'Initialize the Appwrite client with `setProject()` and a server API key (`setKey()`), or authenticate as a signed-in user (`setSession()` or `setJWT()`).',
    )
  } else if (requiresProject && requiresKey && !requiresUser) {
    sentences.push(
      'Initialize the Appwrite client with `setProject()` and a server API key (`setKey()`).',
    )
  } else if (
    requiresProject &&
    requiresUser &&
    allowsApiKeyAlt &&
    userAuthOptions.length > 0
  ) {
    sentences.push(
      'Initialize the Appwrite client with `setProject()` and authenticate with either a server API key (`setKey()`) or a signed-in user (`setSession()` or `setJWT()`).',
    )
  } else if (requiresProject && requiresUser && userAuthOptions.length > 0) {
    if (isClientPlatform(platform)) {
      sentences.push(
        'Initialize the Appwrite client with `setProject()` and ensure the user is signed in. The SDK sends the session header automatically after login.',
      )
    } else {
      sentences.push(
        'Initialize the Appwrite client with `setProject()` and a signed-in user (`setSession()` or `setJWT()`).',
      )
    }
  } else if (
    isConsolePlatform(platform) &&
    securityHasOnlyProject(method) &&
    requiresProject &&
    !requiresUser &&
    !requiresKey
  ) {
    sentences.push(
      'Initialize the Appwrite client with `setProject()`. The Console uses your browser session for cloud account login.',
    )
  } else if (requiresProject && !requiresKey && !requiresUser) {
    sentences.push(
      'Initialize the Appwrite client with `setProject()`. No signed-in user or server API key is required.',
    )
  } else {
    sentences.push('See the endpoint security requirements before calling this route.')
  }

  const rest = formatRestSentence(ctx, platform)
  if (rest) {
    sentences.push(rest)
  }

  return sentences.join(' ')
}

export type MethodAuthDescription = {
  summary: string
  impersonation?: boolean
}

export function getMethodAuthDescription(
  method: ApiExplorerMethod,
  platform?: string,
): MethodAuthDescription {
  const ctx = buildAuthDescriptionContext(method, platform)

  return {
    summary: buildAuthSummary(ctx, method, platform),
    impersonation: ctx.impersonationSchemes.length > 0,
  }
}

export function formatMethodAuthDescription(
  method: ApiExplorerMethod,
  platform?: string,
): string {
  const description = getMethodAuthDescription(method, platform)
  const lines = [description.summary]

  if (description.impersonation) {
    lines.push(
      `Supports optional user impersonation. Learn more: ${IMPERSONATION_DOCS_HREF}`,
    )
  }

  return lines.join('\n\n')
}
