import { Sparkles } from 'lucide-react'
import { DocsHomeSectionHeading } from './DocsHomeSectionHeading'
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
import {
  docsContentPaddingX,
  docsGridTwoCol,
  docsPreviewSectionPaddingY,
  docsSectionPaddingY,
} from '@/lib/docs/docs-container'
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
    <div className={cn('relative py-6 @[900px]:py-0', className)}>
      <AiTileSoftLight tone={tone} />
      <div className="relative space-y-1.5">
        <h3 className="text-[14px] font-medium text-foreground">{title}</h3>
        <p className="text-[13px] leading-5 text-muted-foreground">{description}</p>
      </div>
      <div className={cn('relative mt-4 grid gap-2', docsGridTwoCol)}>
        {tools.map((tool) => (
          <DocsAiToolTile key={tool.href} tool={tool} />
        ))}
      </div>
    </div>
  )
}

type DocsAiSectionProps = {
  variant?: 'page' | 'preview'
}

export function DocsAiSection({ variant = 'page' }: DocsAiSectionProps) {
  const sectionPaddingY =
    variant === 'preview' ? docsPreviewSectionPaddingY : docsSectionPaddingY

  return (
    <section
      className={cn(
        'relative isolate overflow-hidden border-b border-border',
        sectionPaddingY,
      )}
    >
      <div
        className="pointer-events-none absolute inset-0 bg-[radial-gradient(circle,var(--border)_1px,transparent_1px)] bg-[length:18px_18px] opacity-50"
        aria-hidden
      />

      <div className={cn('relative mx-auto w-full max-w-6xl', docsContentPaddingX)}>
        <DocsHomeSectionHeading title="Build faster with AI" variant={variant} />
        <p className="mt-3 max-w-3xl text-[13px] leading-6 text-muted-foreground @[480px]:text-[14px] @[480px]:leading-7">
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
          <div className="grid @[900px]:grid-cols-3">
            <AiFeatureCard
              title="MCP"
              description="Connect agents to your Appwrite project, APIs, and docs."
              shade="mcp"
              className="border-b border-border @[900px]:border-b-0 @[900px]:border-e"
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
              shade="skills"
              className="border-b border-border @[900px]:border-b-0 @[900px]:border-e"
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

        <div className="mt-10 grid overflow-visible pb-4 @[900px]:grid-cols-2 @[900px]:divide-x @[900px]:divide-border">
          <DocsAiToolColumn
            title="IDEs & coding agents"
            description="Editors and agents where you ship code locally or in the terminal."
            tools={DOCS_HOME_IDE_AI_TOOLS}
            tone="mcp"
            className="@[900px]:pe-10"
          />
          <DocsAiToolColumn
            title="Vibe coding platforms"
            description="Build from prompts in the browser; connect docs or full MCP where supported."
            tools={DOCS_HOME_VIBE_AI_TOOLS}
            tone="integrations"
            className="border-t border-border pt-6 @[900px]:border-t-0 @[900px]:ps-10 @[900px]:pt-0"
          />
        </div>

        <div className="mt-8 flex flex-col gap-3 @[560px]:flex-row @[560px]:flex-wrap">
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
