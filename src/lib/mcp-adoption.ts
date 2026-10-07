/**
 * Client-side helpers for Appwrite MCP try-it prompts in Connect flows.
 */

import type { Models } from '@appwrite.io/console'
import { APPWRITE_AGENT_OAUTH_CLIENT_ID } from '@/lib/assistant/mcp-appwrite'
import { AGENT_SETUP_PATH, AGENT_SETUP_URL } from '@/lib/seo/agent-setup'

/**
 * True when the account has authorized an MCP client other than the console
 * Agent (Cursor, Claude Code, VS Code, CIMD clients, unknown DCR apps, etc.).
 *
 * Decided from the consents alone so always-mounted UI never resolves client
 * metadata (apps.get per consent, a cross-origin document fetch per CIMD
 * client). The console Agent connects through the seeded public client, so
 * its consent carries that fixed app ID; URL-form clients and every other
 * registered app are third-party. A console Agent that fell back to Dynamic
 * Client Registration (instances without the seeded client) is
 * indistinguishable here and counts as connected.
 */
export function hasAccountMcpAgentConnected(
  consents: Models.Oauth2Consent[] | null | undefined,
): boolean {
  if (!consents?.length) return false
  return consents.some(
    (consent) =>
      !!consent.cimdUrl || consent.appId !== APPWRITE_AGENT_OAUTH_CLIENT_ID,
  )
}

/** Templates use `{projectName}` so try-it prompts target the open project. */
export const MCP_TRY_IT_PROMPT_TEMPLATES = [
  'Use Appwrite MCP to list the databases in project {projectName}',
  'Use Appwrite MCP to list the storage buckets in project {projectName}',
  'Use Appwrite MCP to list the users in project {projectName}',
] as const

export function getMcpTryItPrompts(projectName: string): string[] {
  return MCP_TRY_IT_PROMPT_TEMPLATES.map((template) =>
    template.replaceAll('{projectName}', projectName),
  )
}

/** `/setup.md` on the host serving this console, so each environment hands out its own guide. */
export function getAgentSetupUrl(origin?: string): string {
  const resolvedOrigin =
    origin ?? (typeof window !== 'undefined' ? window.location.origin : undefined)
  return resolvedOrigin ? `${resolvedOrigin}${AGENT_SETUP_PATH}` : AGENT_SETUP_URL
}

/** Short handoff so a coding agent fetches public setup instructions (and we can track that fetch). */
export function buildConnectMcpPrompt(options: {
  projectId: string
  projectName: string
  endpoint?: string
  origin?: string
}): string {
  const { projectId, projectName, endpoint, origin } = options
  const lines: string[] = [
    `Install Appwrite MCP, the Appwrite skills, the latest CLI, and the matching official SDK by following ${getAgentSetupUrl(origin)}`,
    '',
    `- Project ID: \`${projectId}\``,
    `- Name: ${projectName}`,
    ...(endpoint ? [`- Endpoint: \`${endpoint}\``] : []),
    '',
    'If your web tool cannot open that page, download it with curl. If you still cannot read it, ask me to paste it instead of guessing the steps.',
  ]

  return `${lines.join('\n')}\n`
}
