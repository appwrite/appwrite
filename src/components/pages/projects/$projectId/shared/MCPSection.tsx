import { useMemo, useState } from 'react'
import { Check, Copy, Download, ExternalLink } from 'lucide-react'
import { toast } from 'sonner'
import {
  MCP_CLAUDE_DESKTOP_CONFIG_SNIPPET,
  MCP_CODEX_INSTALL_COMMAND,
  MCP_EDITOR_CONFIG_SNIPPET,
  MCP_OPENCODE_CONFIG_SNIPPET,
  MCP_SELF_HOSTED_DOCS_URL,
  getCursorMcpInstallUrl,
  getMcpClaudeCodeInstallCommand,
  getSelfHostedClaudeCodeInstallCommand,
  getSelfHostedCodexConfig,
  getSelfHostedMcpEditorConfig,
  getSelfHostedOpencodeConfig,
  getVscodeMcpInstallUrl,
  openMcpInstallUrl,
} from '@/lib/config/mcp'
import {
  MCP_TRY_IT_PROMPT_TEMPLATES,
  getMcpTryItPrompts,
} from '@/lib/mcp-adoption'
import { getApiEndpoint } from '@/lib/appwrite/sdk'
import { useProject } from '@/lib/react-query/hooks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { Button } from '@/components/ui/button'
import type { CodeBlockLanguage } from '@/components/global/shared/CodeBlock'
import { ConnectCodeExample } from '@/components/global/shared/ConnectCodeExample'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { McpIcon } from '@/components/global/shared/McpIcon'
import { useT, type Translator } from '@/lib/i18n/translate'

export interface MCPSectionProps {
  /** Current project ID (used to prefill self-hosted MCP env) */
  projectId: string
  /** Current project name, embedded in try-it prompts so the agent targets the right project */
  projectName: string
  /** When true, render without the outer card (e.g. inside a modal tab) */
  compact?: boolean
}

type McpToolId =
  | 'claude-code'
  | 'codex'
  | 'cursor'
  | 'claude-desktop'
  | 'vscode'
  | 'opencode'

type McpToolConfig = {
  id: McpToolId
  name: string
  iconPath: string
  language: CodeBlockLanguage
  code: string
  installUrl?: string
}

function jsonSnippet(value: unknown): string {
  return JSON.stringify(value, null, 2)
}

const MCP_MORE_TOOLS_DOCS_HREF = '/docs/tooling/ai/mcp-servers'

function getCloudMcpTools(t: Translator): McpToolConfig[] {
  return [
    {
      id: 'claude-code',
      name: 'Claude Code',
      iconPath: '/icons/claude.svg',
      language: 'bash',
      code: getMcpClaudeCodeInstallCommand(
        t('select "appwrite", then "Authenticate"'),
      ),
    },
    {
      id: 'codex',
      name: 'Codex',
      iconPath: '/icons/chatgpt.svg',
      language: 'bash',
      code: MCP_CODEX_INSTALL_COMMAND,
    },
    {
      id: 'cursor',
      name: 'Cursor',
      iconPath: '/icons/cursor-ai.svg',
      language: 'json',
      code: jsonSnippet(MCP_EDITOR_CONFIG_SNIPPET),
      installUrl: getCursorMcpInstallUrl(),
    },
    {
      id: 'claude-desktop',
      name: 'Claude Desktop',
      iconPath: '/icons/claude.svg',
      language: 'json',
      code: jsonSnippet(MCP_CLAUDE_DESKTOP_CONFIG_SNIPPET),
    },
    {
      id: 'vscode',
      name: 'VS Code',
      iconPath: '/icons/vscode.svg',
      language: 'json',
      code: jsonSnippet(MCP_EDITOR_CONFIG_SNIPPET),
      installUrl: getVscodeMcpInstallUrl(),
    },
    {
      id: 'opencode',
      name: 'OpenCode',
      iconPath: '/icons/opencode.svg',
      language: 'json',
      code: jsonSnippet(MCP_OPENCODE_CONFIG_SNIPPET),
    },
  ]
}

function getSelfHostedMcpTools(
  projectId: string,
  endpoint: string,
): McpToolConfig[] {
  const editorConfig = jsonSnippet(
    getSelfHostedMcpEditorConfig(projectId, endpoint),
  )
  return [
    {
      id: 'claude-code',
      name: 'Claude Code',
      iconPath: '/icons/claude.svg',
      language: 'bash',
      code: getSelfHostedClaudeCodeInstallCommand(projectId, endpoint),
    },
    {
      id: 'codex',
      name: 'Codex',
      iconPath: '/icons/chatgpt.svg',
      language: 'toml',
      code: getSelfHostedCodexConfig(projectId, endpoint),
    },
    {
      id: 'cursor',
      name: 'Cursor',
      iconPath: '/icons/cursor-ai.svg',
      language: 'json',
      code: editorConfig,
    },
    {
      id: 'claude-desktop',
      name: 'Claude Desktop',
      iconPath: '/icons/claude.svg',
      language: 'json',
      code: editorConfig,
    },
    {
      id: 'vscode',
      name: 'VS Code',
      iconPath: '/icons/vscode.svg',
      language: 'json',
      code: editorConfig,
    },
    {
      id: 'opencode',
      name: 'OpenCode',
      iconPath: '/icons/opencode.svg',
      language: 'json',
      code: jsonSnippet(getSelfHostedOpencodeConfig(projectId, endpoint)),
    },
  ]
}

/**
 * MCP server section: Cloud uses the remote Appwrite MCP server with per-tool
 * install instructions; self-hosted uses local uvx + API key. Cursor and VS Code
 * include a one-click Install action on Cloud. Reused in project settings
 * Overview and Connect project modal.
 */
export function MCPSection({
  projectId,
  projectName,
  compact = false,
}: MCPSectionProps) {
  const t = useT()
  const { isSelfHosted } = useConsoleProfile()
  const { project } = useProject(projectId)
  const [selectedToolId, setSelectedToolId] = useState<McpToolId>('claude-code')
  const [copiedPrompt, setCopiedPrompt] = useState<string | null>(null)

  const endpoint = useMemo(
    () => getApiEndpoint(project?.region),
    [project?.region],
  )

  const tryItPrompts = useMemo(
    () => getMcpTryItPrompts(projectName),
    [projectName],
  )

  const tools = useMemo(
    () =>
      isSelfHosted
        ? getSelfHostedMcpTools(projectId, endpoint)
        : getCloudMcpTools(t),
    [isSelfHosted, projectId, endpoint, t],
  )

  const selectedTool = useMemo(
    () => tools.find((tool) => tool.id === selectedToolId) ?? tools[0]!,
    [tools, selectedToolId],
  )

  const toolTabs = useMemo(
    () =>
      tools.map((tool) => ({
        id: tool.id,
        label: tool.name,
        icon: (
          <img
            src={tool.iconPath}
            alt=""
            className={`h-3.5 w-3.5 ${PUBLIC_ICON_MUTED_CLASSES}`}
          />
        ),
      })),
    [tools],
  )

  const handleCopyPrompt = (prompt: string) => {
    navigator.clipboard.writeText(prompt)
    setCopiedPrompt(prompt)
    toast.success(t('Copied to clipboard'))
    setTimeout(() => setCopiedPrompt(null), 2000)
  }

  const docsLinkClassName = 'text-foreground underline hover:no-underline'

  const description = isSelfHosted ? (
    <p className={`text-[13px] text-muted-foreground${compact ? ' mb-4' : ''}`}>
      {t(
        'Run Appwrite MCP locally with uvx and a project API key. Replace YOUR_API_KEY, then see the',
      )}{' '}
      <a
        href={MCP_SELF_HOSTED_DOCS_URL}
        target="_blank"
        rel="noreferrer"
        className={docsLinkClassName}
      >
        {t('docs')}
      </a>
      .
    </p>
  ) : (
    <p className={`text-[13px] text-muted-foreground${compact ? ' mb-4' : ''}`}>
      {t(
        "Appwrite offers an MCP server that allows LLMs to interact with Appwrite's API and documentation. Install with a single click or view the", // pragma: allowlist secret
      )}{' '}
      <DocsRouteLink
        rel="noreferrer"
        className={docsLinkClassName}
        href="/docs/tooling/ai/mcp-servers"
      >
        {t('docs')}
      </DocsRouteLink>{' '}
      {t('for instructions.')}
    </p>
  )

  const installContent = (
    <div className="space-y-2">
      <h4 className="text-[13px] font-semibold text-foreground">
        {t('1. Install')}
      </h4>
      <ConnectCodeExample
        code={selectedTool.code}
        language={selectedTool.language}
        tabs={toolTabs}
        activeTabId={selectedTool.id}
        onTabChange={(id) => setSelectedToolId(id as McpToolId)}
        selectorAriaLabel={t('Select tool')}
      />

      <div className="flex flex-wrap items-center gap-3">
        {selectedTool.installUrl ? (
          <Button
            variant="secondary"
            size="sm"
            className="h-9 text-[13px] gap-1.5"
            onClick={() => openMcpInstallUrl(selectedTool.installUrl!)}
          >
            <Download className="h-4 w-4" />
            {t('Install')}
          </Button>
        ) : null}

        <DocsRouteLink
          href={MCP_MORE_TOOLS_DOCS_HREF}
          className="inline-flex items-center gap-1.5 text-[12px] text-muted-foreground hover:text-foreground"
        >
          {t('More tools in the docs')}
          <ExternalLink className="h-3 w-3" />
        </DocsRouteLink>
      </div>
    </div>
  )

  const tryItContent = (
    <div className="space-y-4">
      <h4 className="text-[13px] font-semibold text-foreground">
        {t('2. Try it')}
      </h4>
      <p className="text-[13px] text-muted-foreground leading-relaxed">
        {t(
          'Open your coding agent and ask one of these prompts to confirm Appwrite MCP is working.',
        )}
      </p>
      <ul className="space-y-2">
        {MCP_TRY_IT_PROMPT_TEMPLATES.map((template, index) => {
          const prompt = tryItPrompts[index]!
          return (
            <li
              key={template}
              className="flex items-center gap-2 rounded-lg border border-border bg-muted/20 px-3 py-2"
            >
              <span className="min-w-0 flex-1 text-[13px] font-medium text-foreground">
                {t(template).replaceAll('{projectName}', projectName)}
              </span>
              <Button
                variant="ghost"
                size="sm"
                className="h-7 gap-1 text-[12px] text-muted-foreground shrink-0"
                onClick={() => handleCopyPrompt(prompt)}
              >
                {copiedPrompt === prompt ? (
                  <Check className="h-3.5 w-3.5" />
                ) : (
                  <Copy className="h-3.5 w-3.5" />
                )}
                {t('Copy')}
              </Button>
            </li>
          )
        })}
      </ul>
    </div>
  )

  const mainContent = (
    <div className="space-y-6">
      {installContent}
      <div className="border-t border-border" />
      {tryItContent}
    </div>
  )

  if (compact) {
    return (
      <div className="pt-4">
        {description}
        {mainContent}
      </div>
    )
  }

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="flex items-center gap-2 text-[15px] font-semibold text-foreground">
          <McpIcon className="h-4 w-4" />
          {t('MCP server')}
        </h3>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4 @container">
        <div className="flex gap-6 @[600px]:flex-row flex-col">
          <div className="@[600px]:w-64 shrink-0">{description}</div>
          <div className="flex-1 min-w-0">{mainContent}</div>
        </div>
      </div>
    </div>
  )
}
