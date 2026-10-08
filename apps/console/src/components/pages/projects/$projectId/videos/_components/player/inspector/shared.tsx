import { createContext, useContext, type ReactNode } from 'react'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'
import { formatBitrate, formatResolution } from '@/lib/utils/video-format'
import type { StreamVariantInfo } from '../useStreamPlayer'

/** Overlays (tooltips, menus) must portal into the pop-up document when detached. */
export const InspectorPortalContext = createContext<HTMLElement | undefined>(
  undefined,
)

export function useInspectorWindow(): Window {
  const container = useContext(InspectorPortalContext)
  return container?.ownerDocument.defaultView ?? window
}

export const HEAD_CLASS =
  'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider'
export const CELL_CLASS = 'px-4 py-3 font-mono text-[12px]'

export function levelColor(level: number): string {
  if (level < 0) return 'var(--muted-foreground)'
  return `var(--chart-${(level % 5) + 1})`
}

export function levelName(level: StreamVariantInfo | undefined): string {
  if (!level) return '-'
  return level.height ? `${level.height}p` : formatBitrate(level.bitrate)
}

export function levelDescription(level: StreamVariantInfo | undefined): string {
  if (!level) return '-'
  return `${formatResolution(level.width, level.height)} · ${formatBitrate(level.bitrate)}`
}

export function formatMs(ms: number | null | undefined): string {
  if (ms == null || !Number.isFinite(ms)) return '-'
  if (ms < 1000) return `${Math.round(ms)} ms`
  if (ms < 60_000) return `${(ms / 1000).toFixed(ms < 10_000 ? 2 : 1)} s`
  const m = Math.floor(ms / 60_000)
  const s = Math.round((ms % 60_000) / 1000)
  return `${m}m ${String(s).padStart(2, '0')}s`
}

export function formatPercent(ratio: number, digits = 1): string {
  if (!Number.isFinite(ratio)) return '-'
  return `${(ratio * 100).toFixed(digits)}%`
}

export function percentile(values: number[], p: number): number | null {
  if (values.length === 0) return null
  const sorted = [...values].sort((a, b) => a - b)
  const index = Math.min(sorted.length - 1, Math.ceil(p * sorted.length) - 1)
  return sorted[Math.max(0, index)]
}

export type Tone = 'good' | 'fair' | 'poor' | 'neutral'

export const TONE_TEXT: Record<Tone, string> = {
  good: 'text-emerald-600 dark:text-emerald-400',
  fair: 'text-amber-600 dark:text-amber-400',
  poor: 'text-red-600 dark:text-red-400',
  neutral: 'text-foreground',
}

export const TONE_DOT: Record<Tone, string> = {
  good: 'bg-emerald-500',
  fair: 'bg-amber-500',
  poor: 'bg-red-500',
  neutral: 'bg-muted-foreground',
}

export function scoreTone(score: number | null | undefined): Tone {
  if (score == null) return 'neutral'
  if (score >= 85) return 'good'
  if (score >= 60) return 'fair'
  return 'poor'
}

export function SectionCard({
  title,
  description,
  actions,
  children,
  className,
  bodyClassName,
}: {
  title: string
  description?: string
  actions?: ReactNode
  children: ReactNode
  className?: string
  bodyClassName?: string
}) {
  return (
    <section
      className={cn(
        'overflow-hidden rounded-xl border border-border bg-card/50',
        className,
      )}
    >
      <header className="flex flex-wrap items-center gap-3 px-4 py-3">
        <div className="min-w-0 flex-1">
          <h3 className="text-[13px] font-semibold text-foreground">{title}</h3>
          {description ? (
            <p className="mt-0.5 text-[12px] text-muted-foreground">
              {description}
            </p>
          ) : null}
        </div>
        {actions}
      </header>
      <div className={cn('border-t border-border p-4', bodyClassName)}>
        {children}
      </div>
    </section>
  )
}

export function Kpi({
  label,
  value,
  hint,
  tone = 'neutral',
}: {
  label: string
  value: ReactNode
  hint?: ReactNode
  tone?: Tone
}) {
  return (
    <div className="min-w-0 bg-card px-4 py-3">
      <dt className="flex items-center gap-1.5 text-[11px] text-muted-foreground">
        {tone !== 'neutral' ? (
          <span
            className={cn('size-1.5 shrink-0 rounded-full', TONE_DOT[tone])}
          />
        ) : null}
        <span className="truncate">{label}</span>
      </dt>
      <dd
        className={cn(
          'mt-1 truncate font-mono text-[15px] font-semibold tabular-nums',
          TONE_TEXT[tone],
        )}
      >
        {value === '' || value == null ? '-' : value}
      </dd>
      {hint ? (
        <p className="mt-0.5 truncate text-[11px] text-muted-foreground">
          {hint}
        </p>
      ) : null}
    </div>
  )
}

export function KpiGrid({
  children,
  className,
}: {
  children: ReactNode
  className?: string
}) {
  return (
    <dl
      className={cn(
        'grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-border bg-border sm:grid-cols-3 xl:grid-cols-4',
        className,
      )}
    >
      {children}
    </dl>
  )
}

export function Empty({ children }: { children: ReactNode }) {
  return (
    <p className="rounded-lg border border-dashed border-border px-4 py-8 text-center text-[12px] text-muted-foreground">
      {children}
    </p>
  )
}

export function IconAction({
  label,
  onClick,
  children,
  disabled,
}: {
  label: string
  onClick: () => void
  children: ReactNode
  disabled?: boolean
}) {
  const container = useContext(InspectorPortalContext)
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <Button
          type="button"
          variant="ghost"
          size="icon"
          className="h-7 w-7 shrink-0 text-muted-foreground hover:text-foreground"
          onClick={onClick}
          aria-label={label}
          disabled={disabled}
        >
          {children}
        </Button>
      </TooltipTrigger>
      <TooltipContent className="text-[12px]" container={container}>
        {label}
      </TooltipContent>
    </Tooltip>
  )
}

export function FilterChips<T extends string>({
  value,
  options,
  onChange,
}: {
  value: T
  options: Array<{ id: T; label: string; count?: number }>
  onChange: (value: T) => void
}) {
  return (
    <div className="inline-flex items-center gap-0.5 rounded-lg border border-border bg-muted/40 p-0.5">
      {options.map((option) => (
        <button
          key={option.id}
          type="button"
          onClick={() => onChange(option.id)}
          aria-pressed={value === option.id}
          className={cn(
            'inline-flex h-6 items-center gap-1.5 rounded-md px-2 text-[11px] font-medium transition-colors',
            value === option.id
              ? 'bg-background text-foreground shadow-sm'
              : 'text-muted-foreground hover:text-foreground',
          )}
        >
          {option.label}
          {option.count != null ? (
            <span className="font-mono tabular-nums text-muted-foreground">
              {option.count}
            </span>
          ) : null}
        </button>
      ))}
    </div>
  )
}
