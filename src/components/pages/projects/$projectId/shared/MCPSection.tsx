import { useMemo, useState } from 'react'
import { Check, Copy, Download } from 'lucide-react'
import { toast } from 'sonner'
import {
  MCP_CLAUDE_CODE_INSTALL_COMMAND,
  MCP_CODEX_INSTALL_COMMAND,
  MCP_EDITOR_CONFIG_SNIPPET,
  getCursorMcpInstallUrl,
  getVscodeMcpInstallUrl,
  openMcpInstallUrl,
} from '@/lib/config/mcp'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { Button } from '@/components/ui/button'
import {
  CodeBlock,
  type CodeBlockLanguage,
} from '@/components/global/shared/CodeBlock'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

export interface MCPSectionProps {
  /** When true, render without the outer card (e.g. inside a modal tab) */
  compact?: boolean
}

type McpToolId = 'claude-code' | 'codex' | 'cursor' | 'vscode'

type McpToolConfig = {
  id: McpToolId
  name: string
  iconPath: string
  language: CodeBlockLanguage
  code: string
  installUrl?: string
}

const MCP_TOOLS: McpToolConfig[] = [
  {
    id: 'claude-code',
    name: 'Claude Code',
    iconPath: '/icons/claude.svg',
    language: 'bash',
    code: MCP_CLAUDE_CODE_INSTALL_COMMAND,
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
    code: JSON.stringify(MCP_EDITOR_CONFIG_SNIPPET, null, 2),
    installUrl: getCursorMcpInstallUrl(),
  },
  {
    id: 'vscode',
    name: 'VS Code',
    iconPath: '/icons/vscode.svg',
    language: 'json',
    code: JSON.stringify(MCP_EDITOR_CONFIG_SNIPPET, null, 2),
    installUrl: getVscodeMcpInstallUrl(),
  },
]

/**
 * MCP server section: single remote Appwrite MCP server with per-tool install
 * instructions. Cursor and VS Code include a one-click Install action.
 * Reused in project settings Overview and Connect project modal (MCP tab).
 */
export function MCPSection({ compact = false }: MCPSectionProps) {
  const t = useT()
  const [selectedToolId, setSelectedToolId] = useState<McpToolId>('claude-code')
  const [copied, setCopied] = useState(false)

  const selectedTool = useMemo(
    () =>
      MCP_TOOLS.find((tool) => tool.id === selectedToolId) ?? MCP_TOOLS[0]!,
    [selectedToolId],
  )

  const handleCopyCode = () => {
    navigator.clipboard.writeText(selectedTool.code)
    setCopied(true)
    toast.success(t('Copied to clipboard'))
    setTimeout(() => setCopied(false), 2000)
  }

  const description = (
    <p className={`text-[13px] text-muted-foreground${compact ? ' mb-4' : ''}`}>
      {t(
        "Appwrite offers an MCP server that allows LLMs to interact with Appwrite's API and documentation. Install with a single click or view the", // pragma: allowlist secret
      )}{' '}
      <DocsRouteLink
        rel="noreferrer"
        className="text-foreground underline hover:no-underline"
        href="/docs/tooling/mcp"
      >
        {t('docs')}
      </DocsRouteLink>{' '}
      {t('for instructions.')}
    </p>
  )

  const mainContent = (
    <div className="space-y-2">
      <div className="shrink-0 flex flex-wrap items-center justify-between gap-2">
        <div className="flex flex-wrap gap-1.5">
          {MCP_TOOLS.map((tool) => {
            const isSelected = tool.id === selectedTool.id
            return (
              <button
                key={tool.id}
                type="button"
                onClick={() => {
                  setSelectedToolId(tool.id)
                  setCopied(false)
                }}
                className={cn(
                  'cursor-pointer inline-flex items-center gap-1.5 rounded-md px-2.5 py-1 text-[12px] font-medium transition-colors',
                  isSelected
                    ? 'bg-muted text-foreground'
                    : 'text-muted-foreground hover:text-foreground hover:bg-muted/70',
                )}
              >
                <img
                  src={tool.iconPath}
                  alt=""
                  className={`h-3.5 w-3.5 ${PUBLIC_ICON_MUTED_CLASSES}`}
                />
                {tool.name}
              </button>
            )
          })}
        </div>
        <Button
          variant="ghost"
          size="sm"
          className="h-7 gap-1 text-[12px] text-muted-foreground shrink-0"
          onClick={handleCopyCode}
        >
          {copied ? (
            <Check className="h-3.5 w-3.5" />
          ) : (
            <Copy className="h-3.5 w-3.5" />
          )}
          {t('Copy')}
        </Button>
      </div>

      <CodeBlock
        code={selectedTool.code}
        language={selectedTool.language}
        showCopy={false}
      />

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
        <h3 className="text-[15px] font-semibold text-foreground">
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
