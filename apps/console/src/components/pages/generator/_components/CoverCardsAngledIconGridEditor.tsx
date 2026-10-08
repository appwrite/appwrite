import { useEffect, useMemo, useRef, useState } from 'react'
import { CoverBuiltInIconPicker } from '@/components/pages/generator/_components/CoverBuiltInIconPicker'
import { CoverIconPreview } from '@/components/pages/generator/_components/CoverIconPreview'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  buildCoverCardsAngledHaloGrid,
  COVER_CARDS_ANGLED_FADED_ICON_OPACITY,
  COVER_CARDS_ANGLED_HALO_EMPTY_OPACITY,
  COVER_CARDS_ANGLED_ICON_VISIBILITY_OPTIONS,
  getCoverCardsAngledIconKeys,
  getCoverCardsAngledIconSlots,
  getCoverCardsAngledIconVisibilityKey,
  normalizeCoverCardsAngledData,
} from '@/lib/cover-generator/cards-angled/constants'
import type {
  CoverCardsAngledIconVisibility,
  CoverRenderData,
} from '@/lib/cover-generator/types'
import { isCoverUploadedImageValue } from '@/lib/cover-generator/editor-image-fields'
import { cn } from '@/lib/utils'

type CoverCardsAngledIconGridEditorProps = {
  data: Extract<CoverRenderData, { template: 'cards-angled' }>
  imageFields: Record<string, string | undefined>
  onChange: (next: CoverRenderData) => void
  onImageFieldChange: (key: string, value: string | undefined) => void
  onImageFileUpload: (key: string, file: File) => void
}

function setCardsAngledField(
  data: Extract<CoverRenderData, { template: 'cards-angled' }>,
  key: string,
  value: unknown,
): CoverRenderData {
  return { ...data, [key]: value } as CoverRenderData
}

export function CoverCardsAngledIconGridEditor({
  data,
  imageFields,
  onChange,
  onImageFieldChange,
  onImageFileUpload,
}: CoverCardsAngledIconGridEditorProps) {
  const normalized = useMemo(() => normalizeCoverCardsAngledData(data), [data])
  const iconSlots = useMemo(() => getCoverCardsAngledIconSlots(normalized), [normalized])
  const haloGrid = useMemo(
    () => buildCoverCardsAngledHaloGrid(normalized.columns, normalized.rows),
    [normalized.columns, normalized.rows],
  )
  const iconKeys = useMemo(() => getCoverCardsAngledIconKeys(), [])

  const [editingIndex, setEditingIndex] = useState<number | null>(null)
  const fileInputRef = useRef<HTMLInputElement>(null)

  const editingKey = editingIndex != null ? iconKeys[editingIndex] : null
  const editingVisibilityKey = editingKey
    ? getCoverCardsAngledIconVisibilityKey(editingKey)
    : null
  const editingIconValue =
    editingKey != null
      ? imageFields[editingKey] ?? normalized[editingKey] ?? ''
      : ''
  const editingVisibility: CoverCardsAngledIconVisibility =
    editingVisibilityKey != null
      ? (normalized[editingVisibilityKey] ?? 'visible')
      : 'visible'
  const isCustomImage =
    typeof editingIconValue === 'string' &&
    editingIconValue.length > 0 &&
    isCoverUploadedImageValue(editingIconValue)

  useEffect(() => {
    if (editingIndex != null && editingIndex >= iconSlots.length) {
      setEditingIndex(null)
    }
  }, [editingIndex, iconSlots.length])

  const handleDialogOpenChange = (open: boolean) => {
    if (!open) setEditingIndex(null)
  }

  const updateIcon = (key: string, value: string | undefined) => {
    onImageFieldChange(key, undefined)
    onChange(setCardsAngledField(data, key, value))
  }

  const updateVisibility = (value: CoverCardsAngledIconVisibility) => {
    if (!editingVisibilityKey) return
    onChange(setCardsAngledField(data, editingVisibilityKey, value))
  }

  return (
    <div className="space-y-2">
      <div>
        <Label className="text-[13px]">Icons</Label>
        <p className="mt-1 text-[12px] text-muted-foreground">
          Grid matches the cover layout. Click a card to edit its icon and visibility.
        </p>
      </div>

      <div
        className="mx-auto w-fit rounded-lg border border-border bg-muted/15 p-2"
        style={{
          display: 'grid',
          gap: 4,
          gridTemplateColumns: `repeat(${haloGrid.columns}, 36px)`,
        }}
      >
        {haloGrid.cells.map((cell, index) => {
          if (cell.kind === 'empty') {
            return (
              <div
                key={`halo-${index}`}
                aria-hidden
                className="rounded-md border border-border/70 bg-muted/25"
                style={{
                  width: 36,
                  height: 36,
                  opacity: COVER_CARDS_ANGLED_HALO_EMPTY_OPACITY,
                }}
              />
            )
          }

          const slot = iconSlots[cell.iconIndex]
          if (!slot) return null

          const isHidden = slot.visibility === 'hidden'
          const isFaded = slot.visibility === 'fade'

          return (
            <button
              key={`icon-${cell.iconIndex}`}
              type="button"
              title={`Icon ${cell.iconIndex + 1}${
                isHidden ? ' (hidden)' : isFaded ? ' (faded)' : ''
              }`}
              aria-label={`Edit icon ${cell.iconIndex + 1}`}
              aria-pressed={editingIndex === cell.iconIndex}
              onClick={() => setEditingIndex(cell.iconIndex)}
              className={cn(
                'flex items-center justify-center overflow-hidden rounded-md border bg-background p-1 transition-colors',
                editingIndex === cell.iconIndex
                  ? 'border-[var(--brand-cta)] ring-1 ring-[var(--brand-cta)]/30'
                  : 'border-border hover:border-foreground/25 hover:bg-accent/40',
              )}
              style={{
                width: 36,
                height: 36,
                opacity: isHidden
                  ? COVER_CARDS_ANGLED_HALO_EMPTY_OPACITY
                  : isFaded
                    ? COVER_CARDS_ANGLED_FADED_ICON_OPACITY
                    : 1,
              }}
            >
              {!isHidden && slot.src ? (
                <CoverIconPreview src={slot.src} themeId={data.theme} size={20} />
              ) : null}
            </button>
          )
        })}
      </div>

      <Dialog
        open={editingIndex != null}
        onOpenChange={handleDialogOpenChange}
      >
        <DialogContent className="flex max-h-[min(85dvh,640px)] flex-col gap-0 overflow-hidden p-0 sm:max-w-lg">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>
              {editingIndex != null ? `Icon ${editingIndex + 1}` : 'Icon'}
            </DialogTitle>
            <DialogDescription className="mt-2 text-[13px]">
              Choose an icon and how it appears on the cover.
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          {editingKey && editingVisibilityKey ? (
            <div className="space-y-4 overflow-y-auto px-6 py-4">
              <CoverBuiltInIconPicker
                id={editingKey}
                label="Icon"
                value={editingIconValue}
                isCustomImage={isCustomImage}
                onSelectBuiltIn={(path) => {
                  onImageFieldChange(editingKey, undefined)
                  onChange(setCardsAngledField(data, editingKey, path))
                }}
              />

              <div className="space-y-2">
                <Label className="text-[13px]">Visibility</Label>
                <div className="flex flex-wrap gap-1.5">
                  {COVER_CARDS_ANGLED_ICON_VISIBILITY_OPTIONS.map((option) => (
                    <button
                      key={option.value}
                      type="button"
                      onClick={() => updateVisibility(option.value)}
                      className={cn(
                        'rounded-md border px-2.5 py-1.5 text-[12px] font-medium transition-colors',
                        editingVisibility === option.value
                          ? 'border-foreground/30 bg-accent text-foreground'
                          : 'border-border text-muted-foreground hover:bg-accent/50 hover:text-foreground',
                      )}
                    >
                      {option.label}
                    </button>
                  ))}
                </div>
              </div>

              <div className="space-y-2">
                <p className="text-[12px] font-medium text-muted-foreground">
                  Custom image
                </p>
                {isCustomImage ? (
                  <div className="flex items-center gap-3 rounded-lg border border-border bg-muted/20 p-3">
                    <img
                      src={editingIconValue}
                      alt=""
                      className="h-14 w-14 shrink-0 rounded-md border border-border object-cover"
                    />
                    <div className="min-w-0 flex-1">
                      <p className="text-[13px] font-medium text-foreground">
                        Uploaded image
                      </p>
                      <p className="text-[12px] text-muted-foreground">
                        Saved locally in this browser for preview and export
                      </p>
                    </div>
                  </div>
                ) : null}
                <Input
                  value={isCustomImage ? '' : editingIconValue}
                  onChange={(event) => {
                    onImageFieldChange(editingKey, undefined)
                    onChange(
                      setCardsAngledField(
                        data,
                        editingKey,
                        event.target.value || undefined,
                      ),
                    )
                  }}
                  placeholder="Paste an image URL or path"
                  className="h-9 text-[13px]"
                  disabled={isCustomImage}
                />
                <div className="flex flex-wrap gap-2">
                  <input
                    ref={fileInputRef}
                    type="file"
                    accept="image/*,.svg"
                    className="hidden"
                    onChange={(event) => {
                      const file = event.target.files?.[0]
                      if (!file || !editingKey) return
                      onImageFileUpload(editingKey, file)
                      event.target.value = ''
                    }}
                  />
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-8 text-[12px]"
                    onClick={() => fileInputRef.current?.click()}
                  >
                    Upload image
                  </Button>
                  {editingIconValue ? (
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      className="h-8 text-[12px]"
                      onClick={() => updateIcon(editingKey, undefined)}
                    >
                      Clear
                    </Button>
                  ) : null}
                </div>
              </div>
            </div>
          ) : null}
        </DialogContent>
      </Dialog>
    </div>
  )
}
