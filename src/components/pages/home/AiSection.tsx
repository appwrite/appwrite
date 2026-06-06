import { Plus } from 'lucide-react'
import type { ReactNode } from 'react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  getMcpIntegrations,
  getOfficialPlugins,
  type HomePluginConfig,
  type IDEConfig,
} from '@/lib/config/ide'
import { AiTileSoftLight } from '@/components/pages/home/HomeSoftLights'
import { cn } from '@/lib/utils'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
const OFFICIAL_PLUGINS = getOfficialPlugins()
const MCP_INTEGRATIONS = getMcpIntegrations()

const SKILL_TAGS = [
  'createDocument',
  'uploadFile',
  'getUser',
  'listFiles',
  'deleteSession',
  'getAccount',
  'listTeams',
] as const

type BenchmarkRow = {
  model: string
  icon: string
  cost: string
  overall: number
  auth: number
  tablesDb: number
  functions: number
  storage: number
  sites: number
  messaging: number
}

const BENCHMARK_ROWS: BenchmarkRow[] = [
  {
    model: 'GPT 5.5',
    icon: '/icons/chatgpt.svg',
    cost: '$5.00',
    overall: 97.7,
    auth: 98.5,
    tablesDb: 96.5,
    functions: 99.5,
    storage: 94.3,
    sites: 100,
    messaging: 100,
  },
  {
    model: 'Claude Opus 4.7',
    icon: '/icons/claude.svg',
    cost: '$5.00',
    overall: 97.1,
    auth: 99,
    tablesDb: 91.3,
    functions: 100,
    storage: 94.8,
    sites: 100,
    messaging: 100,
  },
  {
    model: 'Claude Opus 4.8',
    icon: '/icons/claude.svg',
    cost: '$5.00',
    overall: 97.1,
    auth: 99.3,
    tablesDb: 96.1,
    functions: 95,
    storage: 94.3,
    sites: 100,
    messaging: 100,
  },
  {
    model: 'Grok Build 0.1',
    icon: '/icons/x.svg',
    cost: '$1.00',
    overall: 96.7,
    auth: 92,
    tablesDb: 96.3,
    functions: 100,
    storage: 93.5,
    sites: 99.7,
    messaging: 100,
  },
]

function formatScore(value: number) {
  return `${value % 1 === 0 ? value.toFixed(0) : value.toFixed(1)}%`
}

function AiFeatureCard({
  title,
  description,
  ctaLabel,
  ctaHref,
  shade,
  className,
  children,
}: {
  title: string
  description: string
  ctaLabel: string
  ctaHref: string
  shade?: 'mcp' | 'skills'
  className?: string
  children: ReactNode
}) {
  return (
    <article
      className={cn('relative flex min-h-[22rem] flex-col overflow-hidden', className)}
    >
      {shade ? <AiTileSoftLight tone={shade} /> : null}

      <div className="relative flex flex-1 flex-col p-5 sm:p-6">
        <div className="space-y-2">
          <h3 className="font-aeonik-pro text-[16px] font-normal text-foreground">
            {title}
          </h3>
          <p className="text-[13px] leading-5 text-muted-foreground">{description}</p>
        </div>

        <div className="mt-4 flex flex-1 flex-col">{children}</div>

        <div className="mt-4">
          <Button variant="outline" className="h-9 text-[13px]" asChild>
            <a href={ctaHref} target="_blank" rel="noopener noreferrer">
              {ctaLabel}
            </a>
          </Button>
        </div>
      </div>
    </article>
  )
}

function MockTypingInput({
  placeholder,
  typedText,
  typeDelayMs = 150,
  leadingIcon,
}: {
  placeholder: string
  typedText: string
  typeDelayMs?: number
  leadingIcon?: ReactNode
}) {
  const cursorDelay = typeDelayMs + typedText.length * 55

  return (
    <div className="flex items-center gap-2 rounded-md border border-border bg-background/80 px-3 py-2 text-[10px] sm:text-[11px]">
      {leadingIcon}
      <div className="relative min-w-0 flex-1">
        <span className="text-muted-foreground transition-opacity duration-200 group-hover/visual:opacity-0 motion-reduce:group-hover/visual:opacity-100">
          {placeholder}
        </span>
        <span className="absolute inset-0 flex items-center opacity-0 group-hover/visual:opacity-100 motion-reduce:opacity-100">
          <span className="inline-flex max-w-full items-center overflow-hidden whitespace-nowrap text-muted-foreground">
            <span
              className="inline-block max-w-0 overflow-hidden whitespace-nowrap group-hover/visual:animate-[ai-mock-type-reveal_1.7s_steps(24,end)_forwards] motion-reduce:max-w-none motion-reduce:group-hover/visual:animate-none"
              style={{ animationDelay: `${typeDelayMs}ms` }}
            >
              {typedText}
            </span>
            <span
              className="ml-px inline-block h-3 w-px shrink-0 bg-muted-foreground opacity-0 group-hover/visual:animate-[ai-mock-cursor-blink_1s_step-end_infinite] motion-reduce:opacity-100 motion-reduce:group-hover/visual:animate-none"
              style={{ animationDelay: `${cursorDelay}ms` }}
            />
          </span>
        </span>
      </div>
    </div>
  )
}

function McpFeaturePanel() {
  return (
    <AiFeatureCard
      title="MCP"
      description="Connect AI agents to your Appwrite backend. No custom integrations required."
      ctaLabel="Learn more"
      ctaHref="https://appwrite.io/docs/tooling/mcp"
      shade="mcp"
      className="border-b border-border lg:border-b-0 lg:border-r"
    >
      <div className="group/visual relative min-h-[14rem] flex-1 overflow-hidden rounded-lg border border-border bg-muted/25">
        <div className="absolute inset-y-0 left-0 w-[38%] border-r border-border/80 bg-background/40 p-3">
          <div className="space-y-2">
            {Array.from({ length: 5 }).map((_, index) => (
              <div
                key={index}
                className="h-2 rounded-full bg-muted-foreground/15 group-hover/visual:animate-[ai-mock-sidebar-pulse_1.4s_ease-in-out_infinite] motion-reduce:group-hover/visual:animate-none"
                style={{
                  width: `${68 - index * 8}%`,
                  animationDelay: `${index * 120}ms`,
                }}
              />
            ))}
          </div>
        </div>
        <div className="absolute inset-y-0 right-0 flex w-[62%] flex-col justify-end p-3 sm:p-4">
          <div className="mb-auto space-y-2 pt-1">
            <div
              className="ml-auto max-w-[92%] rounded-lg border border-border bg-background/90 px-2.5 py-2 text-[10px] leading-snug text-muted-foreground opacity-90 group-hover/visual:animate-[ai-mock-fade-in_0.45s_ease-out] motion-reduce:group-hover/visual:animate-none sm:text-[11px]"
              style={{ animationDelay: '80ms' }}
            >
              Create a collection for user profiles
            </div>
            <div
              className="max-w-[92%] rounded-lg border border-border bg-background/90 px-2.5 py-2 text-[10px] leading-snug text-muted-foreground group-hover/visual:animate-[ai-mock-fade-in_0.55s_ease-out] motion-reduce:group-hover/visual:animate-none sm:text-[11px]"
              style={{ animationDelay: '380ms' }}
            >
              <span className="inline-flex items-center gap-1.5 group-hover/visual:hidden motion-reduce:hidden">
                <span
                  className="size-1 rounded-full bg-muted-foreground/40 animate-[ai-mock-thinking-dots_1s_ease-in-out_infinite]"
                  style={{ animationDelay: '0ms' }}
                />
                <span
                  className="size-1 rounded-full bg-muted-foreground/40 animate-[ai-mock-thinking-dots_1s_ease-in-out_infinite]"
                  style={{ animationDelay: '150ms' }}
                />
                <span
                  className="size-1 rounded-full bg-muted-foreground/40 animate-[ai-mock-thinking-dots_1s_ease-in-out_infinite]"
                  style={{ animationDelay: '300ms' }}
                />
              </span>
              <span className="hidden group-hover/visual:inline motion-reduce:inline">
                Setting up collection with email and name attributes.
              </span>
              <span className="group-hover/visual:hidden motion-reduce:hidden">
                Thinking...
              </span>
            </div>
          </div>
          <MockTypingInput
            placeholder="Ask anything..."
            typedText="Add avatar field to profiles"
            typeDelayMs={220}
          />
        </div>
      </div>
    </AiFeatureCard>
  )
}

function SkillsFeaturePanel() {
  return (
    <AiFeatureCard
      title="Skills"
      description="Teach AI agents your backend, so they always make the right call."
      ctaLabel="Learn more"
      ctaHref="https://appwrite.io/docs/tooling/ai/skills"
      shade="skills"
    >
      <div className="group/visual relative min-h-[14rem] flex-1 overflow-hidden rounded-lg border border-border bg-muted/25 p-4 sm:p-5">
        <MockTypingInput
          placeholder="Ask anything..."
          typedText="List files in my bucket"
          typeDelayMs={120}
          leadingIcon={
            <Plus
              className="size-3.5 shrink-0 text-muted-foreground transition-transform duration-200 group-hover/visual:rotate-90 motion-reduce:group-hover/visual:rotate-0"
              aria-hidden
            />
          }
        />
        <div className="mt-4 flex flex-wrap gap-2">
          {SKILL_TAGS.map((tag, index) => (
            <span
              key={tag}
              className="rounded-md border border-border bg-background/80 px-2 py-1 font-mono text-[10px] text-muted-foreground group-hover/visual:animate-[ai-mock-tag-press_0.4s_ease-out_both] motion-reduce:group-hover/visual:animate-none sm:text-[11px]"
              style={{ animationDelay: `${280 + index * 110}ms` }}
            >
              {tag}
            </span>
          ))}
        </div>
      </div>
    </AiFeatureCard>
  )
}

type PluginTileBadge = {
  label: string
  variant: 'info' | 'inactive'
}

function PluginTile({
  plugin,
  href,
  badges,
}: {
  plugin: Pick<HomePluginConfig, 'id' | 'name' | 'iconPath'>
  href: string
  badges: PluginTileBadge[]
}) {
  return (
    <a
      href={href}
      target="_blank"
      rel="noopener noreferrer"
      className="group flex items-center gap-3 rounded-lg border border-border bg-background/60 px-3 py-2.5 transition-colors hover:bg-accent/15"
    >
      <span className="flex size-8 shrink-0 items-center justify-center rounded-md border border-border bg-muted/40">
        <img src={plugin.iconPath} alt="" className="size-4 object-contain" />
      </span>
      <span className="min-w-0 flex-1 text-[13px] font-medium text-foreground">
        {plugin.name}
      </span>
      <span className="flex shrink-0 flex-wrap items-center justify-end gap-1">
        {badges.map((badge) => (
          <Badge
            key={badge.label}
            variant={badge.variant}
            className="text-[10px]"
          >
            {badge.label}
          </Badge>
        ))}
      </span>
    </a>
  )
}

function AiPluginsSection() {
  return (
    <div className="mt-10 grid overflow-visible pb-4 lg:mt-12 lg:grid-cols-2 lg:divide-x lg:divide-border">
      <div className="relative py-6 lg:py-0 lg:pr-10">
        <AiTileSoftLight tone="plugins" />
        <div className="relative space-y-1.5">
          <h3 className="font-aeonik-pro text-[16px] font-normal text-foreground sm:text-[18px]">
            Official plugins
          </h3>
          <p className="text-[13px] leading-5 text-muted-foreground">
            One-click marketplace plugins for Cursor, Claude Code, and Codex.
          </p>
        </div>
        <div className="relative mt-4 grid grid-cols-1 gap-2 sm:grid-cols-2">
          {OFFICIAL_PLUGINS.map((plugin) => (
            <PluginTile
              key={plugin.id}
              plugin={plugin}
              href={plugin.docsUrl}
              badges={[{ label: 'Official', variant: 'info' }]}
            />
          ))}
        </div>
      </div>

      <div className="relative border-t border-border py-6 lg:border-t-0 lg:py-0 lg:pl-10">
        <AiTileSoftLight tone="integrations" />
        <div className="relative space-y-1.5">
          <h3 className="font-aeonik-pro text-[16px] font-normal text-foreground sm:text-[18px]">
            Integrations
          </h3>
          <p className="text-[13px] leading-5 text-muted-foreground">
            Connect Appwrite in other agents and IDEs.
          </p>
        </div>
        <div className="relative mt-4 grid grid-cols-2 gap-2">
          {MCP_INTEGRATIONS.map((integration: IDEConfig) => (
            <PluginTile
              key={integration.id}
              plugin={integration}
              href={integration.mcpDocsUrl!}
              badges={[{ label: 'Skills', variant: 'inactive' }]}
            />
          ))}
        </div>
        <div className="relative mt-4">
          <Button variant="outline" className="h-9 text-[13px]" asChild>
            <a
              href="https://appwrite.io/docs/tooling/mcp"
              target="_blank"
              rel="noopener noreferrer"
            >
              Learn more
            </a>
          </Button>
        </div>
      </div>
    </div>
  )
}

function BenchmarkTable() {
  return (
    <div className="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
      <Table className="min-w-[56rem]">
        <TableHeader>
          <TableRow className="hover:bg-transparent border-b border-border">
            <TableHead className="px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              Model
            </TableHead>
            <TableHead className="px-4 py-3 text-right text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              Cost/1M
            </TableHead>
            <TableHead className="px-4 py-3 text-right text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              Overall
            </TableHead>
            <TableHead className="px-4 py-3 text-right text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              Auth
            </TableHead>
            <TableHead className="px-4 py-3 text-right text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              TablesDB
            </TableHead>
            <TableHead className="px-4 py-3 text-right text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              Functions
            </TableHead>
            <TableHead className="px-4 py-3 text-right text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              Storage
            </TableHead>
            <TableHead className="px-4 py-3 text-right text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              Sites
            </TableHead>
            <TableHead className="px-4 py-3 text-right text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              Messaging
            </TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {BENCHMARK_ROWS.map((row) => (
            <TableRow key={row.model} className="hover:bg-accent/10">
              <TableCell className="px-4 py-3">
                <span className="flex items-center gap-2.5">
                  <img src={row.icon} alt="" className="size-4 object-contain" />
                  <span className="text-[13px] font-medium text-foreground">{row.model}</span>
                </span>
              </TableCell>
              <TableCell className="px-4 py-3 text-right text-[13px] tabular-nums text-muted-foreground">
                {row.cost}
              </TableCell>
              <TableCell className="px-4 py-3 text-right text-[13px] font-semibold tabular-nums text-emerald-600 dark:text-emerald-400">
                {formatScore(row.overall)}
              </TableCell>
              <TableCell className="px-4 py-3 text-right text-[13px] tabular-nums text-muted-foreground">
                {formatScore(row.auth)}
              </TableCell>
              <TableCell className="px-4 py-3 text-right text-[13px] tabular-nums text-muted-foreground">
                {formatScore(row.tablesDb)}
              </TableCell>
              <TableCell className="px-4 py-3 text-right text-[13px] tabular-nums text-muted-foreground">
                {formatScore(row.functions)}
              </TableCell>
              <TableCell className="px-4 py-3 text-right text-[13px] tabular-nums text-muted-foreground">
                {formatScore(row.storage)}
              </TableCell>
              <TableCell className="px-4 py-3 text-right text-[13px] tabular-nums text-muted-foreground">
                {formatScore(row.sites)}
              </TableCell>
              <TableCell className="px-4 py-3 text-right text-[13px] tabular-nums text-muted-foreground">
                {formatScore(row.messaging)}
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  )
}

export function AiSection() {
  return (
    <section className="relative isolate overflow-hidden border-t border-border bg-background py-16 sm:py-20">
      <div
        className="pointer-events-none absolute inset-0 bg-[radial-gradient(circle,var(--border)_1px,transparent_1px)] bg-[length:18px_18px] opacity-70"
        aria-hidden
      />

      <div className="relative mx-auto w-full max-w-7xl px-4 sm:px-6">
        <h2 className="font-aeonik-pro max-w-3xl text-balance text-[36px] font-normal leading-none tracking-tight text-foreground sm:text-[44px]">
          Designed for the AI agents in your workflow
          <span className="text-[var(--brand-cta)]">_</span>
        </h2>

        <div className="mt-10 overflow-hidden rounded-xl border border-border bg-card/50 lg:mt-12">
          <div className="grid lg:grid-cols-2">
            <McpFeaturePanel />
            <SkillsFeaturePanel />
          </div>
        </div>

        <AiPluginsSection />

        <div className="pt-8 sm:pt-10">
          <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div className="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-baseline sm:gap-4 lg:gap-5">
              <h3 className="font-aeonik-pro shrink-0 text-[16px] font-normal text-foreground sm:text-[17px]">
                Benchmark
              </h3>
              <p className="min-w-0 max-w-xl text-[14px] leading-6 text-muted-foreground sm:text-[15px]">
                Works with every major LLM. Find out how well your model integrates with
                Appwrite.
              </p>
            </div>
            <div className="flex shrink-0 flex-wrap gap-2">
              <Button variant="outline" className="h-10 text-[13px]" asChild>
                <a
                  href="https://arena.appwrite.io/"
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  View full benchmark
                </a>
              </Button>
            </div>
          </div>

          <div className="mt-6 overflow-hidden rounded-xl border border-border bg-card/45">
            <BenchmarkTable />
          </div>
        </div>
      </div>
    </section>
  )
}
