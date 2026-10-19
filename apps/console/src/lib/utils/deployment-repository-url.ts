/**
 * Resolve a browser URL for a deployment's VCS repository (GitHub, GitLab, Bitbucket, or custom host).
 */

type DeploymentRepositoryFields = {
  providerRepositoryUrl?: string | null
  providerRepositoryOwner?: string | null
  providerRepositoryName?: string | null
  vcsProvider?: string | null
}

function getVcsProviderKind(
  deployment: DeploymentRepositoryFields,
): 'github' | 'gitlab' | 'bitbucket' | 'origin' | null {
  if (deployment.providerRepositoryUrl) {
    const url = deployment.providerRepositoryUrl.toLowerCase()
    if (url.includes('github.com')) return 'github'
    if (url.includes('gitlab.com')) return 'gitlab'
    if (url.includes('bitbucket.org') || url.includes('bitbucket.com'))
      return 'bitbucket'
    if (url.includes('cursor.com') || url.includes('origin.cursor.com'))
      return 'origin'
  }
  if (deployment.vcsProvider) {
    const p = deployment.vcsProvider.toLowerCase()
    if (p === 'github') return 'github'
    if (p === 'gitlab') return 'gitlab'
    if (p === 'bitbucket') return 'bitbucket'
    if (p === 'origin') return 'origin'
  }
  return null
}

function normalizeRepositoryUrlFromString(raw: string): string | null {
  const trimmed = raw.trim()
  if (!trimmed) return null
  try {
    const u = new URL(trimmed)
    if (u.protocol !== 'http:' && u.protocol !== 'https:') return null
    let path = u.pathname.replace(/\.git$/i, '')
    if (path.endsWith('/')) path = path.slice(0, -1)
    return `${u.origin}${path}`
  } catch {
    return null
  }
}

/**
 * Returns an https (or http) URL to open the repository in the browser, or null if unknown.
 */
export function getDeploymentRepositoryWebUrl(
  deployment: DeploymentRepositoryFields,
): string | null {
  const fromApi = deployment.providerRepositoryUrl
    ? normalizeRepositoryUrlFromString(deployment.providerRepositoryUrl)
    : null
  if (fromApi) return fromApi

  const owner = deployment.providerRepositoryOwner
  const name = deployment.providerRepositoryName
  if (!owner || !name) return null

  const kind = getVcsProviderKind(deployment)
  if (!kind) return null

  if (kind === 'github') {
    return `https://github.com/${owner}/${name}`
  }
  if (kind === 'gitlab') {
    return `https://gitlab.com/${owner}/${name}`
  }
  if (kind === 'origin') {
    return `https://cursor.com/codebase/${owner}/${name}`
  }
  return `https://bitbucket.org/${owner}/${name}`
}
