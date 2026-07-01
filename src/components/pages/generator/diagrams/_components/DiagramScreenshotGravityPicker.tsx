import {
  ArrowDown,
  ArrowLeft,
  ArrowRight,
  ArrowUp,
  CornerDownLeft,
  CornerDownRight,
  CornerUpLeft,
  CornerUpRight,
  Crosshair,
  type LucideIcon,
} from 'lucide-react'
import { Label } from '@/components/ui/label'
import {
  DIAGRAM_SCREENSHOT_GRAVITY_GRID_ROWS,
  formatDiagramScreenshotGravityLabel,
  getDiagramScreenshotFocusForGravity,
  getDiagramScreenshotFocus,
  getDiagramScreenshotGravityFromFocus,
  type DiagramScreenshotGravity,
} from '@/lib/diagram-generator/screenshot-focus'
import type { DiagramNode } from '@/lib/diagram-generator/types'
import { cn } from '@/lib/utils'

const GRAVITY_PICKER_ICON: Record<DiagramScreenshotGravity, LucideIcon> = {
  'top-left': CornerUpLeft,
  top: ArrowUp,
  'top-right': CornerUpRight,
  left: ArrowLeft,
  center: Crosshair,
  right: ArrowRight,
  'bottom-left': CornerDownLeft,
  bottom: ArrowDown,
  'bottom-right': CornerDownRight,
}

type DiagramScreenshotGravityPickerProps = {
  node: Pick<DiagramNode, 'focusX' | 'focusY'>
  onChange: (patch: Pick<DiagramNode, 'focusX' | 'focusY'>) => void
}

export function DiagramScreenshotGravityPicker({
  node,
  onChange,
}: DiagramScreenshotGravityPickerProps) {
  const focus = getDiagramScreenshotFocus(node)
  const selectedGravity = getDiagramScreenshotGravityFromFocus(
    focus.focusX,
    focus.focusY,
  )

  return (
    <div className="space-y-2">
      <div className="flex items-center gap-2">
        <Label className="text-[12px]">Gravity</Label>
        <span
          className="ms-auto min-w-0 shrink-0 whitespace-nowrap text-end text-[11px] capitalize text-muted-foreground"
          aria-live="polite"
        >
          {formatDiagramScreenshotGravityLabel(selectedGravity)}
        </span>
      </div>
      <div
        className="w-full max-w-[11.5rem] rounded-lg border border-border bg-muted/25 p-px shadow-inner dark:bg-muted/15"
        role="group"
        aria-label="Screenshot gravity"
      >
        <div className="grid grid-cols-3 gap-px bg-border/70">
          {DIAGRAM_SCREENSHOT_GRAVITY_GRID_ROWS.flatMap((row, rowIndex) =>
            row.map((gravity, columnIndex) => {
              const Icon = GRAVITY_PICKER_ICON[gravity]
              const selected = selectedGravity === gravity
              const cellIndex = rowIndex * 3 + columnIndex
              const cornerClass =
                cellIndex === 0
                  ? 'rounded-ss-[calc(var(--radius-lg)_-_1px)]'
                  : cellIndex === 2
                    ? 'rounded-se-[calc(var(--radius-lg)_-_1px)]'
                    : cellIndex === 6
                      ? 'rounded-es-[calc(var(--radius-lg)_-_1px)]'
                      : cellIndex === 8
                        ? 'rounded-ee-[calc(var(--radius-lg)_-_1px)]'
                        : ''

              return (
                <button
                  key={gravity}
                  type="button"
                  className={cn(
                    'relative flex aspect-square min-h-10 w-full min-w-0 items-center justify-center',
                    'bg-background/90 transition-colors duration-150',
                    'hover:bg-muted/70 hover:text-foreground',
                    'focus-visible:z-[2] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset',
                    selected
                      ? 'bg-accent text-foreground shadow-sm'
                      : 'text-muted-foreground',
                    cornerClass,
                  )}
                  aria-label={formatDiagramScreenshotGravityLabel(gravity)}
                  aria-pressed={selected}
                  onClick={() => onChange(getDiagramScreenshotFocusForGravity(gravity))}
                >
                  <Icon className="size-3.5" strokeWidth={1.75} aria-hidden />
                </button>
              )
            }),
          )}
        </div>
      </div>
      <p className="text-[11px] text-muted-foreground">
        Controls how the image is cropped inside the browser frame.
      </p>
    </div>
  )
}
