import { CoverCardsAngledPreview } from '@/components/pages/generator/_components/CoverCardsAngledPreview'
import { CoverScreenshotAngledPreview } from '@/components/pages/generator/_components/CoverScreenshotAngledPreview'
import {
  isCoverDomPreviewTemplate,
  normalizeCoverCardsAngledData,
} from '@/lib/cover-generator/cards-angled/constants'
import type { CoverTemplateId } from '@/lib/cover-generator/constants'
import type { CoverRenderData } from '@/lib/cover-generator/types'
import { cn } from '@/lib/utils'

export function isCoverGeneratorDomPreviewTemplate(
  template: CoverTemplateId | string,
): boolean {
  return isCoverDomPreviewTemplate(template)
}

type CoverPreviewContentProps = {
  data: CoverRenderData
  previewUrl?: string | null
  className?: string
}

/** Renders cover art at export dimensions (before display scaling). */
export function CoverPreviewContent({
  data,
  previewUrl,
  className,
}: CoverPreviewContentProps) {
  const { width, height } = data

  if (data.template === 'screenshot-angled') {
    return (
      <CoverScreenshotAngledPreview
        data={data}
        width={width}
        height={height}
      />
    )
  }

  if (data.template === 'cards-angled') {
    return (
      <CoverCardsAngledPreview
        data={normalizeCoverCardsAngledData(data)}
        width={width}
        height={height}
      />
    )
  }

  if (previewUrl) {
    return (
      <img
        src={previewUrl}
        alt=""
        draggable={false}
        className={cn('block max-w-none', className)}
        style={{ width, height }}
      />
    )
  }

  return null
}

type CoverScaledPreviewProps = {
  data: CoverRenderData
  previewUrl?: string | null
  displayWidth: number
  className?: string
}

/** Uniformly scales {@link CoverPreviewContent} to a target display width. */
export function CoverScaledPreview({
  data,
  previewUrl,
  displayWidth,
  className,
}: CoverScaledPreviewProps) {
  const scale = displayWidth / data.width
  const displayHeight = Math.round(data.height * scale)

  return (
    <div
      className={cn('relative shrink-0 overflow-hidden', className)}
      style={{ width: displayWidth, height: displayHeight }}
    >
      <div
        className="pointer-events-none absolute left-0 top-0 origin-top-left"
        style={{
          width: data.width,
          height: data.height,
          transform: `scale(${scale})`,
        }}
      >
        <CoverPreviewContent data={data} previewUrl={previewUrl} />
      </div>
    </div>
  )
}
