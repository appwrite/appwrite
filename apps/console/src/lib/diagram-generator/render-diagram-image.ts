import sharp from 'sharp'
import { applyCoverImageFormat } from '@/lib/cover-generator/encode-cover-image-sharp'
import { renderDiagramTemplateSvg } from '@/lib/diagram-generator/render-diagram-svg'
import type { DiagramDocument } from '@/lib/diagram-generator/types'

export async function renderDiagramImage(document: DiagramDocument): Promise<Uint8Array> {
  const svg = await renderDiagramTemplateSvg(document)
  const buffer = await applyCoverImageFormat(sharp(Buffer.from(svg)), document.format)
  return new Uint8Array(buffer)
}
