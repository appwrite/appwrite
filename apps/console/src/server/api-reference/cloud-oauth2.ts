import type { OpenApiSpec } from '@/lib/api-explorer/types'

/**
 * Appwrite Cloud's OAuth 2.0 authorization server: the one the MCP server's
 * protected-resource metadata names, and whose access tokens the Cloud API
 * accepts. Self-hosted Appwrite has no such server, so only the Cloud spec
 * served at /openapi.json declares it.
 */
const CLOUD_OAUTH2_METADATA_URL =
  'https://fra.cloud.appwrite.io/.well-known/oauth-authorization-server/v1/oauth2/console'

const SCOPE_NAMESPACES = ['project', 'organization'] as const

type AuthorizationServerMetadata = {
  authorization_endpoint: string
  token_endpoint: string
  scopes_supported: string[]
}

let metadata: Promise<AuthorizationServerMetadata | null> | null = null

function loadMetadata(): Promise<AuthorizationServerMetadata | null> {
  metadata ??= fetch(CLOUD_OAUTH2_METADATA_URL, {
    signal: AbortSignal.timeout(5_000),
  })
    .then((response) =>
      response.ok
        ? (response.json() as Promise<AuthorizationServerMetadata>)
        : null,
    )
    .catch(() => null)
    .then((loaded) => {
      // Retry on the next request rather than caching a failure.
      if (!loaded) metadata = null
      return loaded
    })
  return metadata
}

/** `project:rows.read` -> `Read rows in a project.` */
function describeScope(scope: string): string {
  const [namespace, permission] = scope.split(':')
  const owner = `${namespace === 'organization' ? 'an' : 'a'} ${namespace}`
  if (permission === 'all') return `Full access to ${owner}.`
  const parts = permission.split('.')
  const access = parts.pop() === 'read' ? 'Read' : 'Create, update, and delete'
  return `${access} ${parts.join(' ')} in ${owner}.`
}

/**
 * Adds an `OAuth2` authorization-code scheme with Cloud's named scopes, and
 * lets each operation be called with an access token holding a scope that
 * matches its `x-appwrite.scope` (any one of them, when it lists several).
 * Returns the spec unchanged when the metadata can't be loaded.
 */
export async function withCloudOAuth2(spec: OpenApiSpec): Promise<OpenApiSpec> {
  const server = await loadMetadata()
  if (!server) return spec

  const scopes = new Set(
    server.scopes_supported.filter((scope) =>
      SCOPE_NAMESPACES.some((namespace) => scope.startsWith(`${namespace}:`)),
    ),
  )
  const decorated = structuredClone(spec)
  decorated.components ??= {}
  // The spec type only models the scheme fields the console reads.
  decorated.components.securitySchemes = Object.assign(
    {},
    decorated.components.securitySchemes,
    {
      OAuth2: {
        type: 'oauth2',
        description:
          'OAuth 2.0 access token from Appwrite Cloud, issued after a person approves the requested scopes. See https://appwrite.io/auth.md.',
        flows: {
          authorizationCode: {
            authorizationUrl: server.authorization_endpoint,
            tokenUrl: server.token_endpoint,
            refreshUrl: server.token_endpoint,
            scopes: Object.fromEntries(
              [...scopes].map((scope) => [scope, describeScope(scope)]),
            ),
          },
        },
      },
    },
  )

  for (const path of Object.values(decorated.paths ?? {})) {
    for (const operation of Object.values(path ?? {})) {
      if (!operation || typeof operation !== 'object') continue
      // A string, or an array of scopes any one of which allows the call.
      const label = (
        operation as { 'x-appwrite'?: { scope?: string | string[] } }
      )['x-appwrite']?.scope
      const granted = (Array.isArray(label) ? label : [label ?? ''])
        .flatMap((name) =>
          SCOPE_NAMESPACES.map((namespace) => `${namespace}:${name.trim()}`),
        )
        .filter((scope) => scopes.has(scope))
      if (granted.length === 0) continue
      const withSecurity = operation as {
        security?: Array<Record<string, string[]>>
      }
      // The token replaces the session or key, not the project (or
      // organization) the call is made in.
      const binding = Object.fromEntries(
        Object.keys(withSecurity.security?.[0] ?? {})
          .filter((name) => name === 'Project' || name === 'Organization')
          .map((name) => [name, []]),
      )
      withSecurity.security = [
        ...(withSecurity.security ?? []),
        ...granted.map((scope) => ({ ...binding, OAuth2: [scope] })),
      ]
    }
  }
  return decorated
}
