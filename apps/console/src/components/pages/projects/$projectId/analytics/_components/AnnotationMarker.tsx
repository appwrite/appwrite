import { useState } from 'react'
import type { Models } from '@appwrite.io/console'
import { Pencil, Trash2 } from 'lucide-react'
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

const MARKER_SIZE = 14

/**
 * A chart bucket's notes: a dot on top of the reference line that opens them.
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
}) {
  const t = useT()
  const [open, setOpen] = useState(false)

  const act = (action: () => void) => {
    setOpen(false)
    openDialogAfterOverlayCloses(action)
  }

  return (
    <foreignObject
      x={x - MARKER_SIZE / 2}
      y={y - MARKER_SIZE / 2}
      width={MARKER_SIZE}
      height={MARKER_SIZE}
      className="overflow-visible"
    >
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <button
            type="button"
            aria-label={t('Show notes')}
            // The chart's drag-to-select and click-to-add listen on its wrapper.
            onMouseDown={(event) => event.stopPropagation()}
            onClick={(event) => event.stopPropagation()}
            className="flex size-full items-center justify-center rounded-full border-2 border-background bg-foreground/70 text-[8px] font-semibold leading-none text-background transition-colors hover:bg-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
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
        </PopoverContent>
      </Popover>
    </foreignObject>
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
