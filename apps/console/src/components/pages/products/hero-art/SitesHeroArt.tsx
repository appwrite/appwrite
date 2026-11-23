import { Check, GitBranch, Lock } from 'lucide-react'
import type { CSSProperties } from 'react'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import {
  ArtChip,
  ArtLiveDot,
  ArtWindow,
  riseStyle,
} from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'

const DEPLOY_STEPS = [
  { label: 'Cloning repository', duration: '2s' },
  { label: 'Building', duration: '38s' },
  { label: 'Deploying', duration: '4s' },
] as const

function SitePreview() {
  return (
    <div className="product-hero-rise relative overflow-hidden" style={riseStyle(1500)} aria-hidden>
      <div className="flex items-center justify-between border-b border-border px-5 py-3">
        <div className="flex items-center gap-2">
          <span className="size-3 rounded-full bg-[var(--brand-cta)]" />
          <span className="h-2 w-12 rounded-full bg-foreground/70" />
        </div>
        <div className="hidden items-center gap-4 sm:flex">
          <span className="h-1.5 w-10 rounded-full bg-muted-foreground/30" />
          <span className="h-1.5 w-10 rounded-full bg-muted-foreground/30" />
          <span className="h-1.5 w-10 rounded-full bg-muted-foreground/30" />
          <span className="h-6 w-16 rounded-md bg-foreground/80" />
        </div>
      </div>
      <div className="relative px-5 pb-6 pt-10 text-center sm:pt-14">
        <div className="absolute inset-x-0 top-0 h-full bg-[radial-gradient(ellipse_60%_70%_at_50%_0%,rgb(var(--tone-rgb)/0.22),transparent_70%)]" />
        <div className="relative">
          <span className="mx-auto block h-4 w-3/5 rounded-full bg-foreground/80 sm:h-5" />
          <span className="mx-auto mt-2.5 block h-4 w-2/5 rounded-full bg-foreground/80 sm:h-5" />
          <span className="mx-auto mt-4 block h-1.5 w-1/2 rounded-full bg-muted-foreground/30" />
          <span className="mx-auto mt-1.5 block h-1.5 w-1/3 rounded-full bg-muted-foreground/30" />
          <div className="mt-5 flex justify-center gap-2">
            <span className="h-7 w-24 rounded-md bg-[var(--brand-cta)]" />
            <span className="h-7 w-20 rounded-md border border-border" />
          </div>
        </div>
        <div className="relative mt-8 grid grid-cols-3 gap-3">
          {[0, 1, 2].map((card) => (
            <div key={card} className="rounded-lg border border-border bg-muted/30 p-3 text-start">
              <span className="block size-5 rounded-md bg-[rgb(var(--tone2-rgb)/0.35)]" />
              <span className="mt-3 block h-1.5 w-3/4 rounded-full bg-foreground/50" />
              <span className="mt-1.5 block h-1.5 w-1/2 rounded-full bg-muted-foreground/30" />
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}

export function SitesHeroArt() {
  const t = useT()

  return (
    <div className="relative pb-10 sm:px-16 sm:pb-6">
      <ArtWindow
        className="product-hero-rise"
        style={riseStyle(60)}
        bodyClassName="p-0"
        title={
          <span className="flex items-center gap-1.5 rounded-md bg-background px-2.5 py-1 dark:bg-muted/40" dir="ltr">
            <Lock className="size-3 text-emerald-600 dark:text-emerald-400" aria-hidden />
            acme.appwrite.network
          </span>
        }
        trailing={
          <span className="flex items-center gap-1.5 text-[11px] text-muted-foreground">
            <ArtLiveDot />
            {t('Live')}
          </span>
        }
      >
        <SitePreview />
      </ArtWindow>

      <div
        className="product-hero-rise absolute -bottom-2 start-0 z-[2] w-[260px] sm:-start-2 sm:bottom-10"
        style={riseStyle(400)}
      >
        <div className="product-tone-shadow rounded-xl border border-border bg-background p-3.5 text-start dark:bg-card">
          <div className="flex items-center justify-between gap-2">
            <div className="flex items-center gap-2">
              <FrameworkIcon framework="nextjs" className="size-4" />
              <p className="text-[12px] font-semibold text-foreground">{t('Build logs')}</p>
            </div>
            <span dir="ltr" className="font-mono text-[10px] text-muted-foreground">44s</span>
          </div>
          <div className="mt-2.5 h-1 overflow-hidden rounded-full bg-muted">
            <div
              className="product-hero-fill h-full rounded-full bg-[var(--tone-ink)]"
              style={{ '--fill-delay': '500ms', '--fill-duration': '1.2s' } as CSSProperties}
            />
          </div>
          <ul className="mt-3 space-y-2">
            {DEPLOY_STEPS.map((step, index) => (
              <li
                key={step.label}
                className="product-hero-rise flex items-center gap-2 text-[11.5px]"
                style={riseStyle(600 + index * 280)}
              >
                <span className="flex size-4 items-center justify-center rounded-full bg-emerald-500/15 text-emerald-600 dark:text-emerald-400">
                  <Check className="size-2.5" strokeWidth={3} aria-hidden />
                </span>
                <span className="flex-1 text-foreground">{t(step.label)}</span>
                <span dir="ltr" className="font-mono text-[10px] text-muted-foreground">{step.duration}</span>
              </li>
            ))}
            <li
              className="product-hero-rise flex items-center gap-2 text-[11.5px]"
              style={riseStyle(600 + DEPLOY_STEPS.length * 280)}
            >
              <span className="flex size-4 items-center justify-center">
                <ArtLiveDot />
              </span>
              <span className="font-medium text-emerald-700 dark:text-emerald-400">{t('Ready')}</span>
            </li>
          </ul>
        </div>
      </div>

      <ArtChip className="end-0 top-14 hidden sm:block" delayMs={1000}>
        <div className="flex items-center gap-2 text-start">
          <span className="flex size-7 items-center justify-center rounded-md bg-[rgb(var(--tone-rgb)/0.16)] text-[var(--tone-ink)]">
            <GitBranch className="size-3.5" aria-hidden />
          </span>
          <div dir="ltr">
            <p className="text-[11px] font-medium text-foreground">
              main <span className="font-mono text-muted-foreground">a1b2c3d</span>
            </p>
            <p className="font-mono text-[10px] text-muted-foreground">feat: launch page</p>
          </div>
        </div>
      </ArtChip>

      <ArtChip className="bottom-24 end-0 hidden sm:block" delayMs={1300} floatDelayMs={1000}>
        <div className="flex items-center gap-2 text-start">
          <Lock className="size-3.5 text-emerald-600 dark:text-emerald-400" aria-hidden />
          <p dir="ltr" className="font-mono text-[11px] text-foreground">acme.com</p>
          <span className="rounded bg-emerald-500/10 px-1.5 py-0.5 text-[10px] font-medium text-emerald-700 dark:text-emerald-400">
            {t('Verified')}
          </span>
        </div>
      </ArtChip>
    </div>
  )
}
