export type DebugMenuPosition = {
  x: number
  y: number
}

type LegacyDebugMenuCorner =
  | 'bottom-right'
  | 'bottom-left'
  | 'top-right'
  | 'top-left'

export const DEBUG_MENU_POSITION_KEY = 'debug:menuPosition'
const LEGACY_DEBUG_MENU_CORNER_KEY = 'debug:menuCorner'

export const DEBUG_MENU_SIZE_PX = 44
export const DEBUG_MENU_EDGE_OFFSET_PX = 16

const LEGACY_CORNERS = new Set<LegacyDebugMenuCorner>([
  'bottom-right',
  'bottom-left',
  'top-right',
  'top-left',
])

function isLegacyDebugMenuCorner(value: string): value is LegacyDebugMenuCorner {
  return LEGACY_CORNERS.has(value as LegacyDebugMenuCorner)
}

function isFiniteNumber(value: unknown): value is number {
  return typeof value === 'number' && Number.isFinite(value)
}

export function getDefaultDebugMenuPosition(
  viewportWidth: number,
  viewportHeight: number,
): DebugMenuPosition {
  const half = DEBUG_MENU_SIZE_PX / 2
  return {
    x: viewportWidth - DEBUG_MENU_EDGE_OFFSET_PX - half,
    y: viewportHeight - DEBUG_MENU_EDGE_OFFSET_PX - half,
  }
}

export function clampDebugMenuPosition(
  position: DebugMenuPosition,
  viewportWidth: number,
  viewportHeight: number,
): DebugMenuPosition {
  const half = DEBUG_MENU_SIZE_PX / 2
  const minX = DEBUG_MENU_EDGE_OFFSET_PX + half
  const maxX = viewportWidth - DEBUG_MENU_EDGE_OFFSET_PX - half
  const minY = DEBUG_MENU_EDGE_OFFSET_PX + half
  const maxY = viewportHeight - DEBUG_MENU_EDGE_OFFSET_PX - half

  return {
    x: Math.min(Math.max(position.x, minX), Math.max(minX, maxX)),
    y: Math.min(Math.max(position.y, minY), Math.max(minY, maxY)),
  }
}

function legacyCornerToPosition(
  corner: LegacyDebugMenuCorner,
  viewportWidth: number,
  viewportHeight: number,
): DebugMenuPosition {
  const half = DEBUG_MENU_SIZE_PX / 2
  const offset = DEBUG_MENU_EDGE_OFFSET_PX

  switch (corner) {
    case 'bottom-right':
      return {
        x: viewportWidth - offset - half,
        y: viewportHeight - offset - half,
      }
    case 'bottom-left':
      return { x: offset + half, y: viewportHeight - offset - half }
    case 'top-right':
      return { x: viewportWidth - offset - half, y: offset + half }
    case 'top-left':
      return { x: offset + half, y: offset + half }
  }
}

function parseStoredPosition(raw: string): DebugMenuPosition | null {
  try {
    const parsed = JSON.parse(raw) as { x?: unknown; y?: unknown }
    if (isFiniteNumber(parsed.x) && isFiniteNumber(parsed.y)) {
      return { x: parsed.x, y: parsed.y }
    }
  } catch {
    // ignore invalid JSON
  }
  return null
}

export function readDebugMenuPosition(
  viewportWidth = typeof window !== 'undefined' ? window.innerWidth : 0,
  viewportHeight = typeof window !== 'undefined' ? window.innerHeight : 0,
): DebugMenuPosition {
  const fallback = getDefaultDebugMenuPosition(
    viewportWidth || 1,
    viewportHeight || 1,
  )

  if (typeof window === 'undefined') return fallback

  try {
    const stored = localStorage.getItem(DEBUG_MENU_POSITION_KEY)
    if (stored) {
      const parsed = parseStoredPosition(stored)
      if (parsed) {
        return clampDebugMenuPosition(parsed, viewportWidth, viewportHeight)
      }
    }

    const legacyCorner = localStorage.getItem(LEGACY_DEBUG_MENU_CORNER_KEY)
    if (legacyCorner && isLegacyDebugMenuCorner(legacyCorner)) {
      const migrated = legacyCornerToPosition(
        legacyCorner,
        viewportWidth,
        viewportHeight,
      )
      writeDebugMenuPosition(migrated)
      localStorage.removeItem(LEGACY_DEBUG_MENU_CORNER_KEY)
      return migrated
    }
  } catch {
    // localStorage unavailable
  }

  return fallback
}

export function writeDebugMenuPosition(position: DebugMenuPosition): void {
  if (typeof window === 'undefined') return
  try {
    localStorage.setItem(DEBUG_MENU_POSITION_KEY, JSON.stringify(position))
  } catch {
    // localStorage unavailable
  }
}

export function getDebugMenuPopoverPlacement(
  position: DebugMenuPosition,
  viewportWidth: number,
  viewportHeight: number,
): {
  side: 'top' | 'bottom'
  align: 'start' | 'end'
} {
  const isBottomHalf = position.y > viewportHeight / 2
  const isRightHalf = position.x > viewportWidth / 2

  return {
    side: isBottomHalf ? 'top' : 'bottom',
    align: isRightHalf ? 'end' : 'start',
  }
}

export function getDebugMenuTooltipSide(
  position: DebugMenuPosition,
  viewportWidth: number,
): 'left' | 'right' {
  return position.x > viewportWidth / 2 ? 'left' : 'right'
}
