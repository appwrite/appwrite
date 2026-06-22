import { ChevronDown, Copy, Download, ExternalLink, Terminal } from 'lucide-react'
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
  COVER_DOWNLOAD_SCALES,
  formatCoverDimensionsLabel,
  formatCoverDownloadScaleLabel,
  getCoverScaledDimensions,
} from '@/lib/cover-generator/download-scale'
import { COVER_IMAGE_FORMATS } from '@/lib/cover-generator/constants'
import type { CoverImageFormat } from '@/lib/cover-generator/constants'
import type { CoverDownloadScale } from '@/lib/cover-generator/download-scale'
import type { CoverRenderData } from '@/lib/cover-generator/types'

type CoverEditorToolbarProps = {
  data: CoverRenderData
  recommendsPost: boolean
  onChange: (next: CoverRenderData) => void
  onCopyApiUrl: () => void
  onOpenApiDocs: () => void
  onOpenImage: () => void
  onDownload: (format: CoverImageFormat, scale: CoverDownloadScale) => void
}

export function CoverEditorToolbar({
  data,
  recommendsPost,
  onChange,
  onCopyApiUrl,
  onOpenApiDocs,
  onOpenImage,
  onDownload,
}: CoverEditorToolbarProps) {
  const handleDownload = (format: CoverImageFormat, scale: CoverDownloadScale) => {
    onChange({ ...data, format })
    onDownload(format, scale)
  }

  return (
    <div className="flex shrink-0 flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b border-border bg-background px-4 py-2.5">
      <p className="shrink-0 text-[13px] font-medium text-foreground">Cover generator</p>

      <div className="flex shrink-0 flex-wrap items-center justify-end gap-2">
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-9 text-[13px]"
          onClick={onOpenApiDocs}
        >
          <Terminal className="mr-1.5 size-3.5" />
          API
        </Button>

        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-9 text-[13px]"
          onClick={onCopyApiUrl}
          disabled={recommendsPost}
          title={
            recommendsPost
              ? 'This cover is too large for a GET URL. Open API docs for POST examples.'
              : undefined
          }
        >
          <Copy className="mr-1.5 size-3.5" />
          Copy URL
        </Button>

        <div className="relative isolate flex shrink-0 items-stretch">
          <Button
            type="button"
            size="sm"
            className="relative z-[1] h-9 rounded-r-none px-4 text-[13px]"
            onClick={() => handleDownload(data.format, 1)}
          >
            <Download className="mr-1.5 size-3.5" />
            Download
          </Button>
          <DropdownMenu modal={false}>
            <DropdownMenuTrigger asChild>
              <Button
                type="button"
                size="sm"
                className="relative z-[2] h-9 rounded-l-none border-l border-primary-foreground/15 px-2.5"
                aria-label="More download options"
              >
                <ChevronDown className="size-4" />
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
                        <span className="ml-auto text-[12px] text-muted-foreground">
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

        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-9 text-[13px]"
          onClick={onOpenImage}
        >
          <ExternalLink className="mr-1.5 size-3.5" />
          Open image
        </Button>
      </div>
    </div>
  )
}
