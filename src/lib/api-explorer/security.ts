import type { ApiExplorerMethod } from './types'

/** Scheme membership only: OAuth scope requirements stay on each alternative. */
export function getAcceptedSecuritySchemeNames(
  method: ApiExplorerMethod,
): string[] {
  return [...new Set(method.security?.flatMap(Object.keys) ?? [])]
}

export function getRequiredSecuritySchemeNames(
  method: ApiExplorerMethod,
): string[] {
  const alternatives = method.security ?? []
  if (alternatives.length === 0) return []
  return Object.keys(alternatives[0]!).filter((name) =>
    alternatives.every((requirement) => Object.hasOwn(requirement, name)),
  )
}
