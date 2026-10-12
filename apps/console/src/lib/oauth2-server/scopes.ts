export const REQUIRED_OAUTH2_SCOPES = [
  'openid',
  'profile',
  'email',
  'phone',
] as const

export type RequiredOAuth2Scope = (typeof REQUIRED_OAUTH2_SCOPES)[number]

const REQUIRED_SCOPE_SET = new Set<string>(REQUIRED_OAUTH2_SCOPES)

export function isRequiredOAuth2Scope(scope: string): scope is RequiredOAuth2Scope {
  return REQUIRED_SCOPE_SET.has(scope)
}

/** Ensures required OIDC scopes are always present, in a stable order. */
export function mergeOAuth2Scopes(scopes: string[]): string[] {
  const optional = scopes.filter((scope) => !isRequiredOAuth2Scope(scope))
  return [...REQUIRED_OAUTH2_SCOPES, ...optional]
}

export function optionalOAuth2Scopes(scopes: string[]): string[] {
  return scopes.filter((scope) => !isRequiredOAuth2Scope(scope))
}

export function oauth2ScopesEqual(a: string[], b: string[]): boolean {
  const mergedA = mergeOAuth2Scopes(a)
  const mergedB = mergeOAuth2Scopes(b)
  return (
    mergedA.length === mergedB.length &&
    mergedA.every((scope, index) => scope === mergedB[index])
  )
}
