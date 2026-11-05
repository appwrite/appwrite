import type { CSSProperties } from 'react'
import { Bot, Sparkles, User } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import {
  ArtIconBadge,
  ArtPanel,
  floatStyle,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

/** Humans vs bot categories, as segments of one bar (shares of all traffic). */
const SEGMENTS = [
  { label: 'Humans', share: 81, className: 'bg-[var(--tone-ink)]' },
  { label: 'AI crawler', share: 9, className: 'bg-amber-500' },
  { label: 'Search crawler', share: 6, className: 'bg-teal-500' },
  { label: 'Social preview', share: 4, className: 'bg-slate-400' },
] as const

/** Named agents scattered beside the card, like request attributes on Firewall. */
const AGENTS: { name: string; category: string; className: string; fade?: boolean }[] = [
  { name: 'GPTBot', category: 'AI crawler', className: 'end-[2%] top-[4%]' },
  { name: 'ClaudeBot', category: 'AI crawler', className: 'end-[-4%] top-[22%]' },
  { name: 'ChatGPT-User', category: 'AI assistant', className: 'end-[6%] top-[40%]', fade: true },
  { name: 'Googlebot', category: 'Search crawler', className: 'end-[-2%] top-[58%]' },
  { name: 'Slackbot', category: 'Social preview', className: 'end-[8%] top-[76%]', fade: true },
]

const AI_REFERRERS = [
  { label: 'chatgpt.com', value: '1.4K' },
  { label: 'claude.ai', value: '520' },
  { label: 'perplexity.ai', value: '310' },
] as const

/** Hatching marks machine traffic, as in the console's Humans vs bots card. */
const HATCH = 'repeating-linear-gradient(135deg, rgba(255,255,255,0.25) 0 3px, transparent 3px 6px)'

export function AnalyticsAiTrafficVisual() {
  const t = useT()

  return (
    <div className="relative mx-auto w-full max-w-[540px] py-2">
      {AGENTS.map((agent, index) => (
        <span
          key={agent.name}
          className={cn('product-hero-rise absolute hidden sm:block', agent.className)}
          style={riseStyle(700 + index * 90)}
          aria-hidden
        >
          <span
            className={cn(
              'product-hero-float inline-flex items-center gap-1.5 rounded-full border border-border bg-background px-2.5 py-1 text-[11px] shadow-sm dark:bg-card',
              agent.fade && 'opacity-55',
            )}
            style={floatStyle(index * 420)}
          >
            <Bot className="size-3 text-muted-foreground" aria-hidden />
            <span dir="ltr" className="font-mono text-foreground">
              {agent.name}
            </span>
            <span className="text-muted-foreground">{t(agent.category)}</span>
          </span>
        </span>
      ))}

      <div className="relative z-[1] w-full space-y-3 sm:w-[330px]">
        <ArtPanel delayMs={60} innerClassName="product-tone-shadow px-3.5 py-3.5">
          <div className="flex items-end justify-between gap-4">
            <div className="flex items-center gap-2.5">
              <ArtIconBadge icon={User} />
              <div>
                <p className="text-[10.5px] text-muted-foreground">{t('Humans')}</p>
                <p dir="ltr" className="font-aeonik-pro text-[20px] leading-none tracking-tight text-foreground">
                  81%
                </p>
              </div>
            </div>
            <div className="flex items-center gap-2.5 text-end">
              <div>
                <p className="text-[10.5px] text-muted-foreground">{t('Bots')}</p>
                <p dir="ltr" className="font-aeonik-pro text-[20px] leading-none tracking-tight text-foreground">
                  19%
                </p>
              </div>
              <ArtIconBadge icon={Bot} tone="neutral" />
            </div>
          </div>
          <div className="mt-3 flex h-3 gap-[2px] overflow-hidden rounded-full" aria-hidden>
            {SEGMENTS.map((segment, index) => (
              <span
                key={segment.label}
                className={cn(
                  'product-hero-fill h-full',
                  index === 0 && 'rounded-s-full',
                  index === SEGMENTS.length - 1 && 'rounded-e-full',
                  segment.className,
                )}
                style={
                  {
                    width: `${segment.share}%`,
                    backgroundImage: index === 0 ? undefined : HATCH,
                    '--fill-delay': `${200 + index * 120}ms`,
                  } as CSSProperties
                }
              />
            ))}
          </div>
          <div className="mt-2.5 flex flex-wrap gap-x-3 gap-y-1">
            {SEGMENTS.slice(1).map((segment) => (
              <span key={segment.label} className="inline-flex items-center gap-1.5 text-[10.5px] text-muted-foreground">
                <span className={cn('size-2 rounded-sm', segment.className)} aria-hidden />
                {t(segment.label)}
              </span>
            ))}
          </div>
        </ArtPanel>

        <div className="ms-12 h-4 border-s border-dashed border-foreground/25" aria-hidden />

        <ArtPanel
          className="sm:ms-8"
          delayMs={360}
          float
          floatDelayMs={300}
          innerClassName="px-3.5 py-3"
        >
          <div className="flex items-center justify-between gap-2">
            <p className="flex items-center gap-1.5 text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">
              <Sparkles className="size-3 text-[var(--tone-ink)]" aria-hidden />
              {t('AI channel')}
            </p>
            <Badge variant="success" className="text-[10px]">
              {t('People')}
            </Badge>
          </div>
          <ul className="mt-2 space-y-1">
            {AI_REFERRERS.map((referrer) => (
              <li key={referrer.label} className="flex items-center justify-between text-[11.5px]">
                <span dir="ltr" className="text-foreground">
                  {referrer.label}
                </span>
                <span dir="ltr" className="font-medium tabular-nums text-foreground">
                  {referrer.value}
                </span>
              </li>
            ))}
          </ul>
        </ArtPanel>
      </div>
    </div>
  )
}
