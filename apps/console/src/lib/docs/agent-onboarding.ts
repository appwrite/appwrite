import {
  MCP_CODEX_INSTALL_COMMAND,
  MCP_OPENCODE_CONFIG_SNIPPET,
  MCP_SERVER_NAME,
  MCP_SERVER_URL,
} from '@/lib/config/mcp'
import {
  CLAUDE_OFFICIAL_MARKETPLACE_REPO,
  CLAUDE_PLUGIN_MARKETPLACE_REPO,
  CLAUDE_PLUGIN_OFFICIAL,
  CODEX_PLUGIN_CONNECT_URL,
  CODEX_PLUGIN_ID,
  CURSOR_MCP_INSTALL_URL,
  MCP_JSON,
  VSCODE_ADD_MCP_JSON,
} from '@/lib/seo/agent-setup'

export type DocsOnboardingAgentId =
  | 'claude-code'
  | 'cursor'
  | 'codex'
  | 'vscode'
  | 'other'

/** Where an onboarding control sits, sent with its analytics events. */
export type DocsOnboardingPlacement =
  | 'docs-home'
  | 'quick-start'
  | 'console-preview'

export type DocsManualSetupStep = {
  title: string
  description?: string
  code?: string
  language?: 'bash' | 'json'
  link?: { label: string; href: string }
}

export type DocsOnboardingAgent = {
  id: DocsOnboardingAgentId
  name: string
  /** Icon under /public/icons. `other` falls back to a generic glyph. */
  iconSrc?: string
  /** `IDE_CONFIGS` id when the app can open with the prompt prefilled. */
  deeplinkIdeId?: string
  /** Where to paste the prompt, shown under the prompt box. */
  pasteHint: string
  /** Full manual setup on the quick start page. */
  manualSteps: DocsManualSetupStep[]
}

const OPENCODE_JSON = JSON.stringify(MCP_OPENCODE_CONFIG_SNIPPET, null, 2)

export const DOCS_ONBOARDING_AGENTS: DocsOnboardingAgent[] = [
  {
    id: 'claude-code',
    name: 'Claude Code',
    iconSrc: '/icons/claude.svg',
    pasteHint: 'Paste it into a Claude Code session in your project folder.',
    manualSteps: [
      {
        title: 'Install the Appwrite plugin',
        description:
          'The plugin bundles the hosted MCP server, the Appwrite skills, and deploy commands. Adding the official marketplace again is harmless.',
        code: `claude plugin marketplace add ${CLAUDE_OFFICIAL_MARKETPLACE_REPO}\nclaude plugin install ${CLAUDE_PLUGIN_OFFICIAL}`,
        language: 'bash',
      },
      {
        title: 'Plugin not found?',
        description: 'Add the Appwrite marketplace and install from it.',
        code: `claude plugin marketplace add ${CLAUDE_PLUGIN_MARKETPLACE_REPO}\nclaude plugin install appwrite@appwrite`,
        language: 'bash',
      },
      {
        title: 'Sign in',
        description:
          'Run /reload-plugins, then /mcp. Select appwrite and choose Authenticate. Your browser opens so you can approve access.',
      },
    ],
  },
  {
    id: 'cursor',
    name: 'Cursor',
    iconSrc: '/icons/cursor-ai.svg',
    deeplinkIdeId: 'cursor',
    pasteHint: "Paste it into Cursor's agent chat with your project open.",
    manualSteps: [
      {
        title: 'Add the MCP server',
        description:
          'Use the one-click install, or merge this into ~/.cursor/mcp.json and keep your other servers.',
        code: MCP_JSON,
        language: 'json',
        link: { label: 'Add to Cursor', href: CURSOR_MCP_INSTALL_URL },
      },
      {
        title: 'Sign in',
        description:
          'Open Cursor Settings > MCP and click Needs login next to appwrite. Your browser opens so you can approve access.',
      },
      {
        title: 'Install the skills (optional)',
        description: 'Gives the agent current Appwrite SDK and CLI patterns.',
        code: 'npx skills add appwrite/skills',
        language: 'bash',
      },
    ],
  },
  {
    id: 'codex',
    name: 'Codex',
    iconSrc: '/icons/codex.svg',
    deeplinkIdeId: 'codex',
    pasteHint: 'Paste it into a Codex session in your project folder.',
    manualSteps: [
      {
        title: 'Install the Appwrite plugin',
        description:
          'The plugin bundles the Appwrite connection and the Appwrite skills.',
        code: `codex plugin add ${CODEX_PLUGIN_ID}`,
        language: 'bash',
      },
      {
        title: 'Connect Appwrite',
        description:
          'Open the plugin page and connect Appwrite. Your browser opens so you can approve access. Then start a new Codex session.',
        link: { label: 'Open the plugin page', href: CODEX_PLUGIN_CONNECT_URL },
      },
      {
        title: 'Plugin not available?',
        description:
          'Add the server and the skills instead. The login opens your browser so you can approve access, and the server loads in your next Codex session.',
        code: `${MCP_CODEX_INSTALL_COMMAND}\ncodex mcp login ${MCP_SERVER_NAME}\nnpx skills add appwrite/skills`,
        language: 'bash',
      },
    ],
  },
  {
    id: 'vscode',
    name: 'VS Code',
    iconSrc: '/icons/vscode.svg',
    deeplinkIdeId: 'vscode',
    pasteHint:
      'Paste it into Copilot Chat in agent mode with your project open.',
    manualSteps: [
      {
        title: 'Add the MCP server',
        description:
          'Run this in a terminal. It merges the server into your user MCP configuration.',
        code: `code --add-mcp '${VSCODE_ADD_MCP_JSON}'`,
        language: 'bash',
      },
      {
        title: 'Sign in',
        description:
          'VS Code asks you to trust and authenticate the server when it first starts. Your browser opens so you can approve access.',
      },
      {
        title: 'Install the skills (optional)',
        description: 'Gives the agent current Appwrite SDK and CLI patterns.',
        code: 'npx skills add appwrite/skills',
        language: 'bash',
      },
    ],
  },
  {
    id: 'other',
    name: 'Other',
    pasteHint:
      'Paste it into any coding agent that can run commands in your project.',
    manualSteps: [
      {
        title: 'Add the remote MCP server',
        description: `Add a remote HTTP server named ${MCP_SERVER_NAME} with this URL. It signs in with OAuth, so you do not need an API key.`,
        code: MCP_SERVER_URL,
        language: 'bash',
      },
      {
        title: 'OpenCode',
        description: 'Add this to opencode.json.',
        code: OPENCODE_JSON,
        language: 'json',
      },
      {
        title: 'Clients that only support stdio',
        description: 'Run the hosted server through mcp-remote.',
        code: `npx mcp-remote ${MCP_SERVER_URL}`,
        language: 'bash',
      },
    ],
  },
]

export const DEFAULT_DOCS_ONBOARDING_AGENT_ID: DocsOnboardingAgentId =
  'claude-code'

export function getDocsOnboardingAgent(
  id: DocsOnboardingAgentId,
): DocsOnboardingAgent {
  return (
    DOCS_ONBOARDING_AGENTS.find((agent) => agent.id === id) ??
    DOCS_ONBOARDING_AGENTS[0]
  )
}

export function isDocsOnboardingAgentId(
  value: unknown,
): value is DocsOnboardingAgentId {
  return DOCS_ONBOARDING_AGENTS.some((agent) => agent.id === value)
}

export type DocsFirstPrompt = {
  title: string
  prompt: string
  icon: 'auth' | 'table' | 'upload' | 'deploy'
}

/** Follow-up prompts once the agent is connected. */
export const DOCS_FIRST_PROMPTS: DocsFirstPrompt[] = [
  {
    title: 'Add sign-in',
    prompt:
      'Add sign-up, sign-in, and sign-out with email and password using Appwrite Auth.',
    icon: 'auth',
  },
  {
    title: 'Store data',
    prompt:
      'Create a tasks table in Appwrite with title and done columns, then list the rows on the home page.',
    icon: 'table',
  },
  {
    title: 'Upload files',
    prompt:
      'Let signed-in users upload a profile picture to an Appwrite Storage bucket and show it in the header.',
    icon: 'upload',
  },
  {
    title: 'Deploy',
    prompt: 'Deploy this app to Appwrite Sites and send me the URL.',
    icon: 'deploy',
  },
]

export type DocsAgentCapability = {
  title: string
  description: string
  href: string
  icon: 'mcp' | 'skills' | 'cli' | 'markdown'
}

/** What the agent can use once setup finishes. */
export const DOCS_AGENT_CAPABILITIES: DocsAgentCapability[] = [
  {
    title: 'MCP server',
    description:
      'Acts on your project: creates tables, users, buckets, and more, and searches these docs.',
    href: '/docs/tooling/ai/mcp-servers',
    icon: 'mcp',
  },
  {
    title: 'Agent skills',
    description:
      'Current SDK and CLI patterns for every language, so generated code matches the latest APIs.',
    href: '/docs/tooling/ai/skills',
    icon: 'skills',
  },
  {
    title: 'CLI',
    description:
      'Links your project, pushes tables and buckets, and deploys functions and sites.',
    href: '/docs/tooling/command-line/installation',
    icon: 'cli',
  },
  {
    title: 'Docs for agents',
    description:
      'Every docs page as Markdown (add .md to the URL), plus llms.txt as the index.',
    href: '/docs/tooling/ai/docs-as-markdown',
    icon: 'markdown',
  },
]
