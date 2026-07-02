import { Code, FileText } from 'lucide-react'
import { useMemo } from 'react'
import { getMCPIDEs } from '@/lib/config/ide'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { Button } from '@/components/ui/button'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { useT } from '@/lib/i18n/translate'

export interface MCPSectionProps {
  /** When true, render without the outer card (e.g. inside a modal tab) */
  compact?: boolean
}

/**
 * MCP servers section: two MCP types (API, Docs) + IDE integration buttons.
 * Reused in project settings Overview and Connect project modal (MCP tab).
 */
export function MCPSection({ compact = false }: MCPSectionProps) {
  const t = useT()
  const mcpIntegrations = useMemo(() => getMCPIDEs(), [])

  const description = (
    <p className={`text-[13px] text-muted-foreground${compact ? ' mb-4' : ''}`}>
      {t(
        "Appwrite offers two MCP servers that allow LLMs to interact with Appwrite's API and documentation. Deploy with a single click or view the", // pragma: allowlist secret
      )}{' '}
      <DocsRouteLink rel="noreferrer"
        className="text-foreground underline hover:no-underline" href="/docs/tooling/mcp">
        {t('docs')}
      </DocsRouteLink>{' '}
      {t('for instructions.')}
    </p>
  )

  const mainContent = (
    <>
      {/* MCP Server Types */}
      <div className="grid gap-3 sm:grid-cols-2 mb-4">
        <DocsRouteLink rel="noreferrer"
          className="rounded-lg border border-border bg-muted/30 p-3 transition-colors hover:bg-muted/50 hover:border-border cursor-pointer" href="/docs/tooling/mcp/api">
          <div className="flex items-start gap-2">
            <Code className="h-4 w-4 text-muted-foreground mt-0.5 shrink-0" />
            <div className="flex-1 min-w-0">
              <p className="text-[13px] font-medium text-foreground mb-1">
                {t('MCP for API')}
              </p>
              <p className="text-[12px] text-muted-foreground mb-2">
                {t(
                  'Interact with your Appwrite project directly. Create users, manage databases, and perform operations using natural language.', // pragma: allowlist secret
                )}
              </p>
              <span className="text-[12px] text-foreground">
                {t('Learn more')} →
              </span>
            </div>
          </div>
        </DocsRouteLink>

        <DocsRouteLink rel="noreferrer"
          className="rounded-lg border border-border bg-muted/30 p-3 transition-colors hover:bg-muted/50 hover:border-border cursor-pointer" href="/docs/tooling/mcp/docs">
          <div className="flex items-start gap-2">
            <FileText className="h-4 w-4 text-muted-foreground mt-0.5 shrink-0" />
            <div className="flex-1 min-w-0">
              <p className="text-[13px] font-medium text-foreground mb-1">
                {t('MCP for Docs')}
              </p>
              <p className="text-[12px] text-muted-foreground mb-2">
                {t(
                  'Access comprehensive Appwrite documentation. Get code examples, troubleshooting help, and implementation guidance.', // pragma: allowlist secret
                )}
              </p>
              <span className="text-[12px] text-foreground">
                {t('Learn more')} →
              </span>
            </div>
          </div>
        </DocsRouteLink>
      </div>

      {/* Integration Buttons */}
      <div className="mt-4">
        <div className="my-6 flex w-full items-center gap-3 text-[12px] text-muted-foreground">
          <div className="h-px flex-1 bg-border" />
          <span className="font-medium text-foreground/80">IDEs</span>
          <div className="h-px flex-1 bg-border" />
        </div>
        <div className="flex flex-wrap items-center justify-center gap-2">
          {mcpIntegrations.map((ide) => {
            if (!ide.mcpDocsUrl) return null
            return (
              <Button
                key={ide.id}
                variant="secondary"
                size="sm"
                className="h-9 text-[13px]"
                asChild
              >
                <a href={ide.mcpDocsUrl} target="_blank" rel="noreferrer">
                  <img
                    src={ide.iconPath}
                    alt=""
                    className={`me-1.5 h-4 w-4 ${PUBLIC_ICON_MUTED_CLASSES}`}
                  />
                  {ide.name}
                </a>
              </Button>
            )
          })}
        </div>
      </div>
    </>
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
          {t('MCP servers')}
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
