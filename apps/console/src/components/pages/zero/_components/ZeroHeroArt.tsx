import { useEffect, useState } from 'react'
import { Bot, CheckCircle2, KeyRound, Link2 } from 'lucide-react'
import { AppwriteMarkIcon } from '@/components/global/shared/AppwriteMarkIcon'
import {
  ArtChip,
  ArtConnector,
  ArtLiveDot,
  ArtWindow,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { ProductIconTile } from '@/components/pages/products/_components/ProductTone'
import { useT } from '@/lib/i18n/translate'
import { PRODUCT_REGISTRY } from '@/lib/products/registry'
import type { ProductIcon } from '@/lib/products/types'
import { cn } from '@/lib/utils'
import {
  ZERO_BUILD_MINUTES,
  ZERO_COMMAND,
  zeroAgents,
  zeroResponse,
  zeroServiceItems,
} from '@/lib/zero/marketing-content'

function responseValue(key: string): string {
  return zeroResponse.find((row) => row.key === key)?.value ?? ''
}

const PROJECT_ID = responseValue('projectId')
const EXAMPLE = zeroAgents.examples[0]

/** Every service the project has, then the key that reaches all of them. */
const TILES: { id: string; name: string; icon: ProductIcon }[] = [
  ...zeroServiceItems.map(({ id }) => ({
    id,
    name: PRODUCT_REGISTRY[id].name,
    icon: PRODUCT_REGISTRY[id].icon,
  })),
  { id: 'key', name: 'API key', icon: KeyRound },
]

/** What `npx appwrite zero` prints first, trimmed to fit the window. */
const TERMINAL_LINES = [
  { text: '✓ Your Appwrite Zero project is live', success: true },
  { text: `  Project    ${PROJECT_ID}` },
  { text: `  Endpoint   ${responseValue('endpoint')}` },
  { text: `  You have ${ZERO_BUILD_MINUTES} min to build.` },
] as const

const BUILD_SECONDS = ZERO_BUILD_MINUTES * 60
const CLOCK_START_MS = 1600

/** Counts the build hour down once the panel has landed. Server render shows the full hour. */
function useBuildSecondsLeft(): number {
  const [seconds, setSeconds] = useState(BUILD_SECONDS)

  useEffect(() => {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return
    let interval: number | undefined
    const start = window.setTimeout(() => {
      interval = window.setInterval(() => {
        setSeconds((left) => (left > 1 ? left - 1 : BUILD_SECONDS))
      }, 1000)
    }, CLOCK_START_MS)
    return () => {
      window.clearTimeout(start)
      window.clearInterval(interval)
    }
  }, [])

  return seconds
}

function formatClock(seconds: number): string {
  const minutes = Math.floor(seconds / 60)
  return `${String(minutes).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`
}

/** A ruler with one tick per minute of the build hour. Spent minutes fade; the minute running now is the pink edge. */
function BuildClock() {
  const t = useT()
  const seconds = useBuildSecondsLeft()
  const minutesLeft = Math.ceil(seconds / 60)
  const edge = minutesLeft - 1

  return (
    <div className="border-b border-border px-4 py-3.5">
      <div className="flex items-baseline justify-between gap-3">
        <span className="text-[11px] text-muted-foreground">
          {t('Build time left')}
        </span>
        <span
          dir="ltr"
          className="font-mono text-[15px] text-foreground tabular-nums"
        >
          {formatClock(seconds)}
        </span>
      </div>
      <div
        dir="ltr"
        className="mt-3 flex h-5 items-end justify-between"
        aria-hidden
      >
        {Array.from({ length: ZERO_BUILD_MINUTES }, (_, minute) => {
          const quarter = minute % 15 === 0
          return (
            <span
              key={minute}
              className={cn(
                'product-hero-rise w-[2px]',
                quarter || minute === edge ? 'h-5' : 'h-3',
              )}
              style={riseStyle(700 + minute * 12)}
            >
              <span
                className={cn(
                  'block size-full rounded-full transition-colors duration-700',
                  minute === edge
                    ? 'animate-pulse bg-[var(--tone-ink)] shadow-[0_0_8px_rgb(var(--tone-rgb))] motion-reduce:animate-none'
                    : minute > edge
                      ? 'bg-foreground/10'
                      : quarter
                        ? 'bg-foreground/60'
                        : 'bg-foreground/25',
                )}
              />
            </span>
          )
        })}
      </div>
    </div>
  )
}

function TerminalWindow() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-xl lg:max-w-none">
      <ArtWindow
        className="product-hero-rise"
        style={riseStyle(100)}
        title={t('Terminal')}
        bodyClassName="px-4 py-3.5"
      >
        <div dir="ltr" className="font-mono text-[11.5px] leading-6">
          <p
            className="product-hero-rise text-foreground"
            style={riseStyle(250)}
          >
            <span className="select-none text-muted-foreground">$ </span>
            {ZERO_COMMAND}
          </p>
          {TERMINAL_LINES.map((line, index) => (
            <p
              key={line.text}
              className={cn(
                'product-hero-rise whitespace-pre',
                'success' in line
                  ? 'text-emerald-600 dark:text-emerald-400'
                  : 'text-muted-foreground',
              )}
              style={riseStyle(550 + index * 130)}
            >
              {line.text}
            </p>
          ))}
        </div>
      </ArtWindow>

      <ArtChip
        className="-top-6 end-4 hidden lg:block"
        delayMs={2100}
        floatDelayMs={600}
      >
        <div className="flex items-center gap-2 text-start">
          <span className="flex size-7 items-center justify-center rounded-md border border-border bg-muted/40 text-muted-foreground">
            <Bot className="size-3.5" aria-hidden />
          </span>
          <div>
            <p className="text-[11px] font-medium text-foreground">
              {t('Your agent')}
            </p>
            <p className="flex items-center gap-1 text-[10.5px] text-muted-foreground">
              <CheckCircle2
                className="size-3 text-emerald-600 dark:text-emerald-400"
                aria-hidden
              />
              {t(EXAMPLE.step)}
            </p>
          </div>
        </div>
      </ArtChip>
    </div>
  )
}

function ProjectPanel() {
  const t = useT()

  return (
    <div
      className="product-hero-rise product-tone-shadow mx-auto w-full max-w-2xl overflow-hidden rounded-2xl border border-[rgb(var(--tone-rgb)/0.35)] bg-background text-start dark:bg-card lg:max-w-none"
      style={riseStyle(450)}
    >
      <div className="flex items-center gap-3 border-b border-border px-4 py-3">
        <span className="flex size-8 shrink-0 items-center justify-center rounded-lg border border-border bg-muted/40 text-foreground">
          <AppwriteMarkIcon className="size-4" />
        </span>
        <div className="min-w-0" dir="ltr">
          <p className="truncate text-[13px] font-semibold text-foreground">
            {EXAMPLE.site}
          </p>
          <p className="truncate font-mono text-[10.5px] text-muted-foreground">
            {PROJECT_ID}
          </p>
        </div>
        <span className="ms-auto flex shrink-0 items-center gap-1.5 rounded-md border border-border px-2 py-1 text-[11px] text-muted-foreground">
          <ArtLiveDot />
          {t('Zero project')}
        </span>
      </div>

      <BuildClock />

      <ul className="grid grid-cols-2 gap-2 p-3 sm:grid-cols-4">
        {TILES.map((tile, index) => (
          <li
            key={tile.id}
            className="product-hero-rise"
            style={riseStyle(1100 + index * 80)}
          >
            <ServiceTile icon={tile.icon} name={t(tile.name)} />
          </li>
        ))}
      </ul>

      <div
        className="product-hero-rise flex items-center gap-2.5 border-t border-border bg-muted/20 px-4 py-2.5"
        style={riseStyle(1900)}
      >
        <Link2
          className="size-3.5 shrink-0 text-[var(--tone-ink)]"
          aria-hidden
        />
        <span className="shrink-0 text-[11px] text-muted-foreground">
          {t('Claim link')}
        </span>
        <span
          dir="ltr"
          className="min-w-0 flex-1 truncate font-mono text-[11px] text-foreground"
        >
          {zeroAgents.claimLink}
        </span>
        <span className="shrink-0 rounded-md bg-[var(--brand-cta)] px-2.5 py-1 text-[11px] font-medium text-white">
          {t('Claim project')}
        </span>
      </div>
    </div>
  )
}

function ServiceTile({ icon, name }: { icon: ProductIcon; name: string }) {
  const t = useT()

  return (
    <div className="flex h-full items-center gap-2.5 rounded-lg border border-border bg-muted/20 p-2 dark:bg-muted/10">
      <ProductIconTile icon={icon} size="sm" />
      <div className="min-w-0">
        <p className="truncate text-[12px] font-semibold text-foreground">
          {name}
        </p>
        <p className="flex items-center gap-1 text-[10.5px] text-emerald-700 dark:text-emerald-400">
          <span className="size-1.5 rounded-full bg-emerald-500" />
          {t('Ready')}
        </p>
      </div>
    </div>
  )
}

/**
 * The hero's centerpiece: one command, then a live Zero project with its build hour
 * ticking, every service ready, an all-scope key and the link that keeps it.
 */
export function ZeroHeroArt() {
  return (
    <div className="grid items-center gap-6 pb-2 lg:grid-cols-[minmax(0,0.82fr)_56px_minmax(0,1.18fr)] lg:gap-0 lg:pb-8">
      <TerminalWindow />
      <div className="hidden items-center lg:flex">
        <ArtConnector travel travelDelayMs={900} />
      </div>
      <ProjectPanel />
    </div>
  )
}
