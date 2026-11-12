'use client'

import { lazy, Suspense, useRef } from 'react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { MarketingSiteLink } from '@/components/global/shared/MarketingSiteLink'
import { ProductAvatarsList } from '@/components/global/shared/ProductAvatarsList'
import { getHomeMcpStackAvatarItems, getOfficialPlugins, type HomePluginConfig } from '@/lib/config/ide'
import { AiChatSoftLight, AiTileSoftLight } from '@/components/pages/home/HomeSoftLights'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { useIntersectionVisible } from '@/hooks/use-intersection-visible'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'

const OFFICIAL_PLUGINS = getOfficialPlugins()

const LazyAiAgentWorkspace = lazy(async () => {
  const module = await import('@/components/pages/home/AiCodingChatVisual')
  return { default: module.AiAgentWorkspace }
})

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
    icon: '/icons/grok-build.svg',
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
  const t = useT()

  return (
    <MarketingSiteLink
      href={href}
      className="group flex items-center gap-3 rounded-lg border border-border bg-background/60 px-3 py-2.5 transition-colors hover:bg-accent/15"
    >
      <span className="flex size-8 shrink-0 items-center justify-center rounded-md border border-border bg-muted/40">
        <img
          src={plugin.iconPath}
          alt=""
          className={cn('size-4 object-contain', PUBLIC_ICON_MUTED_CLASSES)}
        />
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
            {t(badge.label)}
          </Badge>
        ))}
      </span>
    </MarketingSiteLink>
  )
}

function MoreIntegrationsIcons() {
  const t = useT()

  return (
    <ProductAvatarsList
      items={getHomeMcpStackAvatarItems()}
      ariaLabel={t('Integrations')}
    />
  )
}

function AiPluginsSection() {
  const t = useT()
  return (
    <div className="relative mt-10 overflow-visible pb-4 lg:mt-12">
      <div className="relative mx-auto max-w-4xl py-8">
        <AiTileSoftLight tone="plugins" align="center" />
        <div className="relative space-y-1.5 text-center">
          <h3 className="font-aeonik-pro text-[16px] font-normal text-foreground sm:text-[18px]">
            {t('Works great with your favorite agents')}
          </h3>
        </div>
        <div className="relative mt-6 grid grid-cols-1 gap-2 sm:grid-cols-3">
          {OFFICIAL_PLUGINS.map((plugin) => (
            <PluginTile
              key={plugin.id}
              plugin={plugin}
              href={plugin.docsUrl}
              badges={[{ label: 'Official', variant: 'info' }]}
            />
          ))}
        </div>
        <div className="relative mt-4 flex justify-center">
          <MoreIntegrationsIcons />
        </div>
      </div>
    </div>
  )
}

function ArenaBlock() {
  const t = useT()
  return (
    <div className="pt-8 sm:pt-10">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div className="flex min-w-0 flex-col gap-2 sm:flex-row sm:items-baseline sm:gap-4 lg:gap-5">
          <h3 className="font-aeonik-pro shrink-0 text-[16px] font-normal text-foreground sm:text-[17px]">
            {t('AI Arena')}
          </h3>
          <p className="min-w-0 max-w-2xl text-[14px] leading-6 text-muted-foreground sm:text-[15px]">
            {t(
              'We constantly benchmark platform performance against every leading model so you can see which one works best with Appwrite.', // pragma: allowlist secret
            )}
          </p>
        </div>
        <div className="flex shrink-0 flex-wrap gap-2">
          <Button variant="outline" className="h-10 text-[13px]" asChild>
            <a
              href="https://arena.appwrite.io/"
              target="_blank"
              rel="noopener noreferrer"
            >
              {t('View full benchmark')}
            </a>
          </Button>
        </div>
      </div>
      <div className="mt-6 overflow-hidden rounded-xl border border-border bg-card/45">
        <BenchmarkTable />
      </div>
    </div>
  )
}

function BenchmarkTable() {
  const t = useT()
  return (
    <div className="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
      <Table className="min-w-[56rem]">
        <TableHeader>
          <TableRow className="hover:bg-transparent border-b border-border">
            <TableHead className="px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Model')}
            </TableHead>
            <TableHead className="px-4 py-3 text-end text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Cost/1M')}
            </TableHead>
            <TableHead className="px-4 py-3 text-end text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Overall')}
            </TableHead>
            <TableHead className="px-4 py-3 text-end text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Auth')}
            </TableHead>
            <TableHead className="px-4 py-3 text-end text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              TablesDB
            </TableHead>
            <TableHead className="px-4 py-3 text-end text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Functions')}
            </TableHead>
            <TableHead className="px-4 py-3 text-end text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Storage')}
            </TableHead>
            <TableHead className="px-4 py-3 text-end text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Sites')}
            </TableHead>
            <TableHead className="px-4 py-3 text-end text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
              {t('Messaging')}
            </TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {BENCHMARK_ROWS.map((row) => (
            <TableRow key={row.model} className="hover:bg-accent/10">
              <TableCell className="px-4 py-3">
                <span className="flex items-center gap-2.5">
                  <img src={row.icon} alt="" className="size-4 object-contain" />
                  <span className="text-[13px] font-medium text-foreground">
                    {row.model}
                  </span>
                </span>
              </TableCell>
              <TableCell className="px-4 py-3 text-end text-[13px] tabular-nums text-muted-foreground">
                {row.cost}
              </TableCell>
              <TableCell className="px-4 py-3 text-end text-[13px] font-semibold tabular-nums text-emerald-600 dark:text-emerald-400">
                {formatScore(row.overall)}
              </TableCell>
              <TableCell className="px-4 py-3 text-end text-[13px] tabular-nums text-muted-foreground">
                {formatScore(row.auth)}
              </TableCell>
              <TableCell className="px-4 py-3 text-end text-[13px] tabular-nums text-muted-foreground">
                {formatScore(row.tablesDb)}
              </TableCell>
              <TableCell className="px-4 py-3 text-end text-[13px] tabular-nums text-muted-foreground">
                {formatScore(row.functions)}
              </TableCell>
              <TableCell className="px-4 py-3 text-end text-[13px] tabular-nums text-muted-foreground">
                {formatScore(row.storage)}
              </TableCell>
              <TableCell className="px-4 py-3 text-end text-[13px] tabular-nums text-muted-foreground">
                {formatScore(row.sites)}
              </TableCell>
              <TableCell className="px-4 py-3 text-end text-[13px] tabular-nums text-muted-foreground">
                {formatScore(row.messaging)}
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  )
}

function DeferredAiWorkspace() {
  const containerRef = useRef<HTMLDivElement>(null)
  const { hasBeenVisible } = useIntersectionVisible(containerRef, {
    rootMargin: '400px 0px',
    once: true,
  })

  return (
    <div ref={containerRef} className="min-h-[32rem]">
      {hasBeenVisible ? (
        <Suspense fallback={<div className="h-[32rem]" aria-hidden />}>
          <LazyAiAgentWorkspace />
        </Suspense>
      ) : (
        <div className="h-[32rem]" aria-hidden />
      )}
    </div>
  )
}

export function AiSection() {
  const t = useT()

  return (
    <section className="relative isolate overflow-hidden border-t border-border bg-background py-16 sm:py-20">
      <div
        className="pointer-events-none absolute inset-0 bg-[radial-gradient(circle,var(--border)_1px,transparent_1px)] bg-[length:18px_18px] opacity-70"
        aria-hidden
      />
      <AiChatSoftLight />

      <div className="relative mx-auto w-full max-w-7xl px-4 sm:px-6">
        <div className="mx-auto max-w-3xl space-y-3 text-center">
          <h2 className="font-aeonik-pro text-balance text-[36px] font-normal leading-none tracking-tight text-foreground sm:text-[44px]">
            {t('Designed for the AI agents in your workflow')}
            <span className="text-[var(--brand-cta)]">_</span>
          </h2>
          <p className="mx-auto max-w-2xl text-[15px] leading-6 text-muted-foreground">
            {t(
              'Query Postgres, chart traffic, and ship a campaign. Your agent does it on a live Appwrite project.', // pragma: allowlist secret
            )}
          </p>
        </div>

        <div className="mt-10 lg:mt-12">
          <DeferredAiWorkspace />
        </div>

        <AiPluginsSection />
        <ArenaBlock />
      </div>
    </section>
  )
}
