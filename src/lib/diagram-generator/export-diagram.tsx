import { createRoot } from 'react-dom/client'
import { flushSync } from 'react-dom'
import { toCanvas } from 'html-to-image'
import type { CoverImageFormat } from '@/lib/cover-generator/constants'
import {
  getCoverCanvasEncodeQuality,
  getCoverImageMimeType,
} from '@/lib/cover-generator/cover-image-format'
import { encodeCoverImageBlob } from '@/lib/cover-generator/fetch-cover-image'
import type { CoverDownloadScale } from '@/lib/cover-generator/download-scale'
import { DiagramArtboard } from '@/components/pages/generator/diagrams/_components/DiagramArtboard'
import type { DiagramDocument } from '@/lib/diagram-generator/types'

type CaptureDiagramOptions = {
  pixelRatio?: CoverDownloadScale
  format?: CoverImageFormat
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

export async function captureDiagramBlob(
  document: DiagramDocument,
  options: CaptureDiagramOptions = {},
): Promise<Blob> {
  const format = options.format ?? document.format
  const pixelRatio = options.pixelRatio ?? 1
  const mount = window.document.createElement('div')
  mount.style.position = 'fixed'
  mount.style.left = '-10000px'
  mount.style.top = '0'
  mount.style.pointerEvents = 'none'
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

    await waitForPaint()

    const artboard = mount.firstElementChild as HTMLElement | null
    if (!artboard) {
      throw new Error('Could not render diagram for export')
    }

    const canvas = await toCanvas(artboard, {
      width: document.width,
      height: document.height,
      pixelRatio,
      cacheBust: true,
    })

    return canvasToBlob(canvas, format)
  } finally {
    root.unmount()
    mount.remove()
  }
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
