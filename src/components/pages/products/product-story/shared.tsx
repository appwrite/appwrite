import type { ReactNode } from 'react'
import { cn } from '@/lib/utils'

export const storyShell =
  'overflow-hidden rounded-xl border border-border bg-card/50 shadow-sm'

export const storyPanel = 'rounded-lg border border-border bg-background'

export const storyHeader =
  'flex items-center justify-between gap-3 border-b border-border bg-muted/15 px-4 py-3'

export function StoryWizardChrome({
  title,
  subtitle,
  step,
  children,
  className,
}: {
  title: string
  subtitle?: string
  step?: string
  children: ReactNode
  className?: string
}) {
  return (
    <div className={cn(storyShell, 'flex h-full flex-col', className)}>
      <div className={storyHeader}>
        <div className="min-w-0">
          <p className="truncate text-[13px] font-semibold text-foreground">{title}</p>
          {subtitle ? (
            <p className="mt-0.5 truncate text-[11px] text-muted-foreground">{subtitle}</p>
          ) : null}
        </div>
        {step ? (
          <span className="shrink-0 rounded-full border border-border bg-muted/30 px-2 py-0.5 text-[10px] font-medium text-muted-foreground">
            {step}
          </span>
        ) : null}
      </div>
      <div className="min-h-0 flex-1 p-4 sm:p-5">{children}</div>
    </div>
  )
}

export function StoryField({
  label,
  value,
  active = false,
  mono = false,
}: {
  label: string
  value: string
  active?: boolean
  mono?: boolean
}) {
  return (
    <div className="space-y-1.5">
      <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
        {label}
      </p>
      <div
        className={cn(
          'rounded-md border px-3 py-2 text-[12px] transition-colors duration-500',
          mono && 'font-mono',
          active
            ? 'border-[color-mix(in_srgb,var(--brand-cta)_35%,var(--border))] bg-[color-mix(in_srgb,var(--brand-cta)_6%,var(--background))] text-foreground'
            : 'border-border bg-muted/10 text-muted-foreground',
        )}
      >
        {value}
      </div>
    </div>
  )
}

export function StoryOptionCard({
  title,
  description,
  selected = false,
  icon,
}: {
  title: string
  description: string
  selected?: boolean
  icon?: ReactNode
}) {
  return (
    <div
      className={cn(
        'flex items-start gap-3 rounded-lg border p-3 transition-colors duration-500',
        selected
          ? 'border-[color-mix(in_srgb,var(--brand-cta)_35%,var(--border))] bg-[color-mix(in_srgb,var(--brand-cta)_6%,var(--background))]'
          : 'border-border bg-muted/5',
      )}
    >
      {icon ? (
        <span className="flex size-8 shrink-0 items-center justify-center rounded-md border border-border bg-muted/30">
          {icon}
        </span>
      ) : null}
      <div className="min-w-0">
        <p className="text-[12px] font-semibold text-foreground">{title}</p>
        <p className="mt-1 text-[11px] leading-5 text-muted-foreground">{description}</p>
      </div>
    </div>
  )
}

export function StoryProgressBar({ progress }: { progress: number }) {
  return (
    <div className="h-1.5 overflow-hidden rounded-full bg-muted/50">
      <div
        className="h-full rounded-full bg-[var(--brand-cta)]/80 transition-[width] duration-700 ease-out motion-reduce:transition-none"
        style={{ width: `${Math.min(100, Math.max(0, progress))}%` }}
      />
    </div>
  )
}

export function StoryLogLine({
  time,
  message,
  tone = 'default',
  delayMs = 0,
  visible = true,
}: {
  time: string
  message: string
  tone?: 'default' | 'success' | 'error'
  delayMs?: number
  visible?: boolean
}) {
  return (
    <p
      className={cn(
        'font-mono text-[10px] leading-5 transition-[opacity,transform] duration-500 sm:text-[11px]',
        visible ? 'translate-y-0 opacity-100' : 'translate-y-1 opacity-0',
        tone === 'success' && 'text-emerald-600 dark:text-emerald-400',
        tone === 'error' && 'text-destructive',
        tone === 'default' && 'text-muted-foreground',
      )}
      style={{ transitionDelay: `${delayMs}ms` }}
    >
      <span className="text-muted-foreground/60">{time}</span> {message}
    </p>
  )
}

export function StoryFeatureChip({ children }: { children: ReactNode }) {
  return (
    <span className="inline-flex items-center rounded-full border border-border bg-background/90 px-2.5 py-1 text-[10px] font-medium text-muted-foreground backdrop-blur-sm sm:text-[11px]">
      {children}
    </span>
  )
}
