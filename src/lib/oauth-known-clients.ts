import type { Models } from '@appwrite.io/console'

/**
 * Registry of well-known OAuth2 / MCP clients that register via Dynamic
 * Client Registration (RFC 7591) but do not provide a `logo_uri`, so we
 * fall back to a bundled static logo and a canonical display name.
 *
 * Patterns are grounded in each client's registration source code
 * (client_name and redirect_uri values they actually POST to /register).
 *
 * Matching is heuristic and display-only: DCR metadata (client_name,
 * redirect_uris, client_uri) is entirely attacker-controllable, so a match
 * must never grant trust — it only picks a logo and groups duplicates.
 * A name match alone is never enough: at least one redirect or client URI
 * must also corroborate it, so casual spoofing can't borrow a logo.
 */
export type KnownOAuthClient = {
  id: string
  /** Canonical display name. */
  name: string
  /** Path to the bundled logo in /public/icons/apps/. */
  iconPath: string
}

type KnownOAuthClientMatcher = KnownOAuthClient & {
  /** Tested against the registered client_name. */
  namePattern: RegExp
  /**
   * When present, at least one redirect URI or the client URI must also
   * match one of these patterns for the client to be recognized.
   */
  uriPatterns?: RegExp[]
}

const LOOPBACK = /^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?\//i

const KNOWN_CLIENTS: KnownOAuthClientMatcher[] = [
  {
    id: 'claude-code',
    name: 'Claude Code',
    iconPath: '/icons/apps/claude.svg',
    // Registers as "Claude Code (<MCP server name>)" with a random-port
    // loopback redirect: http://localhost:<port>/callback
    namePattern: /^claude code(\s*\(.*\))?$/i,
    uriPatterns: [LOOPBACK, /^https:\/\/(www\.)?(claude|anthropic)\.(ai|com)(\/|$)/i],
  },
  {
    id: 'claude',
    name: 'Claude',
    iconPath: '/icons/apps/claude.svg',
    // Claude.ai / Desktop / mobile custom connectors register as "claudeai"
    // with a fixed HTTPS callback (claude.com variant is documented too).
    namePattern: /^(claude|claudeai|claude\.ai)$/i,
    uriPatterns: [/^https:\/\/(www\.)?claude\.(ai|com)\/api\/mcp\/auth_callback/i],
  },
  {
    id: 'opencode',
    name: 'OpenCode',
    iconPath: '/icons/apps/opencode.svg',
    // client_uri https://opencode.ai, redirect http://127.0.0.1:19876/mcp/oauth/callback
    namePattern: /^opencode$/i,
    uriPatterns: [LOOPBACK, /^https:\/\/(www\.)?opencode\.ai(\/|$)/i],
  },
  {
    id: 'cursor',
    name: 'Cursor',
    iconPath: '/icons/apps/cursor.svg',
    // Cursor sends its own logo_uri; this entry mainly canonicalizes
    // naming/grouping and covers registrations missing the logo.
    namePattern: /^cursor$/i,
    uriPatterns: [
      /^cursor:\/\/anysphere\.cursor-mcp\//i,
      /^https:\/\/(www\.)?cursor\.com(\/|$)/i,
      LOOPBACK,
    ],
  },
  {
    id: 'vscode',
    name: 'Visual Studio Code',
    iconPath: '/icons/apps/vscode.svg',
    // "Visual Studio Code", "Visual Studio Code - Insiders", "Code - OSS";
    // redirects https://vscode.dev/redirect and http://127.0.0.1:33418/
    namePattern: /^(visual studio code(\s*-.*)?|code - oss)$/i,
    uriPatterns: [/^https:\/\/(insiders\.)?vscode\.dev\/redirect/i, LOOPBACK],
  },
  {
    id: 'windsurf',
    name: 'Windsurf',
    iconPath: '/icons/apps/windsurf.svg',
    // Extension-host path inherits the VS Code redirect set; the native
    // path uses a random loopback port or a windsurf:// scheme redirect.
    namePattern: /^(windsurf|devin)$/i,
    uriPatterns: [
      /^windsurf:\/\//i,
      /^https:\/\/(insiders\.)?vscode\.dev\/redirect/i,
      LOOPBACK,
    ],
  },
  {
    id: 'zed',
    name: 'Zed',
    iconPath: '/icons/apps/zed.svg',
    // Random-port loopback redirect http://127.0.0.1:<port>/callback
    namePattern: /^zed$/i,
    uriPatterns: [LOOPBACK, /^https:\/\/zed\.dev(\/|$)/i],
  },
  {
    id: 'codex',
    name: 'Codex',
    iconPath: '/icons/apps/codex.svg',
    // Registers as "Codex" with loopback /callback[/<hash>] redirects.
    namePattern: /^codex(\s+cli)?$/i,
    uriPatterns: [LOOPBACK],
  },
  {
    id: 'chatgpt',
    name: 'ChatGPT',
    iconPath: '/icons/apps/chatgpt.svg',
    // Fixed HTTPS callbacks: https://chatgpt.com/connector/oauth/<id> or
    // the legacy https://chatgpt.com/connector_platform_oauth_redirect
    namePattern: /^chatgpt$/i,
    uriPatterns: [/^https:\/\/(www\.)?chatgpt\.com(\/|$)/i],
  },
]

function collectAppUris(app: Models.App): string[] {
  const uris = [...(app.redirectUris ?? [])]
  if (app.clientUri) uris.push(app.clientUri)
  return uris
}

/**
 * Match a registered OAuth2 app against the known-client registry.
 * Returns null when nothing matches; callers then fall back to the app's
 * own logoUri or the generic placeholder.
 */
export function matchKnownOAuthClient(
  app: Models.App,
): KnownOAuthClient | null {
  const name = app.name?.trim() ?? ''
  if (!name) return null

  const uris = collectAppUris(app)

  for (const client of KNOWN_CLIENTS) {
    if (!client.namePattern.test(name)) continue
    // Real DCR clients always register redirect URIs, so a registration
    // with none is anomalous — never let it borrow a logo on name alone.
    if (
      client.uriPatterns &&
      !uris.some((uri) => client.uriPatterns!.some((p) => p.test(uri)))
    ) {
      continue
    }

    return { id: client.id, name: client.name, iconPath: client.iconPath }
  }

  return null
}
