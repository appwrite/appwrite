import { createRoot } from 'react-dom/client'
import { flushSync } from 'react-dom'
import { toCanvas } from 'html-to-image'
import type { CoverImageFormat } from '@/lib/cover-generator/constants'
import {
  getCoverCanvasEncodeQuality,
  getCoverImageMimeType,
} from '@/lib/cover-generator/cover-image-format'
import { encodeCoverImageBlob } from '@/lib/cover-generator/fetch-cover-image'
import {
  isCoverLucideIconValue,
  parseCoverLucideIconName,
} from '@/lib/cover-generator/lucide-icon-utils'
import { preloadCoverLucideIconNodes } from '@/lib/cover-generator/lucide-icon-svg'
import type { CoverDownloadScale } from '@/lib/cover-generator/download-scale'
import { DiagramArtboard } from '@/components/pages/generator/diagrams/_components/DiagramArtboard'
import { fetchDiagramImage } from '@/lib/diagram-generator/fetch-diagram-image'
import { getDiagramNodeIconSrc, hasDiagramNodeIcon } from '@/lib/diagram-generator/node-normalize'
import type { DiagramDocument } from '@/lib/diagram-generator/types'

type CaptureDiagramOptions = {
  pixelRatio?: CoverDownloadScale
  format?: CoverImageFormat
}

function collectDiagramLucideIconNames(document: DiagramDocument): string[] {
  const names = new Set<string>()

  for (const node of document.nodes) {
    if (!hasDiagramNodeIcon(node)) continue
    const src = getDiagramNodeIconSrc(node)
    if (!isCoverLucideIconValue(src)) continue
    const name = parseCoverLucideIconName(src)
    if (name) names.add(name)
  }

  return [...names]
}

function waitForPaint() {
  return new Promise<void>((resolve) => {
    requestAnimationFrame(() => requestAnimationFrame(() => resolve()))
  })
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

function tryCanvasToBlob(
  canvas: HTMLCanvasElement,
  mimeType: string,
  quality?: number,
): Promise<Blob | null> {
  return new Promise((resolve) => {
    canvas.toBlob((blob) => resolve(blob), mimeType, quality)
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
      throw new Error('Could not encode diagram image')
    }
    return encodeCoverImageBlob(pngBlob, 'avif')
  }

  throw new Error('Could not encode diagram image')
}

async function captureDiagramDomBlob(
  document: DiagramDocument,
  options: CaptureDiagramOptions = {},
): Promise<Blob> {
  if (typeof window === 'undefined') {
    throw new Error('Diagram capture requires a browser environment')
  }

  const format = options.format ?? document.format
  const pixelRatio = options.pixelRatio ?? 1

  await preloadCoverLucideIconNodes(collectDiagramLucideIconNames(document))

  const mount = window.document.createElement('div')
  mount.style.position = 'fixed'
  mount.style.left = '-100000px'
  mount.style.top = '0'
  mount.style.width = `${document.width}px`
  mount.style.height = `${document.height}px`
  mount.style.pointerEvents = 'none'
  mount.style.opacity = '1'
  window.document.body.appendChild(mount)

  const root = createRoot(mount)

  try {
    flushSync(() => {
      root.render(
        <DiagramArtboard
          document={document}
          width={document.width}
          height={document.height}
          interactive={false}
        />,
      )
    })

    const artboard = mount.firstElementChild as HTMLElement | null
    if (!artboard) {
      throw new Error('Could not render diagram for export')
    }

    await window.document.fonts.ready
    await preloadImages(artboard)
    await waitForPaint()

    const canvas = await toCanvas(artboard, {
      width: document.width,
      height: document.height,
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

/** Browser export uses the same React artboard as the editor for WYSIWYG output. */
export function shouldCaptureDiagramDomClientSide(): boolean {
  return typeof window !== 'undefined'
}

export async function captureDiagramBlob(
  document: DiagramDocument,
  options: CaptureDiagramOptions = {},
): Promise<Blob> {
  const payload = {
    ...document,
    format: options.format ?? document.format,
  }

  if (shouldCaptureDiagramDomClientSide()) {
    try {
      return await captureDiagramDomBlob(payload, options)
    } catch {
      // Fall back to the server SVG renderer when DOM capture fails (e.g. CORS images).
      return fetchDiagramImage(payload)
    }
  }

  return fetchDiagramImage(payload)
}

export function downloadDiagramBlob(
  blob: Blob,
  document: DiagramDocument,
  scale: CoverDownloadScale = 1,
): void {
  const scaleSuffix = scale === 1 ? '' : `-${scale}x`
  const extension = document.format === 'jpeg' ? 'jpg' : document.format
  const slug = document.title.trim().toLowerCase().replace(/\s+/g, '-').slice(0, 48) || 'diagram'
  const filename = `${slug}-${document.width}x${document.height}${scaleSuffix}.${extension}`

  const url = URL.createObjectURL(blob)
  const anchor = window.document.createElement('a')
  anchor.href = url
  anchor.download = filename
  anchor.click()
  URL.revokeObjectURL(url)
}

export async function openDiagramImage(document: DiagramDocument): Promise<void> {
  const blob = await captureDiagramBlob(document)
  const objectUrl = URL.createObjectURL(blob)
  window.open(objectUrl, '_blank', 'noopener,noreferrer')
  window.setTimeout(() => URL.revokeObjectURL(objectUrl), 60_000)
}
