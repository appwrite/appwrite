export type DebugMenuPosition = {
  x: number
  y: number
}

type DebugMenuStoredPosition = {
  xRatio: number
  yRatio: number
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

const DEFAULT_STORED_POSITION: DebugMenuStoredPosition = {
  xRatio: 1,
  yRatio: 1,
}

function isLegacyDebugMenuCorner(value: string): value is LegacyDebugMenuCorner {
  return LEGACY_CORNERS.has(value as LegacyDebugMenuCorner)
}

function isFiniteNumber(value: unknown): value is number {
  return typeof value === 'number' && Number.isFinite(value)
}

function clampRatio(value: number): number {
  return Math.min(Math.max(value, 0), 1)
}

function getPositionBounds(
  viewportWidth: number,
  viewportHeight: number,
): {
  minX: number
  maxX: number
  minY: number
  maxY: number
} {
  const half = DEBUG_MENU_SIZE_PX / 2
  return {
    minX: DEBUG_MENU_EDGE_OFFSET_PX + half,
    maxX: viewportWidth - DEBUG_MENU_EDGE_OFFSET_PX - half,
    minY: DEBUG_MENU_EDGE_OFFSET_PX + half,
    maxY: viewportHeight - DEBUG_MENU_EDGE_OFFSET_PX - half,
  }
}

export function storedToPixelPosition(
  stored: DebugMenuStoredPosition,
  viewportWidth: number,
  viewportHeight: number,
): DebugMenuPosition {
  const { minX, maxX, minY, maxY } = getPositionBounds(
    viewportWidth,
    viewportHeight,
  )
  const xRatio = clampRatio(stored.xRatio)
  const yRatio = clampRatio(stored.yRatio)

  return {
    x: minX + xRatio * (maxX - minX),
    y: minY + yRatio * (maxY - minY),
  }
}

export function pixelToStoredPosition(
  position: DebugMenuPosition,
  viewportWidth: number,
  viewportHeight: number,
): DebugMenuStoredPosition {
  const clamped = clampDebugMenuPosition(
    position,
    viewportWidth,
    viewportHeight,
  )
  const { minX, maxX, minY, maxY } = getPositionBounds(
    viewportWidth,
    viewportHeight,
  )
  const rangeX = maxX - minX
  const rangeY = maxY - minY

  return {
    xRatio: rangeX > 0 ? (clamped.x - minX) / rangeX : 1,
    yRatio: rangeY > 0 ? (clamped.y - minY) / rangeY : 1,
  }
}

export function getDefaultDebugMenuPosition(
  viewportWidth: number,
  viewportHeight: number,
): DebugMenuPosition {
  return storedToPixelPosition(
    DEFAULT_STORED_POSITION,
    viewportWidth,
    viewportHeight,
  )
}

export function clampDebugMenuPosition(
  position: DebugMenuPosition,
  viewportWidth: number,
  viewportHeight: number,
): DebugMenuPosition {
  const { minX, maxX, minY, maxY } = getPositionBounds(
    viewportWidth,
    viewportHeight,
  )

  return {
    x: Math.min(Math.max(position.x, minX), Math.max(minX, maxX)),
    y: Math.min(Math.max(position.y, minY), Math.max(minY, maxY)),
  }
}

function legacyCornerToStored(corner: LegacyDebugMenuCorner): DebugMenuStoredPosition {
  switch (corner) {
    case 'bottom-right':
      return { xRatio: 1, yRatio: 1 }
    case 'bottom-left':
      return { xRatio: 0, yRatio: 1 }
    case 'top-right':
      return { xRatio: 1, yRatio: 0 }
    case 'top-left':
      return { xRatio: 0, yRatio: 0 }
  }
}

function parseStoredPosition(raw: string): DebugMenuStoredPosition | null {
  try {
    const parsed = JSON.parse(raw) as {
      x?: unknown
      y?: unknown
      xRatio?: unknown
      yRatio?: unknown
    }

    if (isFiniteNumber(parsed.xRatio) && isFiniteNumber(parsed.yRatio)) {
      return {
        xRatio: clampRatio(parsed.xRatio),
        yRatio: clampRatio(parsed.yRatio),
      }
    }
  } catch {
    // ignore invalid JSON
  }
  return null
}

function parseLegacyAbsolutePosition(
  raw: string,
  viewportWidth: number,
  viewportHeight: number,
): DebugMenuStoredPosition | null {
  try {
    const parsed = JSON.parse(raw) as { x?: unknown; y?: unknown }
    if (isFiniteNumber(parsed.x) && isFiniteNumber(parsed.y)) {
      return pixelToStoredPosition(
        { x: parsed.x, y: parsed.y },
        viewportWidth,
        viewportHeight,
      )
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
  const width = viewportWidth || 1
  const height = viewportHeight || 1
  const fallback = getDefaultDebugMenuPosition(width, height)

  if (typeof window === 'undefined') return fallback

  try {
    const stored = localStorage.getItem(DEBUG_MENU_POSITION_KEY)
    if (stored) {
      const parsed = parseStoredPosition(stored)
      if (parsed) {
        return storedToPixelPosition(parsed, width, height)
      }

      const migrated = parseLegacyAbsolutePosition(stored, width, height)
      if (migrated) {
        writeDebugMenuStoredPosition(migrated)
        return storedToPixelPosition(migrated, width, height)
      }
    }

    const legacyCorner = localStorage.getItem(LEGACY_DEBUG_MENU_CORNER_KEY)
    if (legacyCorner && isLegacyDebugMenuCorner(legacyCorner)) {
      const migrated = legacyCornerToStored(legacyCorner)
      writeDebugMenuStoredPosition(migrated)
      localStorage.removeItem(LEGACY_DEBUG_MENU_CORNER_KEY)
      return storedToPixelPosition(migrated, width, height)
    }
  } catch {
    // localStorage unavailable
  }

  return fallback
}

function writeDebugMenuStoredPosition(position: DebugMenuStoredPosition): void {
  if (typeof window === 'undefined') return
  try {
    localStorage.setItem(DEBUG_MENU_POSITION_KEY, JSON.stringify(position))
  } catch {
    // localStorage unavailable
  }
}

export function writeDebugMenuPosition(
  position: DebugMenuPosition,
  viewportWidth = typeof window !== 'undefined' ? window.innerWidth : 0,
  viewportHeight = typeof window !== 'undefined' ? window.innerHeight : 0,
): void {
  if (typeof window === 'undefined') return
  writeDebugMenuStoredPosition(
    pixelToStoredPosition(position, viewportWidth || 1, viewportHeight || 1),
  )
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
