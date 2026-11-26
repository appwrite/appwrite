import { useState } from 'react'
import type { Models } from '@appwrite.io/console'
import { MessageSquareText, Pencil, Plus, Trash2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { openDialogAfterOverlayCloses } from '@/lib/utils/overlay-lock'
import { useT } from '@/lib/i18n/translate'

/**
 * Note badges sit in a lane along the top of the plot: inside it, so hovering
 * one still shows the chart tooltip and a click still lands on the hovered
 * bucket. The chart's y-axis leaves headroom above the data for the lane.
 */
const BADGE_SIZE = 20
const BADGE_TOP = 3
/** Lane depth: the chart tooltip starts below it so badges stay visible. */
export const ANNOTATION_LANE_HEIGHT = BADGE_TOP + BADGE_SIZE + 6
/** Wide enough for a two-digit count; the badge centers inside it. */
const BADGE_BOX_WIDTH = 64
/** Box the "Add note" chip centers in, wider than the chip in any language. */
const CHIP_BOX_WIDTH = 160
/** About half the English chip, so it stays inside the plot at its edges. */
const CHIP_HALF_WIDTH = 38

/** Line for buckets with notes: it hangs from the badge to the x-axis. */
export const ANNOTATION_LINE_PROPS = {
  stroke: 'var(--foreground)',
  strokeOpacity: 0.3,
  strokeDasharray: '3 3',
} as const

/**
 * A chart bucket's notes: a note badge on top of its line that opens them.
 * Rendered as a Recharts label, so it sits in the chart's SVG.
 */
export function AnnotationMarker({
  x,
  y,
  annotations,
  authors,
  canWrite,
  onEdit,
  onDelete,
  onAdd,
}: {
  /** Line position from the label's viewBox. */
  x: number
  y: number
  annotations: Models.AnalyticsAnnotation[]
  /** Names by user ID; undefined until loaded. */
  authors: ReadonlyMap<string, string> | undefined
  canWrite: boolean
  onEdit: (annotation: Models.AnalyticsAnnotation) => void
  onDelete: (annotation: Models.AnalyticsAnnotation) => void
  /** Adds another note on this bucket's day. */
  onAdd: () => void
}) {
  const t = useT()
  const [open, setOpen] = useState(false)

  const act = (action: () => void) => {
    setOpen(false)
    openDialogAfterOverlayCloses(action)
  }

  return (
    <foreignObject
      x={x - BADGE_BOX_WIDTH / 2}
      y={y + BADGE_TOP}
      width={BADGE_BOX_WIDTH}
      height={BADGE_SIZE}
      className="overflow-visible"
    >
      <div className="flex h-full justify-center">
        <Popover open={open} onOpenChange={setOpen}>
          <PopoverTrigger asChild>
            <button
              type="button"
              aria-label={t('Show notes')}
              // The chart's drag-to-select and click-to-add listen on its wrapper.
              onMouseDown={(event) => event.stopPropagation()}
              onClick={(event) => event.stopPropagation()}
              className="flex h-full min-w-5 cursor-pointer items-center justify-center gap-1 rounded-full border border-foreground/20 bg-background px-1.5 text-[11px] font-medium leading-none tabular-nums text-foreground shadow-xs transition-colors hover:border-foreground/40 hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring data-[state=open]:border-foreground/40 data-[state=open]:bg-accent"
            >
              <MessageSquareText className="size-3 shrink-0" aria-hidden />
              {annotations.length > 1 ? annotations.length : null}
            </button>
          </PopoverTrigger>
          {/* Portaled, but React events still bubble to the chart's drag and click-to-add. */}
          <PopoverContent
            side="top"
            className="w-80 p-0"
            onMouseDown={(event) => event.stopPropagation()}
            onClick={(event) => event.stopPropagation()}
          >
            <ul className="max-h-72 divide-y divide-border overflow-y-auto">
              {annotations.map((annotation) => (
                <li key={annotation.$id} className="space-y-1.5 px-3 py-2.5">
                  <p className="whitespace-pre-wrap break-words text-[13px] text-foreground">
                    {annotation.content}
                  </p>
                  <div className="flex items-center justify-between gap-2">
                    <p className="flex min-w-0 items-center gap-1 text-[12px] text-muted-foreground">
                      <AnnotationAuthor
                        userId={annotation.userId}
                        authors={authors}
                      />
                      <DateTooltip date={annotation.date} showFormattedDate />
                    </p>
                    {canWrite ? (
                      <div className="flex shrink-0 items-center">
                        <Button
                          variant="ghost"
                          size="icon"
                          className="size-7"
                          aria-label={t('Edit note')}
                          onClick={() => act(() => onEdit(annotation))}
                        >
                          <Pencil className="size-3.5" />
                        </Button>
                        <Button
                          variant="ghost"
                          size="icon"
                          className="size-7"
                          aria-label={t('Delete note')}
                          onClick={() => act(() => onDelete(annotation))}
                        >
                          <Trash2 className="size-3.5" />
                        </Button>
                      </div>
                    ) : null}
                  </div>
                </li>
              ))}
            </ul>
            {canWrite ? (
              <div className="border-t border-border p-1">
                <Button
                  variant="ghost"
                  size="sm"
                  className="h-8 w-full justify-start gap-1.5 px-2 text-[13px] font-normal text-muted-foreground hover:text-foreground"
                  onClick={() => act(onAdd)}
                >
                  <Plus className="size-3.5" />
                  {t('Add note')}
                </Button>
              </div>
            ) : null}
          </PopoverContent>
        </Popover>
      </div>
    </foreignObject>
  )
}

/**
 * The chart's hover line. Over a bucket without notes it hangs from an
 * "Add note" chip where the note's badge will appear, since a click there adds
 * one. Recharts clones it with the line's points, the plot offset and the
 * hovered index.
 */
export function AnnotationCursor({
  points,
  payloadIndex,
  stroke,
  left,
  width,
  notedIndexes,
  canAdd,
}: {
  points?: ReadonlyArray<{ x: number; y: number }>
  payloadIndex?: number
  stroke?: string
  left?: number
  width?: number
  /** Buckets that already show a note badge. */
  notedIndexes: { has: (index: number) => boolean }
  canAdd: boolean
}) {
  const t = useT()
  const [start, end] = points ?? []
  if (!start || !end) return null
  const showAdd = canAdd && !notedIndexes.has(Number(payloadIndex))
  // Near the plot's edges the chip slides inward instead of clipping.
  const chipX =
    left === undefined || width === undefined
      ? start.x
      : Math.min(
          Math.max(start.x, left + CHIP_HALF_WIDTH),
          left + width - CHIP_HALF_WIDTH,
        )
  return (
    <g pointerEvents="none">
      <line
        x1={start.x}
        y1={showAdd ? start.y + BADGE_TOP + BADGE_SIZE : start.y}
        x2={end.x}
        y2={end.y}
        stroke={stroke}
      />
      {showAdd ? (
        <foreignObject
          x={chipX - CHIP_BOX_WIDTH / 2}
          y={start.y + BADGE_TOP}
          width={CHIP_BOX_WIDTH}
          height={BADGE_SIZE}
          className="overflow-visible"
        >
          <div className="flex h-full justify-center">
            <span
              data-annotation-add
              className="flex h-full items-center gap-1 whitespace-nowrap rounded-full bg-foreground ps-1.5 pe-2 text-[11px] font-medium leading-none text-background shadow-sm"
            >
              <Plus className="size-3" strokeWidth={2.5} aria-hidden />
              {t('Add note')}
            </span>
          </div>
        </foreignObject>
      ) : null}
    </g>
  )
}

/**
 * "You", the member's name, or "Former member" once the lookup finds no
 * membership; nothing for API keys or while names load.
 */
function AnnotationAuthor({
  userId,
  authors,
}: {
  userId: string
  authors: ReadonlyMap<string, string> | undefined
}) {
  const t = useT()
  const account = useAuth().account as
    | Models.User<Models.Preferences>
    | undefined
  if (!userId) return null
  const name =
    userId === account?.$id
      ? t('You')
      : authors && (authors.get(userId) || t('Former member'))
  if (!name) return null
  return (
    <>
      <span className="truncate">{name}</span>
      <span aria-hidden>·</span>
    </>
  )
}
