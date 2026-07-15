/**
 * Rewrites pre-2.0 console URLs to vibes root-path routes.
 *
 * Legacy console lived under `/console` with typed resource segments such as
 * `project-{region}-{id}`, `site-{id}`, and `function-{id}`. Vibes serves the
 * console at the host root with plural resource collections and bare IDs.
 */

const TYPED_RESOURCE_PREFIXES = [
  'site',
  'function',
  'topic',
  'user',
  'team',
  'bucket',
  'database',
  'collection',
  'table',
  'provider',
  'message',
  'platform',
  'key',
  'devkey',
  'variable',
  'installation',
] as const

const TYPED_RESOURCE_RE = new RegExp(
  `^(${TYPED_RESOURCE_PREFIXES.join('|')})-(.+)$`,
  'i',
)

/**
 * Returns true when the pathname looks like a legacy console deep link that
 * should be redirected before the SPA handles it.
 */
export function isLegacyConsolePath(pathname: string): boolean {
  if (pathname === '/console' || pathname.startsWith('/console/')) {
    return true
  }

  const first = pathname.replace(/^\/+/, '').split('/')[0] ?? ''
  return (
    /^project-[a-z0-9]+-.+/i.test(first) ||
    /^organization-.+/i.test(first)
  )
}

/**
 * Maps a legacy console pathname to its vibes equivalent.
 * Preserves path-only semantics; callers should re-attach `search` / hash.
 */
export function rewriteLegacyConsolePath(pathname: string): string {
  let path = pathname

  if (path === '/console' || path.startsWith('/console/')) {
    path = path.slice('/console'.length)
  }

  if (path === '' || path === '/') {
    return '/'
  }

  if (!path.startsWith('/')) {
    path = `/${path}`
  }

  const segments = path.split('/').filter(Boolean)
  const out: string[] = []

  for (let i = 0; i < segments.length; i++) {
    const segment = segments[i]!

    const projectMatch = segment.match(/^project-([a-z0-9]+)-(.+)$/i)
    if (projectMatch) {
      out.push('projects', projectMatch[2]!)
      continue
    }

    const organizationMatch = segment.match(/^organization-(.+)$/i)
    if (organizationMatch) {
      out.push('organizations', organizationMatch[1]!)
      continue
    }

    // Sites used `/deployments/deployment-{id}`; functions used a sibling
    // `/deployment-{id}` segment. Vibes always wants `/deployments/{id}`.
    const deploymentMatch = segment.match(/^deployment-(.+)$/i)
    if (deploymentMatch) {
      if (out[out.length - 1]?.toLowerCase() !== 'deployments') {
        out.push('deployments')
      }
      out.push(deploymentMatch[1]!)
      continue
    }

    // Webhook pause emails deep-linked to a specific webhook; vibes only has
    // the list page under settings/webhooks.
    if (
      segment.toLowerCase().startsWith('webhook-') &&
      out[out.length - 1] === 'webhooks'
    ) {
      continue
    }

    const typedMatch = segment.match(TYPED_RESOURCE_RE)
    if (typedMatch) {
      out.push(typedMatch[2]!)
      continue
    }

    out.push(segment)
  }

  return `/${out.join('/')}`
}
