import type { CoverTemplateId } from '@/lib/cover-generator/constants'
import {
  getCoverBrandLightRgb,
  type CoverSoftLightTone,
} from '@/lib/cover-generator/cover-brand-lights'
import type { CoverThemeFamily } from '@/lib/cover-generator/themes'

const BASE_WIDTH = 1200
const BASE_HEIGHT = 630

type ConfettiPieceDef = {
  /** Normalized x (0–1). */
  x: number
  /** Normalized y (0–1). */
  y: number
  w: number
  h: number
  rotation: number
  tone: CoverSoftLightTone
  opacity: number
  shape: 'rect' | 'circle'
}

/** Fixed layout so exports and previews stay consistent. */
const MILESTONE_CONFETTI_PIECES: ConfettiPieceDef[] = [
  { x: 0.06, y: 0.1, w: 16, h: 7, rotation: -32, tone: 'pink', opacity: 0.5, shape: 'rect' },
  { x: 0.14, y: 0.22, w: 8, h: 8, rotation: 0, tone: 'purple', opacity: 0.45, shape: 'circle' },
  { x: 0.22, y: 0.08, w: 12, h: 5, rotation: 48, tone: 'teal', opacity: 0.42, shape: 'rect' },
  { x: 0.04, y: 0.42, w: 10, h: 10, rotation: 0, tone: 'orange', opacity: 0.4, shape: 'circle' },
  { x: 0.18, y: 0.78, w: 14, h: 6, rotation: 22, tone: 'pink', opacity: 0.48, shape: 'rect' },
  { x: 0.08, y: 0.88, w: 7, h: 7, rotation: 0, tone: 'purple', opacity: 0.38, shape: 'circle' },
  { x: 0.32, y: 0.92, w: 11, h: 4, rotation: -18, tone: 'teal', opacity: 0.4, shape: 'rect' },
  { x: 0.88, y: 0.09, w: 15, h: 6, rotation: 36, tone: 'purple', opacity: 0.5, shape: 'rect' },
  { x: 0.94, y: 0.2, w: 9, h: 9, rotation: 0, tone: 'pink', opacity: 0.44, shape: 'circle' },
  { x: 0.78, y: 0.06, w: 12, h: 5, rotation: -42, tone: 'orange', opacity: 0.42, shape: 'rect' },
  { x: 0.96, y: 0.48, w: 8, h: 8, rotation: 0, tone: 'teal', opacity: 0.38, shape: 'circle' },
  { x: 0.86, y: 0.72, w: 13, h: 5, rotation: -28, tone: 'pink', opacity: 0.46, shape: 'rect' },
  { x: 0.92, y: 0.9, w: 10, h: 10, rotation: 0, tone: 'purple', opacity: 0.4, shape: 'circle' },
  { x: 0.72, y: 0.94, w: 14, h: 6, rotation: 54, tone: 'orange', opacity: 0.42, shape: 'rect' },
  { x: 0.48, y: 0.06, w: 9, h: 9, rotation: 0, tone: 'teal', opacity: 0.35, shape: 'circle' },
  { x: 0.58, y: 0.12, w: 11, h: 4, rotation: 18, tone: 'pink', opacity: 0.36, shape: 'rect' },
  { x: 0.42, y: 0.9, w: 12, h: 5, rotation: -24, tone: 'purple', opacity: 0.38, shape: 'rect' },
  { x: 0.52, y: 0.82, w: 7, h: 7, rotation: 0, tone: 'orange', opacity: 0.34, shape: 'circle' },
  { x: 0.38, y: 0.14, w: 6, h: 6, rotation: 0, tone: 'pink', opacity: 0.32, shape: 'circle' },
  { x: 0.64, y: 0.88, w: 10, h: 4, rotation: 40, tone: 'teal', opacity: 0.36, shape: 'rect' },
  { x: 0.28, y: 0.52, w: 8, h: 8, rotation: 0, tone: 'purple', opacity: 0.28, shape: 'circle' },
  { x: 0.68, y: 0.18, w: 9, h: 4, rotation: -12, tone: 'orange', opacity: 0.32, shape: 'rect' },
  { x: 0.12, y: 0.58, w: 11, h: 5, rotation: 62, tone: 'teal', opacity: 0.3, shape: 'rect' },
  { x: 0.82, y: 0.38, w: 7, h: 7, rotation: 0, tone: 'pink', opacity: 0.3, shape: 'circle' },
]

function rgba(rgb: [number, number, number], alpha: number): string {
  return `rgba(${rgb[0]}, ${rgb[1]}, ${rgb[2]}, ${alpha})`
}

export function isCoverMilestoneTemplate(
  templateId?: CoverTemplateId,
): boolean {
  return templateId === 'milestone-split' || templateId === 'milestone-centered'
}

export function buildCoverMilestoneConfettiSvg(
  width: number,
  height: number,
  family: CoverThemeFamily,
): string {
  if (width <= 0 || height <= 0) return ''

  const scaleX = width / BASE_WIDTH
  const scaleY = height / BASE_HEIGHT
  const opacityScale = family === 'dark' ? 1.12 : 1

  const shapes = MILESTONE_CONFETTI_PIECES.map((piece) => {
    const rgb = getCoverBrandLightRgb(piece.tone)
    const opacity = Math.min(0.72, piece.opacity * opacityScale)
    const cx = piece.x * width
    const cy = piece.y * height
    const w = piece.w * scaleX
    const h = piece.h * scaleY
    const color = rgba(rgb, opacity)

    if (piece.shape === 'circle') {
      const radius = ((w + h) / 4).toFixed(1)
      return `<circle cx="${cx.toFixed(1)}" cy="${cy.toFixed(1)}" r="${radius}" fill="${color}" />`
    }

    const rx = Math.max(1, Math.min(w, h) * 0.2).toFixed(1)
    return `<rect x="${(-w / 2).toFixed(1)}" y="${(-h / 2).toFixed(1)}" width="${w.toFixed(1)}" height="${h.toFixed(1)}" rx="${rx}" fill="${color}" transform="translate(${cx.toFixed(1)} ${cy.toFixed(1)}) rotate(${piece.rotation})" />`
  })

  return `<g aria-hidden="true">${shapes.join('')}</g>`
}

export type CoverMilestoneConfettiDomPiece = {
  left: number
  top: number
  width: number
  height: number
  rotation: number
  backgroundColor: string
  borderRadius: string
}

export function getCoverMilestoneConfettiDomPieces(
  width: number,
  height: number,
  family: CoverThemeFamily,
): CoverMilestoneConfettiDomPiece[] {
  if (width <= 0 || height <= 0) return []

  const scaleX = width / BASE_WIDTH
  const scaleY = height / BASE_HEIGHT
  const opacityScale = family === 'dark' ? 1.12 : 1

  return MILESTONE_CONFETTI_PIECES.map((piece) => {
    const rgb = getCoverBrandLightRgb(piece.tone)
    const opacity = Math.min(0.72, piece.opacity * opacityScale)
    const w = piece.w * scaleX
    const h = piece.h * scaleY
    const cx = piece.x * width
    const cy = piece.y * height

    return {
      left: cx - w / 2,
      top: cy - h / 2,
      width: w,
      height: h,
      rotation: piece.rotation,
      backgroundColor: rgba(rgb, opacity),
      borderRadius: piece.shape === 'circle' ? '9999px' : `${Math.min(w, h) * 0.2}px`,
    }
  })
}
