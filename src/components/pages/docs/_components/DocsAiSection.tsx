import { Sparkles } from 'lucide-react'
import { MarketingSectionHeading } from '@/components/pages/marketing/MarketingSections'
import {
  AiFeatureCard,
  AiMcpMockVisual,
  AiPromptsMockVisual,
  AiSkillsMockVisual,
} from '@/components/pages/shared/AiMockPanels'
import { AiTileSoftLight } from '@/components/pages/home/HomeSoftLights'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  DOCS_HOME_IDE_AI_TOOLS,
  DOCS_HOME_VIBE_AI_TOOLS,
  type DocsHomeToolCard,
} from '@/lib/docs/home-content'
import { DOCS_PROSE_LINK_CLASS } from '@/lib/docs/prose-link'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { cn } from '@/lib/utils'
import { DocsRouteLink } from '../DocsRouteLink'

const INLINE_LINK_CLASS = DOCS_PROSE_LINK_CLASS

function DocsAiToolTile({ tool }: { tool: DocsHomeToolCard }) {
  return (
    <DocsRouteLink
      href={tool.href}
      className="group flex items-center gap-3 rounded-lg border border-border bg-background/60 px-3 py-2.5 transition-colors hover:bg-accent/15"
    >
      <span className="flex size-8 shrink-0 items-center justify-center rounded-md border border-border bg-muted/40">
        {tool.iconSrc ? (
          <img
            src={tool.iconSrc}
            alt=""
            className={cn('size-4 object-contain', PUBLIC_ICON_MUTED_CLASSES)}
          />
        ) : (
          <Sparkles className="size-3.5 text-muted-foreground" aria-hidden />
        )}
      </span>
      <span className="min-w-0 flex-1 text-[13px] font-medium text-foreground">
        {tool.title}
      </span>
      {tool.badges?.length ? (
        <span className="flex shrink-0 flex-wrap items-center justify-end gap-1">
          {tool.badges.map((badge) => (
            <Badge key={badge.label} variant={badge.variant} className="text-[10px]">
              {badge.label}
            </Badge>
          ))}
        </span>
      ) : null}
    </DocsRouteLink>
  )
}

function DocsAiToolColumn({
  title,
  description,
  tools,
  tone,
  className,
}: {
  title: string
  description: string
  tools: DocsHomeToolCard[]
  tone: 'mcp' | 'integrations'
  className?: string
}) {
  return (
    <div className={cn('relative overflow-hidden py-6 lg:py-0', className)}>
      <AiTileSoftLight tone={tone} />
      <div className="relative space-y-1.5">
        <h3 className="text-[15px] font-semibold text-foreground">{title}</h3>
        <p className="text-[13px] leading-5 text-muted-foreground">{description}</p>
      </div>
      <div className="relative mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2">
        {tools.map((tool) => (
          <DocsAiToolTile key={tool.href} tool={tool} />
        ))}
      </div>
    </div>
  )
}

export function DocsAiSection() {
  return (
    <section className="relative isolate overflow-x-hidden border-b border-border py-12 sm:py-16">
      <div
        className="pointer-events-none absolute inset-0 bg-[radial-gradient(circle,var(--border)_1px,transparent_1px)] bg-[length:18px_18px] opacity-50"
        aria-hidden
      />

      <div className="relative mx-auto w-full max-w-7xl px-4 sm:px-6">
        <MarketingSectionHeading
          align="left"
          size="md"
          title="Build faster with AI"
        />
        <p className="mt-4 max-w-3xl text-[14px] leading-7 text-muted-foreground">
          Wire up MCP so models can reach your Appwrite project and docs, install{' '}
          <DocsRouteLink href="/docs/tooling/ai/skills" className={INLINE_LINK_CLASS}>
            agent skills
          </DocsRouteLink>{' '}
          for SDK-accurate codegen, and use{' '}
          <DocsRouteLink
            href="/docs/tooling/ai/quickstart-prompts"
            className={INLINE_LINK_CLASS}
          >
            quickstart prompts
          </DocsRouteLink>{' '}
          to scaffold features, whether you work in an IDE or a vibe coding platform.
        </p>

        <div className="mt-8 overflow-hidden rounded-xl border border-border bg-card/50">
          <div className="grid lg:grid-cols-3">
            <AiFeatureCard
              title="MCP"
              description="Connect agents to your Appwrite project, APIs, and docs."
              titleBadge={{ label: 'Official', variant: 'info' }}
              shade="mcp"
              className="border-b border-border lg:border-b-0 lg:border-r"
              cta={
                <Button variant="outline" className="h-9 text-[13px]" asChild>
                  <DocsRouteLink href="/docs/tooling/ai/mcp-servers">
                    Configure the MCP server
                  </DocsRouteLink>
                </Button>
              }
            >
              <AiMcpMockVisual />
            </AiFeatureCard>

            <AiFeatureCard
              title="Agent skills"
              description="Teach agents your backend so they make SDK-accurate calls."
              titleBadge={{ label: 'Official', variant: 'info' }}
              shade="skills"
              className="border-b border-border lg:border-b-0 lg:border-r"
              cta={
                <Button variant="outline" className="h-9 text-[13px]" asChild>
                  <DocsRouteLink href="/docs/tooling/ai/skills">
                    Explore agent skills
                  </DocsRouteLink>
                </Button>
              }
            >
              <AiSkillsMockVisual />
            </AiFeatureCard>

            <AiFeatureCard
              title="Quickstart prompts"
              description="Scaffold auth, databases, storage, and more from a prompt."
              titleBadge={{ label: 'Official', variant: 'info' }}
              shade="plugins"
              cta={
                <Button variant="outline" className="h-9 text-[13px]" asChild>
                  <DocsRouteLink href="/docs/tooling/ai/quickstart-prompts">
                    Browse quickstart prompts
                  </DocsRouteLink>
                </Button>
              }
            >
              <AiPromptsMockVisual />
            </AiFeatureCard>
          </div>
        </div>

        <div className="mt-10 grid overflow-hidden lg:grid-cols-2 lg:divide-x lg:divide-border">
          <DocsAiToolColumn
            title="IDEs & coding agents"
            description="Editors and agents where you ship code locally or in the terminal."
            tools={DOCS_HOME_IDE_AI_TOOLS}
            tone="mcp"
            className="lg:pr-10"
          />
          <DocsAiToolColumn
            title="Vibe coding platforms"
            description="Build from prompts in the browser; connect docs or full MCP where supported."
            tools={DOCS_HOME_VIBE_AI_TOOLS}
            tone="integrations"
            className="border-t border-border pt-6 lg:border-t-0 lg:pl-10 lg:pt-0"
          />
        </div>

        <div className="mt-8 flex flex-col gap-3 sm:flex-row sm:flex-wrap">
          <Button variant="outline" size="sm" className="h-9 text-[13px]" asChild>
            <DocsRouteLink href="/docs/tooling/ai">
              Explore the AI tooling documentation
            </DocsRouteLink>
          </Button>
          <Button variant="ghost" size="sm" className="h-9 text-[13px]" asChild>
            <DocsRouteLink href="/docs/tooling/ai/mcp-servers">
              Configure the MCP server
            </DocsRouteLink>
          </Button>
        </div>
      </div>
    </section>
  )
}
