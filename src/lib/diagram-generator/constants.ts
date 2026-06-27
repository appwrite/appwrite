import {
  COVER_HEIGHT,
  COVER_SIZE_PRESETS,
  COVER_WIDTH,
} from '@/lib/cover-generator/constants'

export const DIAGRAM_SIZE_PRESETS = COVER_SIZE_PRESETS

export const DIAGRAM_DEFAULT_WIDTH = COVER_WIDTH
export const DIAGRAM_DEFAULT_HEIGHT = COVER_HEIGHT

/** Display artboard width in the generator canvas at 100% zoom. */
export const DIAGRAM_ARTBOARD_DISPLAY_WIDTH = 720

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
