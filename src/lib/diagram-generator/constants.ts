import { COVER_HEIGHT, COVER_WIDTH } from '@/lib/cover-generator/constants'

export const DIAGRAM_SIZE_PRESETS = [
  { id: 'og', label: 'Open Graph', width: 1200, height: 630 },
  { id: 'blog', label: 'Blog post (16:9)', width: 1920, height: 1080 },
  { id: 'twitter', label: 'Twitter / X', width: 1600, height: 900 },
  { id: 'square', label: 'Square', width: 1080, height: 1080 },
  { id: 'large', label: 'Large diagram', width: 1920, height: 2048 },
] as const

export type DiagramSizePresetId = (typeof DIAGRAM_SIZE_PRESETS)[number]['id']

export function getDiagramSizePresetKey(width: number, height: number): string {
  const match = DIAGRAM_SIZE_PRESETS.find(
    (preset) => preset.width === width && preset.height === height,
  )
  return match?.id ?? `${width}x${height}`
}

export function resolveDiagramSizePresetKey(key: string): { width: number; height: number } {
  const preset = DIAGRAM_SIZE_PRESETS.find((item) => item.id === key)
  if (preset) return { width: preset.width, height: preset.height }

  const [widthRaw, heightRaw] = key.split('x')
  const width = Number(widthRaw)
  const height = Number(heightRaw)
  if (
    Number.isFinite(width) &&
    Number.isFinite(height) &&
    width >= 320 &&
    width <= 4096 &&
    height >= 200 &&
    height <= 4096
  ) {
    return { width: Math.round(width), height: Math.round(height) }
  }

  return { width: COVER_WIDTH, height: COVER_HEIGHT }
}

export const DIAGRAM_DEFAULT_WIDTH = COVER_WIDTH
export const DIAGRAM_DEFAULT_HEIGHT = COVER_HEIGHT

/** Display artboard width in the generator canvas at 100% zoom. */
export const DIAGRAM_ARTBOARD_DISPLAY_WIDTH = 720

/** Scale from document coordinates to on-screen pixels at a given zoom level. */
export function getDiagramArtboardRenderScale(
  documentWidth: number,
  zoom = 1,
): number {
  return (DIAGRAM_ARTBOARD_DISPLAY_WIDTH / documentWidth) * zoom
}

export function getDiagramArtboardDisplaySize(
  documentWidth: number,
  documentHeight: number,
  zoom = 1,
): { width: number; height: number } {
  const scale = getDiagramArtboardRenderScale(documentWidth, zoom)
  return {
    width: Math.ceil(documentWidth * scale),
    height: Math.ceil(documentHeight * scale),
  }
}

export const DIAGRAM_SNAP_GRID = 16

export const DIAGRAM_EDGE_STUB = 28

/** Default icon for icon elements when none is selected. */
export const DEFAULT_DIAGRAM_ICON = '/icons/appwrite.svg'

export const DIAGRAM_NODE_DEFAULTS = {
  service: { width: 208, height: 76 },
  title: { width: 720, height: 72 },
  label: { width: 168, height: 36 },
  group: { width: 360, height: 220 },
  icon: { width: 72, height: 72 },
  screenshot: { width: 320, height: 200 },
  table: { width: 320, height: 200 },
} as const

export const DIAGRAM_NODE_KIND_LABELS: Record<
  import('@/lib/diagram-generator/types').DiagramNodeKind,
  string
> = {
  service: 'Element',
  title: 'Title',
  label: 'Label',
  group: 'Group',
  icon: 'Icon',
  screenshot: 'Screenshot',
  table: 'Table',
}

export const DIAGRAM_STORAGE_KEY = 'console.diagramGenerator.state'
