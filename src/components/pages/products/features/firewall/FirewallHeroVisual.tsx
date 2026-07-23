'use client'

import type { CSSProperties } from 'react'
import {
  Ban,
  ArrowRightLeft,
  Shield,
  ShieldAlert,
  ShieldOff,
  Timer,
  type LucideIcon,
} from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

type FirewallHeroAction = 'deny' | 'bypass' | 'rate-limit' | 'redirect' | 'challenge'

const HERO_ACTIONS: {
  id: FirewallHeroAction
  label: string
  icon: LucideIcon
  toneClass: string
}[] = [
  {
    id: 'deny',
    label: 'Deny',
    icon: Ban,
    toneClass: 'text-red-600 dark:text-red-400',
  },
  {
    id: 'bypass',
    label: 'Bypass',
    icon: ShieldOff,
    toneClass: 'text-blue-600 dark:text-blue-400',
  },
  {
    id: 'rate-limit',
    label: 'Rate limit',
    icon: Timer,
    toneClass: 'text-amber-600 dark:text-amber-400',
  },
  {
    id: 'redirect',
    label: 'Redirect',
    icon: ArrowRightLeft,
    toneClass: 'text-slate-600 dark:text-slate-300',
  },
  {
    id: 'challenge',
    label: 'Challenge',
    icon: ShieldAlert,
    toneClass: 'text-violet-600 dark:text-violet-400',
  },
]

const TRAFFIC_REQUESTS = [
  { id: 'req-1', outcome: 'pass', tone: 'pass', delayMs: 0 },
  { id: 'req-2', outcome: 'block', tone: 'deny', delayMs: 220 },
  { id: 'req-3', outcome: 'pass', tone: 'pass', delayMs: 440 },
  { id: 'req-4', outcome: 'pass', tone: 'challenge', delayMs: 660 },
  { id: 'req-5', outcome: 'block', tone: 'deny', delayMs: 880 },
  { id: 'req-6', outcome: 'pass', tone: 'pass', delayMs: 1100 },
] as const

const REQUEST_TONE_CLASS = {
  pass: 'bg-emerald-500',
  deny: 'bg-red-500',
  challenge: 'bg-violet-500',
} as const

export function FirewallHeroVisual() {
  const t = useT()

  return (
    <div className="mt-10 w-full">
      <div className="mx-auto flex max-w-3xl flex-col items-center gap-5">
        <div className="w-full px-1 sm:px-2">
          <div className="relative h-10">
            <div
              className="absolute inset-x-0 top-1/2 h-px -translate-y-1/2 bg-border"
              aria-hidden
            />
            <span className="product-hero-firewall-shield-pulse absolute left-1/2 top-1/2 z-[1] flex size-9 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border border-border bg-background">
              <Shield className="size-4 text-[var(--brand-cta)]" aria-hidden />
            </span>
            {TRAFFIC_REQUESTS.map((request) => (
              <span
                key={request.id}
                className={cn(
                  'absolute top-1/2 size-2 rounded-full opacity-0',
                  REQUEST_TONE_CLASS[request.tone],
                  request.outcome === 'block'
                    ? 'product-hero-firewall-request-block'
                    : 'product-hero-firewall-request-pass',
                )}
                style={{ '--fw-delay': `${request.delayMs}ms` } as CSSProperties}
                aria-hidden
              />
            ))}
          </div>
          <div className="mt-3 flex items-center justify-between gap-2 text-[11px] text-muted-foreground">
            <span>{t('Incoming')}</span>
            <span className="text-center">{t('Firewall')}</span>
            <span>{t('Your app')}</span>
          </div>
        </div>

        <div className="flex flex-wrap items-center justify-center gap-2">
          {HERO_ACTIONS.map((action) => {
            const Icon = action.icon
            return (
              <Badge
                key={action.id}
                variant="inactive"
                className="h-7 gap-1.5 px-2.5 text-[11px]"
              >
                <Icon className={cn('size-3', action.toneClass)} aria-hidden />
                {t(action.label)}
              </Badge>
            )
          })}
        </div>
      </div>
    </div>
  )
}
