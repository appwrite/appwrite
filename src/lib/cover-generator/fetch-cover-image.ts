import { buildCoverApiUrl } from '@/lib/cover-generator/parse-params'
import { getCoverCardsAngledIconKeys } from '@/lib/cover-generator/cards-angled/constants'
import type { CoverImageFormat } from '@/lib/cover-generator/constants'
import { getCoverImageMimeType } from '@/lib/cover-generator/cover-image-format'
import { resolveCoverRenderDataInlineAssets } from '@/lib/cover-generator/editor-image-fields'
import {
  buildCoverDownloadFilename,
  getCoverScaledDimensions,
  type CoverDownloadScale,
} from '@/lib/cover-generator/download-scale'
import type { CoverRenderData } from '@/lib/cover-generator/types'

const MAX_COVER_GET_URL_LENGTH = 1800

function getCoverImageFieldValues(data: CoverRenderData): (string | undefined)[] {
  switch (data.template) {
    case 'integration':
      return [data.logoLeft, data.logoRight]
    case 'integration-icon':
    case 'showcase-icon':
    case 'title-icon':
      return [data.icon]
    case 'screenshot':
    case 'screenshot-side':
    case 'screenshot-angled':
      return [data.screenshot]
    case 'cards-angled':
      return getCoverCardsAngledIconKeys().map((key) => data[key])
    default:
      return []
  }
}

export function coverRenderDataHasInlineAssets(data: CoverRenderData): boolean {
  return getCoverImageFieldValues(data).some(
    (value) => value?.startsWith('data:') || value?.startsWith('blob:'),
  )
}

export function shouldPostCoverRenderRequest(
  data: CoverRenderData,
  origin = '',
): boolean {
  if (coverRenderDataHasInlineAssets(data)) return true
  return buildCoverApiUrl(data, origin).length > MAX_COVER_GET_URL_LENGTH
}

export function buildCoverDownloadData(
  data: CoverRenderData,
  scale: CoverDownloadScale,
): CoverRenderData | null {
  const dimensions = getCoverScaledDimensions(data.width, data.height, scale)
  if (!dimensions) return null
  return { ...data, ...dimensions }
}

export async function fetchCoverImage(
  data: CoverRenderData,
  origin = typeof window !== 'undefined' ? window.location.origin : '',
): Promise<Blob> {
  const baseOrigin = origin.replace(/\/+$/, '')
  const requestData = shouldPostCoverRenderRequest(data, baseOrigin)
    ? await resolveCoverRenderDataInlineAssets(data)
    : data
  const response = shouldPostCoverRenderRequest(data, baseOrigin)
    ? await fetch(`${baseOrigin}/generator/cover`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(requestData),
      })
    : await fetch(buildCoverApiUrl(data, baseOrigin))

  if (!response.ok) {
    throw new Error(`Failed to render cover (${response.status})`)
  }

  return response.blob()
}

export function downloadCoverImageBlob(
  blob: Blob,
  data: CoverRenderData,
  scale: CoverDownloadScale = 1,
): void {
  const objectUrl = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = objectUrl
  link.download = buildCoverDownloadFilename(
    data.template,
    data.format,
    scale,
    data.width,
    data.height,
  )
  link.rel = 'noopener'
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(objectUrl)
}

export async function encodeCoverImageBlob(
  source: Blob,
  format: CoverImageFormat,
  origin = typeof window !== 'undefined' ? window.location.origin : '',
): Promise<Blob> {
  if (format === 'png') return source

  const baseOrigin = origin.replace(/\/+$/, '')
  const response = await fetch(
    `${baseOrigin}/generator/cover/encode?format=${format}`,
    {
      method: 'POST',
      headers: { 'Content-Type': 'image/png' },
      body: source,
    },
  )

  if (!response.ok) {
    throw new Error(`Failed to encode cover image (${response.status})`)
  }

  const blob = await response.blob()
  const expectedType = getCoverImageMimeType(format)
  if (blob.type && blob.type !== expectedType) {
    return new Blob([blob], { type: expectedType })
  }
  return blob
}
