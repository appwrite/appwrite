import type { Models } from '@appwrite.io/console'
import {
  ALL_SCOPE,
  PROJECT_ALL_SCOPE,
  ORGANIZATION_ALL_SCOPE,
  PROJECT_SCOPE_PREFIX,
  ORGANIZATION_SCOPE_PREFIX,
  scopeAction,
  scopeResource,
  type ConsentScopeModel,
  type TierScopes,
} from '@/lib/oauth2/scopes'
import { getEffectiveMcpEndpointUrl } from '@/lib/debug-mcp-endpoint'

/**
 * MCP-grant detection and consent-time scope narrowing for the hosted Appwrite
 * MCP server (granular MCP scopes design).
 *
 * The MCP server advertises the authorization server's full scope catalog, so
 * MCP clients mechanically request *everything*. The consent screen detects
 * those grants - the grant's RFC 8707 `resources` contains the MCP server's
 * canonical resource URI - and only then offers a narrowing editor. Because
 * every granular scope was literally requested, whatever the user narrows to is
 * a plain subset that the approve endpoint's literal check already accepts.
 *
 * The detection signal is self-fulfilling: the resource value becomes the
 * token's `aud`, and the MCP server hard-rejects foreign audiences, so a lying
 * client gains nothing by claiming the MCP resource URI.
 */

/** Mirror of the authorization server's `scope` parameter length cap. */
export const MAX_SCOPE_PARAM_LENGTH = 8192

/** Normalize an RFC 8707 resource URI for comparison: trailing slashes only -
 *  resource indicators are canonical URIs, so anything else compares exactly. */
export function normalizeResourceUrl(url: string): string {
  return url.trim().replace(/\/+$/, '')
}

/**
 * Expand a single MCP endpoint into the resource URI shapes the server may
 * advertise (origin, `/mcp`, trailing-slash variants). Driven by
 * `VITE_APPWRITE_MCP_URL` / debug MCP override via {@link getEffectiveMcpEndpointUrl}.
 */
export function mcpResourceUrlsForEndpoint(mcpUrl: string): string[] {
  const normalized = normalizeResourceUrl(mcpUrl)
  if (!normalized) return []

  const aliases = new Set<string>([normalized])
  try {
    const url = new URL(normalized)
    aliases.add(url.origin)
    const path = url.pathname.replace(/\/+$/, '') || '/'
    if (path === '/') {
      aliases.add(`${url.origin}/mcp`)
    } else if (path === '/mcp') {
      aliases.add(url.origin)
    }
  } catch {
    // keep the normalized string only
  }
  return [...aliases]
}

/**
 * MCP resource URIs this console recognizes for OAuth consent narrowing.
 * Derived from the effective Appwrite MCP endpoint (one env var / debug override).
 */
export function mcpResourceUrls(): string[] {
  return mcpResourceUrlsForEndpoint(getEffectiveMcpEndpointUrl())
}

/** Whether any of the grant's requested resources is a known MCP resource URI. */
export function isMcpResource(
  resources: string[],
  urls: string[] = mcpResourceUrls(),
): boolean {
  const known = new Set(urls.map(normalizeResourceUrl))
  return resources.some(
    (resource) =>
      typeof resource === 'string' && known.has(normalizeResourceUrl(resource)),
  )
}

/** MCP grants (and only MCP grants) get the consent-time narrowing editor. */
export function isMcpGrant(
  grant: Models.Oauth2Grant,
  urls?: string[],
): boolean {
  return isMcpResource(grant.resources ?? [], urls ?? mcpResourceUrls())
}

/* -------------------------------------------------------------------------- */
/*  Grant composition - turn the editor's selection into the approve payload   */
/* -------------------------------------------------------------------------- */

/** Per-resource editor selection within one tier. Rows absent from the record
 *  default to selected with full requested access. */
export interface ResourceSelection {
  selected: boolean
  /** `full` grants every requested action; `read` keeps only `.read`. */
  level: 'full' | 'read'
}

export type TierSelection = Record<string, ResourceSelection>

export interface ComposeInput {
  model: ConsentScopeModel
  project: TierSelection
  organization: TierSelection
  /** Master read-only toggle - treats every selected resource as `read`. */
  readOnly: boolean
  /** True when the user narrowed the org/project resource pickers. */
  resourcesNarrowed: boolean
}

export interface ComposedGrant {
  /**
   * The `scope` value for the approve endpoint, or `undefined` to omit it.
   * Omission means "grant the full literal requested list" - required so the
   * consent-skip diff stays empty on the next re-authorization.
   */
  scope: string | undefined
  /** The user's selection is the full request - nothing was narrowed. */
  untouched: boolean
  /** No non-identity scope remains; Authorize must be disabled. */
  blocked: boolean
  /** A tier was collapsed to its umbrella to fit the scope length cap. */
  lengthCollapsed: boolean
}

/** The bare scope tokens (`tables.read`, …) one tier's selection emits. Every
 *  emitted token is one of the tier's requested tokens - never synthesized -
 *  so the result is a literal subset of the request by construction. */
function tierEmission(
  tier: TierScopes,
  selection: TierSelection,
  readOnly: boolean,
): { tokens: string[]; full: boolean } {
  const tokens: string[] = []
  for (const token of tier.scopes) {
    const resource = scopeResource(token)
    const action = scopeAction(token)
    const row = selection[resource] ?? { selected: true, level: 'full' }
    if (!row.selected) continue
    const level = readOnly ? 'read' : row.level
    if (level === 'read' && action !== 'read') continue
    tokens.push(token)
  }
  return { tokens, full: tokens.length === tier.scopes.length }
}

/**
 * Compose the scope narrowing to send to the approve endpoint.
 *
 * - Untouched selection → omit `scope` entirely: the server keeps the full
 *   literal requested list, so re-authorizations skip consent (the diff against
 *   the previously approved scopes stays empty).
 * - Any narrowing → identity scopes plus the selected granular tokens; a tier
 *   whose requested tokens all survive collapses to its requested umbrella
 *   (`project:all` / `organization:all`). The console-wide `all` is never part
 *   of a narrowed grant - it bypasses RFC 9396 resource restrictions.
 * - `.write` never implies `.read`: a "Read + Write" row emits both tokens.
 */
export function composeGrantedScopes(input: ComposeInput): ComposedGrant {
  const { model, readOnly, resourcesNarrowed } = input

  const project = tierEmission(model.project, input.project, readOnly)
  const organization = tierEmission(
    model.organization,
    input.organization,
    readOnly,
  )

  const capabilitiesFull = project.full && organization.full && !readOnly
  if (capabilitiesFull && !resourcesNarrowed) {
    return {
      scope: undefined,
      untouched: true,
      blocked: false,
      lengthCollapsed: false,
    }
  }

  // A fully-kept tier collapses to its umbrella (when the umbrella was
  // requested) - keeps the granted string short and re-consent diffs sane.
  // Covers the degenerate umbrella-only request (`project:all` with no
  // granular tokens) too: a full selection keeps the umbrella.
  const tierScopes = (
    tier: TierScopes,
    emission: { tokens: string[]; full: boolean },
    prefix: string,
    umbrella: string,
  ): string[] => {
    const requested = tier.all || tier.scopes.length > 0
    // Never collapse under read-only: the umbrella grants write, so a tier
    // whose requested tokens are all `.read` (emission.full despite the
    // toggle) must still emit the explicit token list.
    if (requested && emission.full && tier.all && !readOnly) return [umbrella]
    return emission.tokens.map((token) => prefix + token)
  }

  let projectScopes = tierScopes(
    model.project,
    project,
    PROJECT_SCOPE_PREFIX,
    PROJECT_ALL_SCOPE,
  )
  let organizationScopes = tierScopes(
    model.organization,
    organization,
    ORGANIZATION_SCOPE_PREFIX,
    ORGANIZATION_ALL_SCOPE,
  )

  const identity = model.identity.map((scope) => scope.id)
  const join = () =>
    [...identity, ...projectScopes, ...organizationScopes].join(' ')

  // Client-side mirror of the server's scope length cap. Unreachable in
  // practice (the full catalog is ~2.6 KB), but if a selection ever exceeds
  // it, collapse the larger tier to its requested umbrella and surface that.
  // Umbrella collapse grants write, so it is off the table under read-only -
  // the toggle already halves the token list, keeping it well under the cap.
  let lengthCollapsed = false
  if (!readOnly && join().length > MAX_SCOPE_PARAM_LENGTH) {
    const projectLength = projectScopes.join(' ').length
    const organizationLength = organizationScopes.join(' ').length
    if (projectLength >= organizationLength && model.project.all) {
      projectScopes = [PROJECT_ALL_SCOPE]
      lengthCollapsed = true
    } else if (model.organization.all) {
      organizationScopes = [ORGANIZATION_ALL_SCOPE]
      lengthCollapsed = true
    }
    if (join().length > MAX_SCOPE_PARAM_LENGTH && model.project.all) {
      projectScopes = [PROJECT_ALL_SCOPE]
      lengthCollapsed = true
    }
  }

  // Last resort - reachable when no umbrella was requested (or under
  // read-only, where umbrella collapse is off the table): drop trailing
  // tokens from the longer tier until the value fits. The result stays a
  // literal subset of the request, and the consent screen surfaces the
  // truncation via `lengthCollapsed`.
  while (
    join().length > MAX_SCOPE_PARAM_LENGTH &&
    projectScopes.length + organizationScopes.length > 0
  ) {
    const projectLonger =
      projectScopes.join(' ').length >= organizationScopes.join(' ').length
    const target = projectLonger
      ? projectScopes.length > 0
        ? projectScopes
        : organizationScopes
      : organizationScopes.length > 0
        ? organizationScopes
        : projectScopes
    target.pop()
    lengthCollapsed = true
  }

  // Recomputed after cap handling: truncation that empties both tiers must
  // block Authorize like any other empty selection.
  const blocked = projectScopes.length === 0 && organizationScopes.length === 0

  return { scope: join(), untouched: false, blocked, lengthCollapsed }
}

/** Never granted by a narrowed MCP consent - documented here for tests. */
export const NARROWED_GRANT_NEVER_INCLUDES = [ALL_SCOPE]
