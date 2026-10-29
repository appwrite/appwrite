import { createRoot } from 'react-dom/client'
import { flushSync } from 'react-dom'
import { toCanvas } from 'html-to-image'
import { CoverPreviewContent } from '@/components/pages/generator/_components/CoverPreviewContent'
import {
  isCoverDomPreviewTemplate,
  normalizeCoverCardsAngledData,
} from '@/lib/cover-generator/cards-angled/constants'
import type { CoverImageFormat } from '@/lib/cover-generator/constants'
import {
  getCoverCanvasEncodeQuality,
  getCoverImageMimeType,
} from '@/lib/cover-generator/cover-image-format'
import { encodeCoverImageBlob } from '@/lib/cover-generator/fetch-cover-image'
import { resolveCoverRenderDataInlineAssets } from '@/lib/cover-generator/editor-image-fields'
import type { CoverRenderData } from '@/lib/cover-generator/types'

type CaptureCoverDomPreviewOptions = {
  renderWidth: number
  renderHeight: number
  format: CoverImageFormat
  pixelRatio?: number
}

async function preloadImages(element: HTMLElement) {
  const images = element.querySelectorAll('img')
  await Promise.all(
    Array.from(images).map(
      (img) =>
        new Promise<void>((resolve) => {
          if (img.complete && img.naturalWidth > 0) {
            resolve()
            return
          }
          const done = () => resolve()
          img.addEventListener('load', done, { once: true })
          img.addEventListener('error', done, { once: true })
        }),
    ),
  )
}

function waitForPaint() {
  return new Promise<void>((resolve) => {
    requestAnimationFrame(() => requestAnimationFrame(() => resolve()))
  })
}

function tryCanvasToBlob(
  canvas: HTMLCanvasElement,
  mimeType: string,
  quality?: number,
): Promise<Blob | null> {
  return new Promise((resolve) => {
    canvas.toBlob(
      (blob) => resolve(blob),
      mimeType,
      quality,
    )
  })
}

async function canvasToBlob(
  canvas: HTMLCanvasElement,
  format: CoverImageFormat,
): Promise<Blob> {
  const mimeType = getCoverImageMimeType(format)
  const quality = getCoverCanvasEncodeQuality(format)
  const blob = await tryCanvasToBlob(canvas, mimeType, quality)

  if (blob) return blob

  if (format === 'avif') {
    const pngBlob = await tryCanvasToBlob(canvas, 'image/png')
    if (!pngBlob) {
      throw new Error('Could not encode cover image')
    }
    return encodeCoverImageBlob(pngBlob, 'avif')
  }

  throw new Error('Could not encode cover image')
}

function renderCoverDomPreview(
  data: CoverRenderData,
  width: number,
  height: number,
) {
  if (!isCoverDomPreviewTemplate(data.template)) {
    throw new Error(`Template "${data.template}" does not support DOM preview capture`)
  }

  const sizedData = { ...data, width, height }

  if (data.template === 'cards-angled') {
    return (
      <CoverPreviewContent
        data={normalizeCoverCardsAngledData(sizedData)}
      />
    )
  }

  return <CoverPreviewContent data={sizedData} />
}

export async function captureCoverDomPreviewBlob(
  data: CoverRenderData,
  { renderWidth, renderHeight, format, pixelRatio = 1 }: CaptureCoverDomPreviewOptions,
): Promise<Blob> {
  if (typeof document === 'undefined') {
    throw new Error('Cover capture requires a browser environment')
  }

  const captureData = await resolveCoverRenderDataInlineAssets({
    ...data,
    width: renderWidth,
    height: renderHeight,
  })

  const mount = document.createElement('div')
  mount.style.position = 'fixed'
  mount.style.left = '-100000px'
  mount.style.top = '0'
  mount.style.width = `${renderWidth}px`
  mount.style.height = `${renderHeight}px`
  mount.style.pointerEvents = 'none'
  mount.style.opacity = '1'
  document.body.appendChild(mount)

  const root = createRoot(mount)

  flushSync(() => {
    root.render(renderCoverDomPreview(captureData, renderWidth, renderHeight))
  })

  const target =
    mount.firstElementChild instanceof HTMLElement ? mount.firstElementChild : null

  if (!target) {
    root.unmount()
    mount.remove()
    throw new Error('Cover capture target is missing')
  }

  try {
    await document.fonts.ready
    await preloadImages(target)
    await waitForPaint()

    const canvas = await toCanvas(target, {
      width: renderWidth,
      height: renderHeight,
      pixelRatio,
      cacheBust: false,
      skipAutoScale: true,
    })

    return canvasToBlob(canvas, format)
  } finally {
    root.unmount()
    mount.remove()
  }
}

export function shouldCaptureCoverDomPreviewClientSide(
  data: CoverRenderData,
): boolean {
  return isCoverDomPreviewTemplate(data.template)
}
