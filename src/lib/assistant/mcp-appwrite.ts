import { MCP_SERVER_NAME } from '@/lib/config/mcp'
import {
  getEffectiveMcpEndpointUrl,
  getEnvMcpEndpointUrl,
} from '@/lib/debug-mcp-endpoint'

/** Stable assistant MCP document id for the hosted Appwrite MCP server. */
export const APPWRITE_ASSISTANT_MCP_ID = MCP_SERVER_NAME

export const APPWRITE_ASSISTANT_MCP_NAME = 'Appwrite MCP'

export const APPWRITE_ASSISTANT_MCP_DESCRIPTION =
  'Let the agent take actions in your Appwrite projects through the hosted MCP server.'

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

export function getAppwriteAssistantMcpConnectInput() {
  const url = getAppwriteAssistantMcpUrl()
  return {
    mcpId: APPWRITE_ASSISTANT_MCP_ID,
    name: APPWRITE_ASSISTANT_MCP_NAME,
    url,
    description: APPWRITE_ASSISTANT_MCP_DESCRIPTION,
    resource: url,
    clientName: 'Appwrite Agent',
  }
}
