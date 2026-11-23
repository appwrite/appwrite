import { useRef } from 'react'
import { CoverBuiltInIconPicker } from '@/components/pages/generator/_components/CoverBuiltInIconPicker'
import { CoverHeroBrowserFrame } from '@/components/global/shared/CoverHeroBrowserFrame'
import { DiagramScreenshotGravityPicker } from '@/components/pages/generator/diagrams/_components/DiagramScreenshotGravityPicker'
import { DiagramTableEditor } from '@/components/pages/generator/diagrams/_components/DiagramTableEditor'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { DIAGRAM_NODE_KIND_LABELS } from '@/lib/diagram-generator/constants'
import { createDefaultDiagramTable } from '@/lib/diagram-generator/diagram-table'
import { getDiagramNodeIconSrc } from '@/lib/diagram-generator/node-normalize'
import { getDiagramScreenshotFocus } from '@/lib/diagram-generator/screenshot-focus'
import type { DiagramNode } from '@/lib/diagram-generator/types'
import type { CoverEditorThemeId } from '@/lib/cover-generator/themes'

const SCREENSHOT_ACCEPT = 'image/png,image/jpeg,image/webp,image/avif'
const SCREENSHOT_MAX_BYTES = 4 * 1024 * 1024
const SCREENSHOT_PREVIEW_FRAME_HEIGHT = 144

type DiagramNodeKindPropertiesProps = {
  node: DiagramNode
  themeId: CoverEditorThemeId
  onNodeChange: (nodeId: string, patch: Partial<DiagramNode>) => void
}

function readScreenshotFile(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    if (!file.type.startsWith('image/')) {
      reject(new Error('Unsupported file type'))
      return
    }
    if (file.size > SCREENSHOT_MAX_BYTES) {
      reject(new Error('Image is too large'))
      return
    }

    const reader = new FileReader()
    reader.onload = () => {
      if (typeof reader.result === 'string') {
        resolve(reader.result)
        return
      }
      reject(new Error('Could not read image'))
    }
    reader.onerror = () => reject(new Error('Could not read image'))
    reader.readAsDataURL(file)
  })
}

export function DiagramNodeKindProperties({
  node,
  themeId,
  onNodeChange,
}: DiagramNodeKindPropertiesProps) {
  const screenshotInputRef = useRef<HTMLInputElement>(null)

  if (node.kind === 'service') {
    const iconValue = node.iconSrc ?? ''

    return (
      <div className="space-y-4">
        <CoverBuiltInIconPicker
          id={`diagram-service-icon-${node.id}`}
          label="Icon"
          description="Optional. Choose a brand or Lucide icon for this element."
          value={iconValue}
          isCustomImage={false}
          onSelectBuiltIn={(iconSrc) => onNodeChange(node.id, { iconSrc })}
        />
        {iconValue ? (
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8 text-[12px]"
            onClick={() => onNodeChange(node.id, { iconSrc: undefined })}
          >
            Remove icon
          </Button>
        ) : null}
        <div className="space-y-2">
          <Label htmlFor={`diagram-service-subtitle-${node.id}`} className="text-[12px]">
            Subtitle
          </Label>
          <Input
            id={`diagram-service-subtitle-${node.id}`}
            value={node.subtitle ?? ''}
            maxLength={64}
            placeholder="Optional helper text"
            className="h-9 text-[13px]"
            onChange={(event) =>
              onNodeChange(node.id, {
                subtitle: event.target.value || undefined,
              })
            }
          />
        </div>
      </div>
    )
  }

  if (node.kind === 'title') {
    return (
      <div className="space-y-2">
        <Label htmlFor={`diagram-title-subtitle-${node.id}`} className="text-[12px]">
          Subtitle
        </Label>
        <Input
          id={`diagram-title-subtitle-${node.id}`}
          value={node.subtitle ?? ''}
          maxLength={96}
          placeholder="Optional supporting line"
          className="h-9 text-[13px]"
          onChange={(event) =>
            onNodeChange(node.id, {
              subtitle: event.target.value || undefined,
            })
          }
        />
      </div>
    )
  }

  if (node.kind === 'icon') {
    return (
      <div className="space-y-4">
        <CoverBuiltInIconPicker
          id={`diagram-icon-${node.id}`}
          label="Icon"
          description="Choose a brand or Lucide icon for this element."
          value={getDiagramNodeIconSrc(node)}
          isCustomImage={false}
          onSelectBuiltIn={(iconSrc) => onNodeChange(node.id, { iconSrc })}
        />
        <div className="space-y-2">
          <Label htmlFor={`diagram-icon-caption-${node.id}`} className="text-[12px]">
            Caption
          </Label>
          <Input
            id={`diagram-icon-caption-${node.id}`}
            value={node.label}
            maxLength={48}
            placeholder="Optional"
            className="h-9 text-[13px]"
            onChange={(event) => onNodeChange(node.id, { label: event.target.value })}
          />
        </div>
      </div>
    )
  }

  if (node.kind === 'screenshot') {
    const focus = getDiagramScreenshotFocus(node)

    return (
      <div className="space-y-4">
        <div className="space-y-2">
          <Label className="text-[12px]">Image</Label>
          <div className="rounded-lg border border-border bg-muted/20 p-3">
            <div className="mb-3 overflow-hidden rounded-md border border-border">
              <CoverHeroBrowserFrame
                frameWidth={240}
                frameHeight={SCREENSHOT_PREVIEW_FRAME_HEIGHT}
                themeId={themeId}
                closed
                src={node.imageSrc}
                focusX={focus.focusX}
                focusY={focus.focusY}
                alt={node.label || 'Screenshot'}
                placeholder={
                  <div className="flex h-full w-full items-center justify-center bg-[#17171c] text-[12px] text-white/45">
                    No image
                  </div>
                }
              />
            </div>
            <div className="flex flex-wrap gap-2">
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="h-8 text-[12px]"
                onClick={() => screenshotInputRef.current?.click()}
              >
                {node.imageSrc ? 'Replace image' : 'Upload image'}
              </Button>
              {node.imageSrc ? (
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-8 text-[12px]"
                  onClick={() => onNodeChange(node.id, { imageSrc: undefined })}
                >
                  Remove
                </Button>
              ) : null}
            </div>
            <input
              ref={screenshotInputRef}
              type="file"
              accept={SCREENSHOT_ACCEPT}
              className="hidden"
              onChange={(event) => {
                const file = event.target.files?.[0]
                event.target.value = ''
                if (!file) return

                void readScreenshotFile(file)
                  .then((imageSrc) => onNodeChange(node.id, { imageSrc }))
                  .catch(() => {
                    // Ignore invalid uploads silently; user can retry.
                  })
              }}
            />
            <p className="mt-2 text-[11px] text-muted-foreground">
              PNG, JPEG, WebP, or AVIF up to 4 MB.
            </p>
          </div>
        </div>
        {node.imageSrc ? (
          <DiagramScreenshotGravityPicker
            node={node}
            onChange={(patch) => onNodeChange(node.id, patch)}
          />
        ) : null}
        <div className="space-y-2">
          <Label htmlFor={`diagram-screenshot-caption-${node.id}`} className="text-[12px]">
            Caption
          </Label>
          <Input
            id={`diagram-screenshot-caption-${node.id}`}
            value={node.label}
            maxLength={48}
            placeholder="Optional"
            className="h-9 text-[13px]"
            onChange={(event) => onNodeChange(node.id, { label: event.target.value })}
          />
        </div>
      </div>
    )
  }

  if (node.kind === 'table') {
    const defaults = createDefaultDiagramTable()
    const headers = node.tableHeaders ?? defaults.tableHeaders
    const rows = node.tableRows ?? defaults.tableRows

    return (
      <div className="space-y-4">
        <div className="space-y-2">
          <Label htmlFor={`diagram-table-title-${node.id}`} className="text-[12px]">
            Title
          </Label>
          <Input
            id={`diagram-table-title-${node.id}`}
            value={node.label}
            maxLength={48}
            className="h-9 text-[13px]"
            onChange={(event) => onNodeChange(node.id, { label: event.target.value })}
          />
        </div>
        <DiagramTableEditor
          headers={headers}
          rows={rows}
          onChange={(patch) => onNodeChange(node.id, patch)}
        />
      </div>
    )
  }

  return null
}

export function shouldShowDiagramNodeLabelField(node: DiagramNode): boolean {
  return !['icon', 'screenshot', 'table'].includes(node.kind)
}

export function getDiagramNodeLabelFieldLabel(node: DiagramNode): string {
  return node.kind === 'title' ? 'Title' : 'Label'
}

export function getDiagramNodeLabelMaxLength(node: DiagramNode): number {
  return node.kind === 'title' ? 96 : 48
}

export function getDiagramNodePropertiesSubtitle(node: DiagramNode): string {
  if (node.kind === 'service') return 'Element'
  if (node.kind === 'title') return 'Title element'
  if (node.kind === 'icon') return 'Icon element'
  if (node.kind === 'screenshot') return 'Screenshot element'
  if (node.kind === 'table') return 'Table element'
  return `${node.kind} node`
}

export function getDiagramNodeLayerSubtitle(node: DiagramNode): string {
  return DIAGRAM_NODE_KIND_LABELS[node.kind]
}
