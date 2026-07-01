import {
  ChevronDown,
  Copy,
  Download,
  ExternalLink,
  Loader2,
  Maximize2,
  ZoomIn,
  ZoomOut,
} from 'lucide-react'
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
} from '@/lib/cover-generator/cover-layout-scale'
import { useCoverPreviewImage } from '@/lib/cover-generator/use-cover-preview-image'
import {
  CoverScaledPreview,
  isCoverGeneratorDomPreviewTemplate,
} from '@/components/pages/generator/_components/CoverPreviewContent'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { COVER_IMAGE_FORMATS } from '@/lib/cover-generator/constants'
import type { CoverImageFormat } from '@/lib/cover-generator/constants'
import {
  COVER_DOWNLOAD_SCALES,
  formatCoverDimensionsLabel,
  formatCoverDownloadScaleLabel,
  getCoverScaledDimensions,
  type CoverDownloadScale,
} from '@/lib/cover-generator/download-scale'
import type { CoverRenderData } from '@/lib/cover-generator/types'
import { useViewportPanZoom } from '@/lib/hooks/useViewportPanZoom'
import { cn } from '@/lib/utils'

type CoverCanvasProps = {
  data: CoverRenderData
  recommendsPost: boolean
  onCanvasSizeChange: (width: number, height: number) => void
  onCopyApiUrl: () => void
  onOpenImage: () => void
  onDownload: (format: CoverImageFormat, scale: CoverDownloadScale) => void
}

export function CoverCanvas({
  data,
  recommendsPost,
  onCanvasSizeChange,
  onCopyApiUrl,
  onOpenImage,
  onDownload,
}: CoverCanvasProps) {
  const handleDownload = (format: CoverImageFormat, scale: CoverDownloadScale) => {
    onDownload(format, scale)
  }

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

  const isDomPreview = isCoverGeneratorDomPreviewTemplate(data.template)
  const { data: previewUrl, isFetching, isError } = useCoverPreviewImage(data, {
    enabled: !isDomPreview,
  })
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
          <SchemaBlueprintMat />
          <div className="flex h-full w-full items-center justify-center px-4 py-10">
            <div
              className="relative shrink-0 overflow-hidden rounded-xl border border-border bg-background shadow-sm"
              style={{
                width: COVER_ARTBOARD_DISPLAY_WIDTH,
                height: displayHeight,
              }}
            >
              {previewUrl || isDomPreview ? (
                <CoverScaledPreview
                  data={data}
                  previewUrl={previewUrl}
                  displayWidth={COVER_ARTBOARD_DISPLAY_WIDTH}
                />
              ) : (
                <div
                  className="flex items-center justify-center overflow-hidden bg-muted/20 text-[13px] text-muted-foreground"
                  style={{
                    width: COVER_ARTBOARD_DISPLAY_WIDTH,
                    height: displayHeight,
                  }}
                >
                  {isError ? 'Could not render preview' : 'Rendering preview…'}
                </div>
              )}
              {!isDomPreview && isFetching && previewUrl ? (
                <div className="absolute inset-0 flex items-center justify-center bg-background/40">
                  <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
                </div>
              ) : null}
            </div>
          </div>
        </div>
      </div>

      <div className="pointer-events-none absolute inset-x-4 top-4 z-20 flex items-start justify-between gap-2">
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
        <div className="pointer-events-auto flex max-w-[min(100%,720px)] shrink-0 flex-wrap items-center justify-end gap-2">
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8 border-border bg-card/95 text-[12px] backdrop-blur-sm"
            onClick={onCopyApiUrl}
            disabled={recommendsPost}
            title={
              recommendsPost
                ? 'This cover is too large for a GET URL. Open API docs for POST examples.'
                : undefined
            }
          >
            <Copy className="me-1.5 size-3.5" />
            Copy URL
          </Button>

          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-8 border-border bg-card/95 text-[12px] backdrop-blur-sm"
            onClick={onOpenImage}
          >
            <ExternalLink className="me-1.5 size-3.5" />
            Open image
          </Button>

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
                  {preset.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>

          <div className="relative isolate flex shrink-0 items-stretch">
            <Button
              type="button"
              size="sm"
              className="relative z-[1] h-8 rounded-e-none px-3 text-[12px] shadow-sm"
              onClick={() => handleDownload(data.format, 1)}
            >
              <Download className="me-1.5 size-3.5" />
              Download
            </Button>
            <DropdownMenu modal={false}>
              <DropdownMenuTrigger asChild>
                <Button
                  type="button"
                  size="sm"
                  className="relative z-[2] h-8 rounded-s-none border-s border-primary-foreground/15 px-2 shadow-sm"
                  aria-label="More download options"
                >
                  <ChevronDown className="size-3.5" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end" className="w-60 p-1.5">
                {COVER_IMAGE_FORMATS.map((format, formatIndex) => (
                  <DropdownMenuGroup key={format}>
                    {formatIndex > 0 ? <DropdownMenuSeparator className="my-1.5" /> : null}
                    <DropdownMenuLabel className="px-2 py-1 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                      {format.toUpperCase()}
                    </DropdownMenuLabel>
                    {COVER_DOWNLOAD_SCALES.map((scale) => {
                      const dimensions = getCoverScaledDimensions(data.width, data.height, scale)
                      const scaleLabel = formatCoverDownloadScaleLabel(scale)
                      const sizeLabel = dimensions
                        ? formatCoverDimensionsLabel(dimensions.width, dimensions.height)
                        : 'Too large'

                      return (
                        <DropdownMenuItem
                          key={`${format}-${scale}`}
                          disabled={!dimensions}
                          className="min-h-10 cursor-pointer px-2.5 py-2 text-[13px]"
                          title={
                            dimensions
                              ? undefined
                              : 'Exceeds maximum export size (4096px)'
                          }
                          onSelect={() => handleDownload(format, scale)}
                        >
                          <span className="font-medium">{scaleLabel}</span>
                          <span className="ms-auto text-[12px] text-muted-foreground">
                            {sizeLabel}
                          </span>
                        </DropdownMenuItem>
                      )
                    })}
                  </DropdownMenuGroup>
                ))}
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
        </div>
      </div>
    </div>
  )
}
