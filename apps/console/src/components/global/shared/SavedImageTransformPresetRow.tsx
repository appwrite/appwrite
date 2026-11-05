import { useCallback, useEffect, useRef, useState } from 'react'
import { GripVertical, Loader2, Pencil, Trash2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import type { SavedImageTransformPreset } from '@/lib/user-prefs-keys'

export type SavedImageTransformPresetRowProps = {
  item: SavedImageTransformPreset
  canEdit: boolean
  dragOverKey: string | null
  rowDropKey: string
  onDragStart: (e: React.DragEvent) => void
  onDragOver: (e: React.DragEvent) => void
  onDragLeave: () => void
  onDrop: (e: React.DragEvent) => void
  onApply: () => void
  onDelete: () => void
  deleteBusy: boolean
  deleteDisabled: boolean
  onRenameCommit: (name: string) => void | Promise<unknown>
}

const NAME_MAX = 64

/**
 * One saved image-transform preset row: drag handle, name (inline rename), apply, delete.
 * Matches {@link SavedFilterPresetRow} layout and behavior for consistency with Filters.
 */
export function SavedImageTransformPresetRow({
  item,
  canEdit,
  dragOverKey,
  rowDropKey,
  onDragStart,
  onDragOver,
  onDragLeave,
  onDrop,
  onApply,
  onDelete,
  deleteBusy,
  deleteDisabled,
  onRenameCommit,
}: SavedImageTransformPresetRowProps) {
  const t = useT()
  const [editing, setEditing] = useState(false)
  const [draft, setDraft] = useState(item.name)
  const skipBlurCommitRef = useRef(false)
  const inputRef = useRef<HTMLInputElement>(null)

  useEffect(() => {
    if (!editing) setDraft(item.name)
  }, [item.name, editing])

  const endEditing = useCallback(() => {
    setEditing(false)
  }, [])

  const commitFromDraft = useCallback(() => {
    const next = draft.trim()
    if (!next) {
      setDraft(item.name)
      endEditing()
      return
    }
    if (next !== item.name) {
      void Promise.resolve(onRenameCommit(next)).catch(() => {
        /* toast handled by mutation if needed */
      })
    }
    endEditing()
  }, [draft, item.name, onRenameCommit, endEditing])

  const cancelEditing = useCallback(() => {
    skipBlurCommitRef.current = true
    setDraft(item.name)
    endEditing()
  }, [item.name, endEditing])

  const onInputBlur = useCallback(() => {
    if (skipBlurCommitRef.current) {
      skipBlurCommitRef.current = false
      return
    }
    commitFromDraft()
  }, [commitFromDraft])

  const startEditing = useCallback(() => {
    setDraft(item.name)
    setEditing(true)
    requestAnimationFrame(() => {
      const el = inputRef.current
      if (el) {
        el.focus()
        el.select()
      }
    })
  }, [item.name])

  const isDragOver = dragOverKey === rowDropKey

  return (
    <div
      draggable={canEdit && !editing}
      onDragStart={canEdit && !editing ? onDragStart : undefined}
      onDragOver={(e) => {
        if (!canEdit || editing) return
        onDragOver(e)
      }}
      onDragLeave={onDragLeave}
      onDrop={(e) => {
        e.preventDefault()
        if (canEdit) onDrop(e)
      }}
      className={cn(
        'group flex items-center gap-2 rounded-lg border border-border bg-muted/20 px-2 py-1.5 transition-colors',
        editing ? 'cursor-default' : canEdit && 'cursor-grab active:cursor-grabbing',
        isDragOver && canEdit && !editing && 'border-primary bg-primary/10',
      )}
      aria-label={
        editing
          ? undefined
          : canEdit
            ? `${item.name}, ${t('drag to reorder')}`
            : item.name
      }
    >
      {canEdit ? (
        <GripVertical
          className={cn(
            'h-3.5 w-3.5 shrink-0 text-muted-foreground transition-opacity',
            editing ? 'opacity-0' : 'opacity-0 group-hover:opacity-100',
          )}
          aria-hidden
        />
      ) : (
        <span className="h-3.5 w-3.5 shrink-0" aria-hidden />
      )}

      {editing ? (
        <Input
          ref={inputRef}
          value={draft}
          onChange={(e) => setDraft(e.target.value.slice(0, NAME_MAX))}
          onBlur={onInputBlur}
          onKeyDown={(e) => {
            if (e.key === 'Enter') {
              e.preventDefault()
              skipBlurCommitRef.current = true
              commitFromDraft()
            } else if (e.key === 'Escape') {
              e.preventDefault()
              cancelEditing()
            }
          }}
          className="h-7 min-w-0 flex-1 text-[13px]"
          maxLength={NAME_MAX}
          aria-label={t('Saved preset name')}
          onClick={(ev) => ev.stopPropagation()}
        />
      ) : (
        <span className="min-w-0 flex-1 truncate text-[13px] text-foreground">
          {item.name}
        </span>
      )}

      {canEdit && !editing ? (
        <Tooltip delayDuration={300}>
          <TooltipTrigger asChild>
            <button
              type="button"
              className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-muted-foreground hover:bg-muted hover:text-foreground"
              aria-label={t('Rename saved preset')}
              onClick={(e) => {
                e.stopPropagation()
                startEditing()
              }}
            >
              <Pencil className="h-3.5 w-3.5" />
            </button>
          </TooltipTrigger>
          <TooltipContent side="top" sideOffset={4} className="z-[10070]">
            {t('Rename')}
          </TooltipContent>
        </Tooltip>
      ) : editing ? (
        <span className="w-7 shrink-0" aria-hidden />
      ) : null}

      <Button
        type="button"
        variant="secondary"
        size="sm"
        className="h-7 shrink-0 text-[12px]"
        onClick={(e) => {
          e.stopPropagation()
          if (editing) {
            skipBlurCommitRef.current = true
            commitFromDraft()
          }
          onApply()
        }}
      >
        {t('Apply')}
      </Button>

      {canEdit ? (
        <button
          type="button"
          onClick={(e) => {
            e.stopPropagation()
            void onDelete()
          }}
          disabled={deleteDisabled}
          className="shrink-0 cursor-pointer rounded p-1 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground disabled:pointer-events-none disabled:opacity-50"
          aria-label={t('Delete saved preset')}
        >
          {deleteBusy ? (
            <Loader2 className="h-3 w-3 animate-spin" />
          ) : (
            <Trash2 className="h-3 w-3" />
          )}
        </button>
      ) : null}
    </div>
  )
}
