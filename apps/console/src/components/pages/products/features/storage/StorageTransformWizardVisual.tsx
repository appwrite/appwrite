import {
  ArrowDown,
  ArrowLeft,
  ArrowRight,
  ArrowUp,
  ChevronDown,
  Copy,
  CornerDownLeft,
  CornerDownRight,
  CornerUpLeft,
  CornerUpRight,
  Crosshair,
  Filter,
  Maximize2,
  Redo2,
  Undo2,
  ZoomIn,
  ZoomOut,
} from 'lucide-react'
import type { ReactNode } from 'react'
import { ArtPanel, riseStyle } from '@/components/pages/products/_components/ArtParts'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const GRAVITY_ICONS = [
  CornerUpLeft,
  ArrowUp,
  CornerUpRight,
  ArrowLeft,
  Crosshair,
  ArrowRight,
  CornerDownLeft,
  ArrowDown,
  CornerDownRight,
] as const

const PRESETS = [
  { name: 'thumbnail-256', detail: '256 × 256 · webp', active: false },
  { name: 'og-card', detail: '1200 × 630 · jpg', active: false },
  { name: 'hero-banner', detail: '640 × 360 · webp', active: true },
  { name: 'avatar-square', detail: '128 × 128 · avif', active: false },
] as const

const RESIZE_HANDLES = [
  '-start-[5px] -top-[5px]',
  'left-1/2 -top-[5px] -translate-x-1/2',
  '-end-[5px] -top-[5px]',
  '-end-[5px] top-1/2 -translate-y-1/2',
  '-end-[5px] -bottom-[5px]',
  'left-1/2 -bottom-[5px] -translate-x-1/2',
  '-start-[5px] -bottom-[5px]',
  '-start-[5px] top-1/2 -translate-y-1/2',
] as const

const FLOATING_BAR_CLASS =
  'flex items-center gap-1 rounded-lg border border-border bg-background/95 p-1 shadow-[0_16px_40px_-20px_rgb(0_0_0/0.35)] dark:border-white/10 dark:bg-card'

function ToolButton({ children, active = false }: { children: ReactNode; active?: boolean }) {
  return (
    <span
      className={cn(
        'inline-flex h-7 min-w-7 items-center justify-center gap-1.5 rounded-md px-2 text-[11px] font-medium',
        active ? 'bg-muted text-foreground' : 'text-muted-foreground',
      )}
    >
      {children}
    </span>
  )
}

function ProductShot() {
  return (
    <div className="relative size-full overflow-hidden rounded-md bg-[linear-gradient(160deg,rgb(var(--tone2-rgb)/0.55),rgb(var(--tone-rgb)/0.35))]">
      <div className="absolute end-[14%] top-[12%] aspect-square w-[34%] rounded-full bg-white/35 blur-xl" />
      <div className="absolute bottom-[18%] start-[22%] aspect-square w-[24%] rotate-12 rounded-[22%] bg-[rgb(var(--tone2-rgb))] shadow-[0_18px_30px_-12px_rgb(0_0_0/0.45)]" />
      <div className="absolute bottom-[16%] start-[46%] aspect-square w-[20%] rounded-full bg-[rgb(var(--tone-rgb))] shadow-[0_18px_30px_-12px_rgb(0_0_0/0.45)]" />
      <div className="absolute inset-x-0 bottom-0 h-[18%] bg-black/15" />
    </div>
  )
}

function Canvas() {
  const t = useT()
  return (
    <div className="relative flex items-center justify-center px-2 pb-24 pt-16 sm:px-14">
      <div
        className="product-hero-rise absolute left-1/2 top-0 z-[2] -translate-x-1/2"
        style={riseStyle(200)}
      >
        <div className={FLOATING_BAR_CLASS}>
          <ToolButton active>{t('Design')}</ToolButton>
          <ToolButton>{t('Code')}</ToolButton>
          <span className="mx-1 h-4 w-px bg-border" aria-hidden />
          <ToolButton>
            <Undo2 className="size-3.5" aria-hidden />
          </ToolButton>
          <ToolButton>
            <Redo2 className="size-3.5" aria-hidden />
          </ToolButton>
          <span className="mx-1 hidden h-4 w-px bg-border sm:block" aria-hidden />
          <span className="hidden sm:contents">
            <ToolButton active>{t('Edit')}</ToolButton>
            <ToolButton>{t('Compare')}</ToolButton>
          </span>
        </div>
      </div>

      <div
        className="product-hero-rise absolute start-0 top-1/2 z-[2] hidden -translate-y-1/2 sm:block"
        style={riseStyle(350)}
      >
        <div className={cn(FLOATING_BAR_CLASS, 'flex-col')}>
          <ToolButton>
            <ZoomIn className="size-3.5" aria-hidden />
          </ToolButton>
          <span dir="ltr" className="py-1 font-mono text-[10px] text-foreground">
            100%
          </span>
          <ToolButton>
            <ZoomOut className="size-3.5" aria-hidden />
          </ToolButton>
          <ToolButton>
            <Maximize2 className="size-3.5" aria-hidden />
          </ToolButton>
        </div>
      </div>

      <div className="product-hero-rise relative w-[min(420px,84%)]" style={riseStyle(60)}>
        <div className="product-tone-shadow aspect-[16/9]">
          <ProductShot />
        </div>
        <div className="pointer-events-none absolute -inset-2.5" aria-hidden>
          <div className="absolute inset-0 rounded-md border border-[var(--tone-ink)]" />
          {RESIZE_HANDLES.map((position) => (
            <span
              key={position}
              className={cn(
                'absolute size-2.5 rounded-[3px] border border-[var(--tone-ink)] bg-background',
                position,
              )}
            />
          ))}
        </div>
        <span
          dir="ltr"
          className="absolute -bottom-8 left-1/2 -translate-x-1/2 rounded bg-[var(--tone-ink)] px-1.5 py-0.5 font-mono text-[10px] font-medium text-background"
        >
          640 × 360
        </span>
      </div>

      <div
        className="product-hero-rise absolute bottom-0 left-1/2 z-[2] -translate-x-1/2"
        style={riseStyle(900)}
      >
        <div className={cn(FLOATING_BAR_CLASS, 'gap-2.5 whitespace-nowrap py-1.5 pe-1.5 ps-3')}>
          <span dir="ltr" className="font-mono text-[11px] text-muted-foreground">
            842 KB
          </span>
          <ArrowRight className="size-3 text-muted-foreground rtl:rotate-180" aria-hidden />
          <span dir="ltr" className="font-mono text-[11px] font-medium text-foreground">
            278 KB
          </span>
          <span className="rounded bg-emerald-500/10 px-1.5 py-0.5 text-[10px] font-medium text-emerald-600 dark:text-emerald-400">
            33% {t('of original')}
          </span>
          <span className="hidden h-7 items-center gap-1.5 rounded-md bg-foreground px-2.5 text-[11px] font-medium text-background sm:inline-flex">
            <Copy className="size-3" aria-hidden />
            {t('Copy URL')}
          </span>
        </div>
      </div>
    </div>
  )
}

function Inspector() {
  const t = useT()
  return (
    <ArtPanel innerClassName="p-0" delayMs={450} float floatDelayMs={600}>
      <div className="px-3.5 py-3">
        <div className="flex items-center justify-between text-[12px] font-medium text-foreground">
          <span>{t('Size & crop')}</span>
          <ChevronDown className="size-3.5 rotate-180 text-muted-foreground" aria-hidden />
        </div>

        <div className="mt-3 flex items-center justify-between gap-2">
          <span className="text-[11px] text-muted-foreground">{t('Width (px)')}</span>
          <span dir="ltr" className="font-mono text-[11px] text-foreground">
            640
          </span>
        </div>
        <div className="relative mt-2 h-1 rounded-full bg-muted" aria-hidden>
          <div className="absolute inset-y-0 start-0 w-[32%] rounded-full bg-[var(--tone-ink)]" />
          <span className="absolute start-[32%] top-1/2 size-3 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-[var(--tone-ink)] bg-background rtl:translate-x-1/2" />
        </div>

        <div className="mt-4 flex items-center justify-between gap-2">
          <span className="text-[11px] text-muted-foreground">{t('Gravity')}</span>
          <span dir="ltr" className="font-mono text-[10px] text-muted-foreground">
            center
          </span>
        </div>
        <div className="mt-2 grid w-[7.5rem] grid-cols-3 gap-1" aria-hidden>
          {GRAVITY_ICONS.map((Icon, index) => {
            const selected = index === 4
            return (
              <span
                key={index}
                className={cn(
                  'flex aspect-square items-center justify-center rounded-md border',
                  selected
                    ? 'border-[rgb(var(--tone-rgb)/0.5)] bg-[rgb(var(--tone-rgb)/0.12)] text-[var(--tone-ink)]'
                    : 'border-border bg-muted/30 text-muted-foreground',
                )}
              >
                <Icon className="size-3" strokeWidth={selected ? 2.25 : 1.85} />
              </span>
            )
          })}
        </div>
      </div>
      {(['Quality & format', 'Style & effects'] as const).map((section) => (
        <div
          key={section}
          className="flex items-center justify-between border-t border-border px-3.5 py-2.5 text-[12px] font-medium text-foreground"
        >
          <span>{t(section)}</span>
          <ChevronDown className="size-3.5 text-muted-foreground" aria-hidden />
        </div>
      ))}
    </ArtPanel>
  )
}

function Presets() {
  const t = useT()
  return (
    <ArtPanel innerClassName="p-2" delayMs={600} float floatDelayMs={1200}>
      <div className="flex items-center gap-1.5 px-1.5 pb-2 pt-1">
        <Filter className="size-3.5 text-muted-foreground" aria-hidden />
        <span className="text-[12px] font-medium text-foreground">{t('Presets')}</span>
        <span className="ms-auto rounded-full bg-muted px-1.5 text-[10px] font-medium tabular-nums text-muted-foreground">
          6
        </span>
      </div>
      <div className="space-y-1">
        {PRESETS.map((preset, index) => (
          <div
            key={preset.name}
            className={cn(
              'product-hero-rise rounded-lg border px-2.5 py-1.5',
              preset.active
                ? 'border-[rgb(var(--tone-rgb)/0.45)] bg-[rgb(var(--tone-rgb)/0.08)]'
                : 'border-transparent',
            )}
            style={riseStyle(750 + index * 100)}
          >
            <p dir="ltr" className="text-start font-mono text-[11px] font-medium text-foreground">
              {preset.name}
            </p>
            <p dir="ltr" className="text-start font-mono text-[10px] text-muted-foreground">
              {preset.detail}
            </p>
          </div>
        ))}
      </div>
    </ArtPanel>
  )
}

export function StorageTransformWizardVisual() {
  return (
    <div className="mx-auto grid w-full max-w-6xl items-center gap-6 lg:grid-cols-[200px_minmax(0,1fr)_240px] lg:gap-8">
      <div className="order-last mx-auto w-full max-w-[280px] lg:order-none lg:max-w-none">
        <Presets />
      </div>
      <Canvas />
      <div className="mx-auto w-full max-w-[280px] lg:max-w-none">
        <Inspector />
      </div>
    </div>
  )
}
