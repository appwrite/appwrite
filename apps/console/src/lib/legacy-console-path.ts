/**
 * Rewrites pre-2.0 console URLs to vibes root-path routes.
 *
 * Legacy console lived under `/console` with typed resource segments such as
 * `project-{region}-{id}`, `site-{id}`, and `function-{id}`. Vibes serves the
 * console at the host root with plural resource collections and bare IDs.
 */

import { EDUCATION_JOIN_PATH } from './education/paths'

export const LEGACY_CONSOLE_ORIGIN = 'https://cloud.appwrite.io'

export function getLegacyConsoleOrganizationBillingUrl(
  organizationId: string,
): string {
  return `${LEGACY_CONSOLE_ORIGIN}/console/organization-${organizationId}/billing`
}

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
  'variable',
  'installation',
  'domain',
] as const

const TYPED_RESOURCE_RE = new RegExp(
  `^(${TYPED_RESOURCE_PREFIXES.join('|')})-(.+)$`,
  'i',
)

// Team invite emails and external sign-up links still point at the legacy auth
// pages, which vibes renamed.
const AUTH_PATHS = new Map([
  ['invite', '/join'],
  ['login', '/sign-in'],
  ['register', '/sign-up'],
])

/**
 * Returns true when the pathname looks like a legacy console deep link that
 * should be redirected before the SPA handles it.
 */
export function isLegacyConsolePath(pathname: string): boolean {
  const normalized = pathname.replace(/\/+$/, '') || '/'
  if (normalized === '/console' || normalized.startsWith('/console/')) {
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
 * Returns a pathname only; callers should re-attach `search` / hash. `search`
 * is read solely to pick the password recovery page.
 *
 * GitHub authorization comments used `/console/git/authorize-contributor`.
 * Stripping `/console` maps that to `/git/authorize-contributor` with the
 * original query string (`projectId`, `installationId`, `repositoryId`,
 * `providerPullRequestId`) intact.
 */
export function rewriteLegacyConsolePath(
  pathname: string,
  search = '',
): string {
  let path = pathname
  let wasConsolePrefixed = false

  if (path === '/console' || path.startsWith('/console/')) {
    path = path.slice('/console'.length)
    wasConsolePrefixed = true
  }

  if (path === '' || path === '/') {
    return '/'
  }

  // The GitHub Student Developer Pack and the marketing site send students to
  // `/console/education`, which was the education sign-up flow. Stripping
  // `/console` would land them on the `/education` marketing page instead, so
  // those links go to the flow's new home.
  if (
    wasConsolePrefixed &&
    (path === '/education' || path.startsWith('/education/'))
  ) {
    return EDUCATION_JOIN_PATH
  }

  if (
    wasConsolePrefixed &&
    (path === '/onboarding' || path.startsWith('/onboarding/'))
  ) {
    return '/'
  }

  const authSegment = wasConsolePrefixed ? (path.split('/')[1] ?? '') : ''

  // Recovery emails link `/console/recover` with `userId` and `secret`, which
  // `/reset` requires; the bare path was the forgot-password form, now
  // `/recovery`.
  if (authSegment === 'recover') {
    const params = new URLSearchParams(search)
    return params.get('userId') && params.get('secret') ? '/reset' : '/recovery'
  }

  const authPath = AUTH_PATHS.get(authSegment)
  if (authPath) {
    return authPath
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

    // Billing emails link to the organization usage, plan and invoice pages;
    // vibes folds all of them into billing settings.
    if (
      out[0] === 'organizations' &&
      out.length === 2 &&
      ['usage', 'change-plan', 'invoices'].includes(segment)
    ) {
      out.push('settings', 'billing')
      break
    }

    // Platforms and API keys were tabs of the project overview; vibes serves
    // them from the project root and calls platforms apps.
    if (out[0] === 'projects' && out.length === 2 && segment === 'overview') {
      continue
    }
    if (out[0] === 'projects' && out.length === 2 && segment === 'platforms') {
      out.push('apps')
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

    // Git installation callbacks returned to settings/git-installations; vibes
    // renders git configuration on the settings page itself.
    if (segment === 'git-installations' && out[out.length - 1] === 'settings') {
      continue
    }

    // Webhook pause emails deep-linked to a specific webhook by its bare ID;
    // vibes only has the list page under settings/webhooks.
    if (
      out[out.length - 1] === 'webhooks' &&
      out[out.length - 2] === 'settings'
    ) {
      continue
    }

    // Creation wizards lived under `create-function` and `create-site`, with
    // templates at `create-function/template-{id}` and
    // `create-site/templates/template-{id}`.
    if (/^create-(function|site)$/i.test(segment)) {
      out.push('create')
      continue
    }

    const templateMatch = segment.match(/^template-(.+)$/i)
    if (templateMatch && out.includes('create')) {
      if (out[out.length - 1] === 'create') {
        out.push('template')
      }
      out.push(templateMatch[1]!)
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
