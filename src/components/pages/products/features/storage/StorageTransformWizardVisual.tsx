import type { ReactNode } from 'react'
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
  Download,
  ExternalLink,
  Filter,
  Maximize2,
  Redo2,
  RotateCcw,
  Undo2,
  X,
  ZoomIn,
  ZoomOut,
} from 'lucide-react'
import { SchemaBlueprintMat } from '@/components/global/shared/SchemaBlueprintMat'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Slider } from '@/components/ui/slider'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
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

const TRANSFORM_HANDLE_DOT_CLASS =
  'h-5 w-5 shrink-0 rounded-full border-2 border-border bg-background'

const MOCK_RESIZE_HANDLES = [
  { id: 'nw', className: 'start-5 top-5' },
  { id: 'n', className: 'start-1/2 top-5 -translate-x-1/2' },
  { id: 'ne', className: 'end-5 top-5' },
  { id: 'e', className: 'end-5 top-1/2 -translate-y-1/2' },
  { id: 'se', className: 'end-5 bottom-5' },
  { id: 's', className: 'start-1/2 bottom-5 -translate-x-1/2' },
  { id: 'sw', className: 'start-5 bottom-5' },
  { id: 'w', className: 'start-5 top-1/2 -translate-y-1/2' },
] as const

function MockTransformCropFrame() {
  return (
    <div className="relative shrink-0">
      <div className="group/frame relative h-[7.5rem] w-[11rem] sm:h-[8.5rem] sm:w-[12.5rem]">
        <div className="relative h-full w-full overflow-hidden rounded-md bg-background">
          <div className="absolute inset-0 bg-gradient-to-br from-sky-300/70 via-indigo-300/50 to-violet-400/60" />
          <div className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/35 to-transparent px-2 py-1.5">
            <p className="text-[9px] font-medium text-white/90">640 × 360 · webp</p>
          </div>
        </div>

        <div className="pointer-events-none absolute start-0 top-0 h-full w-full">
          <div
            className="absolute z-[1] rounded-md border-[3px] border-dotted border-muted-foreground/35"
            style={{ inset: -28 }}
          />
          {MOCK_RESIZE_HANDLES.map((handle) => (
            <span
              key={handle.id}
              className={cn(
                'pointer-events-none absolute flex min-h-[44px] min-w-[44px] items-center justify-center',
                handle.className,
              )}
              aria-hidden
            >
              <span className={TRANSFORM_HANDLE_DOT_CLASS} />
            </span>
          ))}
        </div>
      </div>
    </div>
  )
}

function MockPreviewRing({ progress, children }: { progress: number; children: ReactNode }) {
  const size = 40
  const stroke = 2
  const r = (size - stroke) / 2
  const c = 2 * Math.PI * r
  const pct = Math.min(100, Math.max(0, progress))
  const offset = c * (1 - pct / 100)

  return (
    <div className="relative shrink-0" style={{ width: size, height: size }} aria-hidden>
      <svg width={size} height={size} className="-rotate-90">
        <circle
          cx={size / 2}
          cy={size / 2}
          r={r}
          fill="none"
          strokeWidth={stroke}
          className="stroke-muted/30"
        />
        <circle
          cx={size / 2}
          cy={size / 2}
          r={r}
          fill="none"
          strokeWidth={stroke}
          strokeDasharray={c}
          strokeDashoffset={offset}
          strokeLinecap="round"
          className="stroke-emerald-500"
        />
      </svg>
      <div className="absolute inset-0 flex items-center justify-center">{children}</div>
    </div>
  )
}

function MockAccordionSection({
  title,
  open = false,
}: {
  title: string
  open?: boolean
}) {
  const t = useT()
  return (
    <div className="border-b border-border">
      <div className="flex items-center justify-between py-3 text-[13px] font-medium text-foreground">
        <span>{t(title)}</span>
        <ChevronDown
          className={cn('size-4 text-muted-foreground transition-transform', open && 'rotate-180')}
          aria-hidden
        />
      </div>
    </div>
  )
}

export function StorageTransformWizardVisual() {
  const t = useT()
  return (
    <div className="overflow-hidden rounded-xl border border-border bg-card/45">
      <div className="flex items-center justify-between gap-3 border-b border-border px-4 py-3">
        <p className="truncate text-[14px] font-semibold text-foreground">hero-banner.webp</p>
        <X className="size-4 shrink-0 text-muted-foreground" aria-hidden />
      </div>

      <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b border-border bg-muted/30 px-4 py-2">
        <Tabs value="design" className="shadow-none">
          <TabsList className="grid h-9 w-full max-w-[220px] grid-cols-2 shadow-none">
            <TabsTrigger
              value="design"
              className="text-[12px] shadow-none data-[state=active]:shadow-none"
            >
              {t('Design')}
            </TabsTrigger>
            <TabsTrigger
              value="code"
              disabled
              className="text-[12px] shadow-none data-[state=active]:shadow-none"
            >
              {t('Code')}
            </TabsTrigger>
          </TabsList>
        </Tabs>
        <div className="flex flex-wrap items-center gap-2">
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8 gap-1.5 px-2.5 text-[11px]"
            disabled
          >
            <Undo2 className="size-3.5" aria-hidden />
            {t('Undo')}
          </Button>
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8 gap-1.5 px-2.5 text-[11px]"
            disabled
          >
            <Redo2 className="size-3.5" aria-hidden />
            {t('Redo')}
          </Button>
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8 gap-1.5 px-2.5 text-[11px]"
            disabled
          >
            <RotateCcw className="size-3.5" aria-hidden />
            {t('Reset all')}
          </Button>
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8 gap-1.5 px-2.5 text-[11px] group-hover/visual:border-foreground/15 group-hover/visual:bg-muted/40"
            disabled
          >
            <Filter className="size-3.5" aria-hidden />
            {t('Presets')}
            <span className="flex size-4 min-w-4 items-center justify-center rounded-full bg-muted text-[10px] font-medium tabular-nums text-muted-foreground">
              6
            </span>
          </Button>
        </div>
      </div>

      <div className="flex min-h-[18rem] flex-col md:flex-row">
        <div className="relative min-h-[12rem] flex-1 overflow-hidden bg-background">
          <SchemaBlueprintMat density="dense" />

          <div className="absolute inset-x-3 top-3 z-20 flex flex-wrap items-center justify-between gap-2">
            <div className="flex items-center gap-1.5">
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-8 w-8 border-border bg-card/95 p-0 backdrop-blur-sm"
                disabled
              >
                <ZoomIn className="size-4" aria-hidden />
              </Button>
              <div className="flex h-8 min-w-[52px] items-center justify-center rounded-md border border-border bg-card/95 px-2.5 backdrop-blur-sm">
                <span className="text-[11px] font-medium text-foreground">100%</span>
              </div>
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-8 w-8 border-border bg-card/95 p-0 backdrop-blur-sm"
                disabled
              >
                <ZoomOut className="size-4" aria-hidden />
              </Button>
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-8 w-8 border-border bg-card/95 p-0 backdrop-blur-sm"
                disabled
              >
                <Maximize2 className="size-4" aria-hidden />
              </Button>
            </div>
            <div className="flex rounded-md border border-border bg-card/95 p-0.5 backdrop-blur-sm">
              <span className="rounded px-2.5 py-1.5 text-[11px] font-medium bg-muted text-foreground">
                {t('Edit')}
              </span>
              <span className="rounded px-2.5 py-1.5 text-[11px] text-muted-foreground">{t('Compare')}</span>
            </div>
          </div>

          <div className="relative z-10 flex h-full min-h-[12rem] items-center justify-center p-7">
            <MockTransformCropFrame />
          </div>
        </div>

        <aside className="w-full shrink-0 border-t border-border bg-background md:w-[min(18rem,100%)] md:border-s md:border-t-0">
          <div className="px-4 py-3">
            <div className="border-b border-border">
              <div className="flex items-center justify-between py-3 text-[13px] font-medium text-foreground">
                <span>{t('Size & crop')}</span>
                <ChevronDown className="size-4 rotate-180 text-muted-foreground" aria-hidden />
              </div>
              <div className="space-y-4 pb-4">
                <div className="space-y-2">
                  <div className="flex justify-between gap-2">
                    <Label className="text-[11px] text-muted-foreground">{t('Width (px)')}</Label>
                    <span className="text-[11px] tabular-nums text-foreground">640</span>
                  </div>
                  <Slider
                    min={64}
                    max={2000}
                    step={1}
                    value={[640]}
                    disabled
                    className="py-1 [&_[role=slider]]:size-3.5"
                  />
                  <div className="flex h-9 items-center rounded-md border border-border bg-muted/20 px-3 text-[12px] tabular-nums text-foreground">
                    640
                  </div>
                </div>

                <div className="space-y-2">
                  <div className="flex items-center gap-2">
                    <Label className="text-[11px] text-muted-foreground">{t('Gravity')}</Label>
                    <span className="ms-auto font-mono text-[10px] text-muted-foreground">center</span>
                  </div>
                  <div className="w-full max-w-[9.5rem] rounded-lg border border-border bg-muted/25 p-px">
                    <div className="grid grid-cols-3 gap-px bg-border/70">
                      {GRAVITY_ICONS.map((Icon, index) => {
                        const selected = index === 4
                        return (
                          <div
                            key={index}
                            className={cn(
                              'flex aspect-square min-h-8 items-center justify-center bg-background/90',
                              selected &&
                                'bg-primary/[0.12] text-primary ring-1 ring-inset ring-primary/40',
                              !selected && 'text-muted-foreground',
                            )}
                          >
                            <Icon
                              className={cn('size-3.5', selected ? 'opacity-100' : 'opacity-70')}
                              strokeWidth={selected ? 2.25 : 1.85}
                              aria-hidden
                            />
                          </div>
                        )
                      })}
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <MockAccordionSection title="Quality & format" />
            <MockAccordionSection title="Style & effects" />
          </div>
        </aside>
      </div>

      <div className="flex flex-col gap-3 border-t border-border bg-muted/30 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex min-w-0 items-center gap-2.5">
          <MockPreviewRing progress={67}>
            <span className="inline-flex items-baseline tabular-nums text-[9px] font-medium text-foreground">
              67
              <span className="text-[7px] font-normal text-muted-foreground">%</span>
            </span>
          </MockPreviewRing>
          <p className="min-w-0 truncate text-[11px] leading-tight text-foreground">
            <span className="font-medium tabular-nums">842 KB</span>
            <ArrowRight className="mx-1 inline size-3 align-text-bottom text-muted-foreground" />
            <span className="font-medium tabular-nums">278 KB</span>
            <span className="text-muted-foreground"> · 33% {t('of original')}</span>
          </p>
        </div>
        <div className="flex shrink-0 flex-wrap items-center gap-2">
          <Button type="button" variant="outline" size="sm" className="h-8 text-[11px]" disabled>
            <Download className="me-1.5 size-3.5" aria-hidden />
            {t('Download')}
          </Button>
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8 text-[11px] group-hover/visual:border-foreground/15 group-hover/visual:bg-background"
            disabled
          >
            <Copy className="me-1.5 size-3.5" aria-hidden />
            {t('Copy URL')}
          </Button>
          <Button type="button" variant="outline" size="sm" className="h-8 text-[11px]" disabled>
            <ExternalLink className="me-1.5 size-3.5" aria-hidden />
            {t('Open')}
          </Button>
        </div>
      </div>
    </div>
  )
}
