import { useMemo, useState } from 'react'
import { Check, Copy, Download, ExternalLink } from 'lucide-react'
import { toast } from 'sonner'
import {
  MCP_SELF_HOSTED_DOCS_URL,
  getCloudMcpTools,
  getSelfHostedMcpTools,
  openMcpInstallUrl,
  type McpToolId,
} from '@/lib/config/mcp'
import {
  MCP_TRY_IT_PROMPT_TEMPLATES,
  getMcpTryItPrompts,
} from '@/lib/mcp-adoption'
import { getApiEndpoint } from '@/lib/appwrite/sdk'
import { useProject } from '@/lib/react-query/hooks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useT } from '@/lib/i18n/translate'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { Button } from '@/components/ui/button'
import { ConnectCodeExample } from '@/components/global/shared/ConnectCodeExample'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { McpIcon } from '@/components/global/shared/McpIcon'
import { analyticsAttrs } from '@/lib/analytics-actions'

export interface MCPSectionProps {
  /** Current project ID (used to prefill self-hosted MCP env) */
  projectId: string
  /** Current project name, embedded in try-it prompts so the agent targets the right project */
  projectName: string
  /** When true, render without the outer card (e.g. inside a modal tab) */
  compact?: boolean
  /** Render both steps, or only install / try-it. */
  parts?: 'all' | 'install' | 'try'
}

const MCP_MORE_TOOLS_DOCS_HREF = '/docs/tooling/ai/mcp-servers'

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
  parts = 'all',
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
      {parts === 'all' ? (
        <h4 className="text-[13px] font-semibold text-foreground">
          {t('1. Install')}
        </h4>
      ) : null}
      {selectedTool.instructions ? (
        <p className="text-[13px] text-muted-foreground">
          {selectedTool.instructions}
        </p>
      ) : null}
      <ConnectCodeExample
        code={selectedTool.code}
        language={selectedTool.language}
        tabs={toolTabs}
        activeTabId={selectedTool.id}
        onTabChange={(id) => setSelectedToolId(id as McpToolId)}
        selectorAriaLabel={t('Select tool')}
      />

      {selectedTool.fallbackCode ? (
        <details className="space-y-2 text-[13px]">
          <summary className="cursor-pointer text-muted-foreground">
            {t('Local server fallback')}
          </summary>
          <p className="text-muted-foreground">
            {t(
              'Requires Node.js. Add this configuration under Settings > Developer > Local MCP servers > Edit Config, then restart Claude Desktop.',
            )}
          </p>
          <ConnectCodeExample
            code={selectedTool.fallbackCode}
            language="json"
          />
        </details>
      ) : null}

      <div className="flex flex-wrap items-center gap-3">
        {selectedTool.installUrl ? (
          <Button
            variant="secondary"
            size="sm"
            className="h-9 text-[13px] gap-1.5"
            onClick={() => openMcpInstallUrl(selectedTool.installUrl!)}
            {...analyticsAttrs('mcp-connect')}
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
    <div className={parts === 'try' ? 'space-y-4 text-center' : 'space-y-4'}>
      <h4
        className={
          parts === 'try'
            ? 'text-[15px] font-semibold text-foreground'
            : 'text-[13px] font-semibold text-foreground'
        }
      >
        {parts === 'try' ? t('Try it') : t('2. Try it')}
      </h4>
      <p
        className={
          parts === 'try'
            ? 'mx-auto max-w-xl text-[13px] leading-relaxed text-muted-foreground'
            : 'text-[13px] text-muted-foreground leading-relaxed'
        }
      >
        {t(
          'Open your coding agent and ask one of these prompts to confirm Appwrite MCP is working.',
        )}
      </p>
      <ul className={parts === 'try' ? 'space-y-2 text-start' : 'space-y-2'}>
        {MCP_TRY_IT_PROMPT_TEMPLATES.map((template, index) => {
          const prompt = tryItPrompts[index]!
          const copied = copiedPrompt === prompt
          return (
            <li key={template}>
              <button
                type="button"
                className="flex w-full cursor-pointer items-center gap-2 rounded-lg border border-border bg-muted/20 px-3 py-2 text-start transition-colors hover:bg-muted/35"
                onClick={() => handleCopyPrompt(prompt)}
                aria-label={`${t('Copy')}: ${t(template).replaceAll('{projectName}', projectName)}`}
              >
                <span className="min-w-0 flex-1 text-[13px] font-medium text-foreground">
                  {t(template).replaceAll('{projectName}', projectName)}
                </span>
                <span
                  className="inline-flex h-7 shrink-0 items-center gap-1 px-3 text-[12px] font-medium text-muted-foreground"
                  aria-hidden
                >
                  {copied ? (
                    <Check className="h-3.5 w-3.5" />
                  ) : (
                    <Copy className="h-3.5 w-3.5" />
                  )}
                  {t('Copy')}
                </span>
              </button>
            </li>
          )
        })}
      </ul>
    </div>
  )

  const showInstall = parts !== 'try'
  const showTry = parts !== 'install'

  const mainContent = (
    <div className="space-y-6">
      {showInstall ? installContent : null}
      {showInstall && showTry ? (
        <div className="border-t border-border" />
      ) : null}
      {showTry ? tryItContent : null}
    </div>
  )

  if (compact) {
    return (
      <div className={parts === 'all' ? 'pt-4' : undefined}>
        {showInstall ? description : null}
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
