import { Loader2, Maximize2, ZoomIn, ZoomOut } from 'lucide-react'
import { useCallback, useEffect } from 'react'
import { SchemaBlueprintMat } from '@/components/global/shared/SchemaBlueprintMat'
import {
  COVER_SIZE_PRESETS,
  getCoverSizePresetKey,
  resolveCoverSizePresetKey,
} from '@/lib/cover-generator/constants'
import {
  COVER_ARTBOARD_DISPLAY_WIDTH,
  getCoverDisplayHeight,
  getCoverDisplayScale,
} from '@/lib/cover-generator/cover-layout-scale'
import { useCoverPreviewImage } from '@/lib/cover-generator/use-cover-preview-image'
import { CoverCardsAngledPreview } from '@/components/pages/generator/_components/CoverCardsAngledPreview'
import { CoverScreenshotAngledPreview } from '@/components/pages/generator/_components/CoverScreenshotAngledPreview'
import { Button } from '@/components/ui/button'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import type { CoverRenderData } from '@/lib/cover-generator/types'
import { useViewportPanZoom } from '@/lib/hooks/useViewportPanZoom'
import { cn } from '@/lib/utils'

type CoverCanvasProps = {
  data: CoverRenderData
  onCanvasSizeChange: (width: number, height: number) => void
}

export function CoverCanvas({ data, onCanvasSizeChange }: CoverCanvasProps) {
  const {
    canvasRef,
    pan,
    zoom,
    isDragging,
    bindCanvas,
    zoomIn,
    zoomOut,
    resetView,
    zoomPercentage,
    zoomInDisabled,
    zoomOutDisabled,
  } = useViewportPanZoom({ maxZoom: 3 })

  const isDomPreview =
    data.template === 'screenshot-angled' || data.template === 'cards-angled'
  const { data: previewUrl, isFetching, isError } = useCoverPreviewImage(data, {
    enabled: !isDomPreview,
  })
  const displayScale = getCoverDisplayScale(data.width)
  const displayHeight = getCoverDisplayHeight(data.width, data.height)
  const canvasPresetKey = getCoverSizePresetKey(data.width, data.height)

  const handleCanvasKeyDown = useCallback(
    (e: React.KeyboardEvent<HTMLDivElement>) => {
      if (e.metaKey || e.ctrlKey || e.altKey) return
      const key = e.key
      if (key === '+' || key === '=') {
        if (zoomInDisabled) return
        e.preventDefault()
        zoomIn()
      } else if (key === '-' || key === '_') {
        if (zoomOutDisabled) return
        e.preventDefault()
        zoomOut()
      } else if (key === '0') {
        e.preventDefault()
        resetView()
      }
    },
    [zoomIn, zoomOut, resetView, zoomInDisabled, zoomOutDisabled],
  )

  useEffect(() => {
    resetView()
  }, [data.template, data.theme, data.width, data.height, resetView])

  return (
    <div className="relative min-h-0 flex-1 overflow-hidden bg-background">
      <div
        ref={canvasRef}
        {...bindCanvas}
        tabIndex={-1}
        role="application"
        aria-label="Pan and zoom cover preview. Scroll to zoom, drag to pan. Keys: + zoom in, - zoom out, 0 reset."
        onKeyDown={handleCanvasKeyDown}
        className={cn(
          'absolute inset-0 overflow-hidden select-none outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background',
          isDragging ? 'cursor-grabbing' : 'cursor-grab',
        )}
      >
        <div
          className="pointer-events-none h-full w-full overflow-hidden will-change-transform"
          style={{
            transform: `translate(${pan.x}px, ${pan.y}px) scale(${zoom})`,
            transformOrigin: '0 0',
          }}
        >
          <SchemaBlueprintMat className={isDomPreview ? 'hidden' : undefined} />
          <div className="flex h-full w-full items-center justify-center px-4 py-10">
            <div
              className="relative shrink-0 overflow-hidden rounded-xl border border-border bg-background shadow-sm"
              style={{
                width: COVER_ARTBOARD_DISPLAY_WIDTH,
                height: displayHeight,
              }}
            >
              {data.template === 'screenshot-angled' ? (
                <div
                  className="block max-w-none origin-top-left overflow-hidden"
                  style={{
                    width: data.width,
                    height: data.height,
                    transform: `scale(${displayScale})`,
                  }}
                >
                  <CoverScreenshotAngledPreview
                    data={data}
                    width={data.width}
                    height={data.height}
                  />
                </div>
              ) : data.template === 'cards-angled' ? (
                <div
                  className="block max-w-none origin-top-left overflow-hidden"
                  style={{
                    width: data.width,
                    height: data.height,
                    transform: `scale(${displayScale})`,
                  }}
                >
                  <CoverCardsAngledPreview
                    data={data}
                    width={data.width}
                    height={data.height}
                  />
                </div>
              ) : previewUrl ? (
                <img
                  src={previewUrl}
                  alt="Cover preview"
                  draggable={false}
                  className="block max-w-none origin-top-left overflow-hidden"
                  style={{
                    width: data.width,
                    height: data.height,
                    transform: `scale(${displayScale})`,
                  }}
                />
              ) : !isDomPreview ? (
                <div
                  className="flex items-center justify-center overflow-hidden bg-muted/20 text-[13px] text-muted-foreground"
                  style={{
                    width: COVER_ARTBOARD_DISPLAY_WIDTH,
                    height: displayHeight,
                  }}
                >
                  {isError ? 'Could not render preview' : 'Rendering preview…'}
                </div>
              ) : null}
              {!isDomPreview && isFetching && previewUrl ? (
                <div className="absolute inset-0 flex items-center justify-center bg-background/40">
                  <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
                </div>
              ) : null}
            </div>
          </div>
        </div>
      </div>

      <div className="pointer-events-none absolute inset-x-4 top-4 z-20 flex items-center justify-between gap-2">
        <div className="pointer-events-auto flex shrink-0 items-center gap-2">
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8 w-8 border-border bg-card/95 p-0 backdrop-blur-sm"
            onClick={zoomIn}
            disabled={zoomInDisabled}
            aria-label="Zoom in"
          >
            <ZoomIn className="h-4 w-4" />
          </Button>
          <div className="flex h-8 min-w-[64px] items-center justify-center rounded-md border border-border bg-card/95 px-3 backdrop-blur-sm">
            <span className="text-[12px] font-medium text-foreground">
              {zoomPercentage}%
            </span>
          </div>
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8 w-8 border-border bg-card/95 p-0 backdrop-blur-sm"
            onClick={zoomOut}
            disabled={zoomOutDisabled}
            aria-label="Zoom out"
          >
            <ZoomOut className="h-4 w-4" />
          </Button>
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8 w-8 border-border bg-card/95 p-0 backdrop-blur-sm"
            onClick={resetView}
            aria-label="Reset pan and zoom"
          >
            <Maximize2 className="h-4 w-4" />
          </Button>
        </div>
        <div className="pointer-events-auto shrink-0">
          <Select
            value={canvasPresetKey}
            onValueChange={(value) => {
              const { width, height } = resolveCoverSizePresetKey(value)
              onCanvasSizeChange(width, height)
            }}
          >
            <SelectTrigger
              aria-label="Canvas size"
              className="h-8 w-auto max-w-[min(100%,220px)] border-border bg-card/95 text-[12px] backdrop-blur-sm"
            >
              <SelectValue />
            </SelectTrigger>
            <SelectContent align="end">
              {COVER_SIZE_PRESETS.map((preset) => (
                <SelectItem key={preset.id} value={preset.id}>
                  {preset.label} ({preset.width} × {preset.height})
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>
      </div>
    </div>
  )
}
