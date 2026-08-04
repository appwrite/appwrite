import { MCP_SERVER_NAME } from '@/lib/config/mcp'
import {
  getEffectiveMcpEndpointUrl,
  getEnvMcpEndpointUrl,
  normalizeMcpEndpointUrl,
} from '@/lib/debug-mcp-endpoint'

/** Stable assistant MCP document id for the hosted Appwrite MCP server. */
export const APPWRITE_ASSISTANT_MCP_ID = MCP_SERVER_NAME

export const APPWRITE_ASSISTANT_MCP_NAME = 'Appwrite MCP'

export const APPWRITE_ASSISTANT_MCP_DESCRIPTION =
  'Let the agent take actions in your Appwrite projects through the hosted MCP server.'

/**
 * Pre-registered public OAuth2 app used by the console Agent to connect to
 * Appwrite MCP. Preferred when the authorization server has seeded this app
 * (production). On fresh local/dev instances the silent connect path falls
 * back to Dynamic Client Registration when this id is missing. Override with
 * `VITE_APPWRITE_AGENT_OAUTH_CLIENT_ID` when the backend seeds a different id.
 */
export const APPWRITE_AGENT_OAUTH_CLIENT_ID =
  (
    (import.meta.env.VITE_APPWRITE_AGENT_OAUTH_CLIENT_ID as string | undefined) ??
    'appwrite-agent'
  ).trim() || 'appwrite-agent'

export const APPWRITE_AGENT_OAUTH_CLIENT_NAME = 'Appwrite Agent'

/**
 * Env / hosted default MCP URL (ignores debug override). Prefer
 * {@link getAppwriteAssistantMcpUrl} at call sites.
 *
 * Hosted default is mcp.appwrite.io. For local cloud compose, set
 * `VITE_APPWRITE_MCP_URL=http://localhost:8100/` (service `appwrite-mcp`).
 */
export const APPWRITE_ASSISTANT_MCP_URL = getEnvMcpEndpointUrl()

/** RFC 8707 resource indicator for the env default URL. */
export const APPWRITE_ASSISTANT_MCP_RESOURCE = APPWRITE_ASSISTANT_MCP_URL

/** Effective MCP URL: debug override → VITE_APPWRITE_MCP_URL → hosted default. */
export function getAppwriteAssistantMcpUrl(): string {
  return getEffectiveMcpEndpointUrl()
}

/** RFC 8707 resource indicator for the effective MCP URL. */
export function getAppwriteAssistantMcpResource(): string {
  return getAppwriteAssistantMcpUrl()
}

export function getAppwriteAgentOAuthClientInfo(redirectUri: string) {
  return {
    client_id: APPWRITE_AGENT_OAUTH_CLIENT_ID,
    client_name: APPWRITE_AGENT_OAUTH_CLIENT_NAME,
    token_endpoint_auth_method: 'none' as const,
    redirect_uris: [redirectUri],
  }
}

export function getAppwriteAssistantMcpConnectInput() {
  const url = getAppwriteAssistantMcpUrl()
  return {
    mcpId: APPWRITE_ASSISTANT_MCP_ID,
    name: APPWRITE_ASSISTANT_MCP_NAME,
    url,
    description: APPWRITE_ASSISTANT_MCP_DESCRIPTION,
    resource: url,
    clientId: APPWRITE_AGENT_OAUTH_CLIENT_ID,
    clientName: APPWRITE_AGENT_OAUTH_CLIENT_NAME,
  }
}

/**
 * Whether a stored Appwrite MCP connection matches the effective endpoint
 * (env / debug override). Tokens for a previous instance must not count as
 * connected after the MCP URL changes.
 */
export function isAppwriteMcpConnectionCurrent(
  connection: { url?: string | null; hasTokens?: boolean } | null | undefined,
  mcpUrl: string = getAppwriteAssistantMcpUrl(),
): boolean {
  if (!connection?.hasTokens) return false
  const stored = connection.url?.trim()
  if (!stored) return false
  return normalizeMcpEndpointUrl(stored) === normalizeMcpEndpointUrl(mcpUrl)
}
