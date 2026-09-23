/**
 * Client-side helpers for Appwrite MCP try-it prompts in Connect flows.
 */

import type { AccountConnectedAppGroup } from '@/lib/react-query/hooks/account-applications'
import { MCP_API_KEY_PLACEHOLDER } from '@/lib/config/mcp'
import { AGENT_SETUP_URL } from '@/lib/seo/agent-setup'

/** First-party console Agent OAuth client. Not a coding-agent MCP install. */
export const FIRST_PARTY_APPWRITE_AGENT_CLIENT_ID = 'appwrite-agent'

/**
 * True when the account has authorized an MCP client other than the console
 * Agent (Cursor, Claude Code, VS Code, CIMD clients, unknown DCR apps, etc.).
 */
export function hasAccountMcpAgentConnected(
  groups: AccountConnectedAppGroup[] | undefined,
): boolean {
  if (!groups?.length) return false
  return groups.some(
    (group) => group.knownClient?.id !== FIRST_PARTY_APPWRITE_AGENT_CLIENT_ID,
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

/** Short handoff so a coding agent fetches public setup instructions (and we can track that fetch). */
export function buildConnectMcpPrompt(options: {
  projectId: string
  projectName: string
  isSelfHosted: boolean
  endpoint?: string
}): string {
  const { projectId, projectName, isSelfHosted, endpoint } = options
  const lines: string[] = [
    `Install Appwrite MCP, the latest CLI, and the matching official SDK by following ${AGENT_SETUP_URL}`,
    '',
    `- Project ID: \`${projectId}\``,
    `- Name: ${projectName}`,
    `- Endpoint: \`${endpoint ?? ''}\``,
  ]

  if (isSelfHosted) {
    lines.push(
      `- API key placeholder: \`${MCP_API_KEY_PLACEHOLDER}\``,
      '',
      'This instance is self-hosted. Use the self-hosted path in those instructions.',
    )
  }

  return `${lines.join('\n').trimEnd()}\n`
}
