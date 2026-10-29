import {
  INIT_TICKET_ASPECT_RATIO,
  INIT_TICKET_BOTTOM_TRIM_PERCENT,
  initTicketDisplayAspectRatio,
} from '@/lib/init/ticket-layout'

export type TicketVideoPoint = { x: number; y: number }

export { initTicketDisplayAspectRatio }

type Vec3 = { x: number; y: number; z: number }

function degToRad(value: number) {
  return (value * Math.PI) / 180
}

function rotateY(point: Vec3, radians: number): Vec3 {
  const cos = Math.cos(radians)
  const sin = Math.sin(radians)
  return {
    x: point.x * cos + point.z * sin,
    y: point.y,
    z: -point.x * sin + point.z * cos,
  }
}

function rotateX(point: Vec3, radians: number): Vec3 {
  const cos = Math.cos(radians)
  const sin = Math.sin(radians)
  return {
    x: point.x,
    y: point.y * cos - point.z * sin,
    z: point.y * sin + point.z * cos,
  }
}

function projectPoint(
  point: Vec3,
  perspective: number,
  centerX: number,
  centerY: number,
): TicketVideoPoint {
  const depth = perspective - point.z
  if (depth <= 1) {
    return { x: centerX, y: centerY }
  }
  const scale = perspective / depth
  return {
    x: centerX + point.x * scale,
    y: centerY + point.y * scale,
  }
}

function rotateTicketPoint(
  localX: number,
  localY: number,
  width: number,
  height: number,
  rotateXDeg: number,
  rotateYDeg: number,
): Vec3 {
  const centerX = width / 2
  const centerY = height / 2
  const rx = degToRad(rotateXDeg)
  const ry = degToRad(rotateYDeg)
  return rotateX(
    rotateY({ x: localX - centerX, y: localY - centerY, z: 0 }, ry),
    rx,
  )
}

/** Depth term used for perspective-correct texture mapping (matches projectPoint). */
export function projectInitTicketCornerDepth(
  localX: number,
  localY: number,
  width: number,
  height: number,
  rotateXDeg: number,
  rotateYDeg: number,
  perspective: number,
): number {
  const rotated = rotateTicketPoint(
    localX,
    localY,
    width,
    height,
    rotateXDeg,
    rotateYDeg,
  )
  return Math.max(1, perspective - rotated.z)
}

export function projectInitTicketCornerDepths(
  width: number,
  height: number,
  rotateXDeg: number,
  rotateYDeg: number,
  perspective: number,
): [number, number, number, number] {
  return [
    projectInitTicketCornerDepth(0, 0, width, height, rotateXDeg, rotateYDeg, perspective),
    projectInitTicketCornerDepth(width, 0, width, height, rotateXDeg, rotateYDeg, perspective),
    projectInitTicketCornerDepth(width, height, width, height, rotateXDeg, rotateYDeg, perspective),
    projectInitTicketCornerDepth(0, height, width, height, rotateXDeg, rotateYDeg, perspective),
  ]
}

/**
 * Project the ticket rectangle with CSS-style `rotateX() rotateY()` and
 * `perspective`. Origin stays at the ticket center (no recentering).
 *
 * CSS applies rotateY first, then rotateX.
 */
export function projectInitTicketPoint(
  localX: number,
  localY: number,
  width: number,
  height: number,
  rotateXDeg: number,
  rotateYDeg: number,
  perspective: number,
): TicketVideoPoint {
  const centerX = width / 2
  const centerY = height / 2
  const rotated = rotateTicketPoint(
    localX,
    localY,
    width,
    height,
    rotateXDeg,
    rotateYDeg,
  )
  return projectPoint(rotated, perspective, centerX, centerY)
}

export function initTicketVideoSceneHeight(displayWidth: number) {
  return displayWidth / INIT_TICKET_ASPECT_RATIO
}

/** Y of the visible bottom edge in scene space (matches InitTicketCard clipPath). */
export function initTicketVideoVisibleBottomY(displayWidth: number) {
  const fullHeight = initTicketVideoSceneHeight(displayWidth)
  return fullHeight * (1 - INIT_TICKET_BOTTOM_TRIM_PERCENT / 100)
}

/** Bottom V in canvas/image space (0=top, 1=bottom of still). */
export function initTicketVideoTextureVMax() {
  return 1 - INIT_TICKET_BOTTOM_TRIM_PERCENT / 100
}

/** Bottom V in WebGL texture space (0=bottom, 1=top of image). */
export function initTicketVideoTextureVMin() {
  return INIT_TICKET_BOTTOM_TRIM_PERCENT / 100
}

export function projectInitTicketVisibleQuad(
  displayWidth: number,
  rotateXDeg: number,
  rotateYDeg: number,
  perspective: number,
): [TicketVideoPoint, TicketVideoPoint, TicketVideoPoint, TicketVideoPoint] {
  const fullHeight = initTicketVideoSceneHeight(displayWidth)
  const bottomY = initTicketVideoVisibleBottomY(displayWidth)
  return [
    projectInitTicketPoint(0, 0, displayWidth, fullHeight, rotateXDeg, rotateYDeg, perspective),
    projectInitTicketPoint(displayWidth, 0, displayWidth, fullHeight, rotateXDeg, rotateYDeg, perspective),
    projectInitTicketPoint(displayWidth, bottomY, displayWidth, fullHeight, rotateXDeg, rotateYDeg, perspective),
    projectInitTicketPoint(0, bottomY, displayWidth, fullHeight, rotateXDeg, rotateYDeg, perspective),
  ]
}

export function projectInitTicketVisibleCornerDepths(
  displayWidth: number,
  rotateXDeg: number,
  rotateYDeg: number,
  perspective: number,
): [number, number, number, number] {
  const fullHeight = initTicketVideoSceneHeight(displayWidth)
  const bottomY = initTicketVideoVisibleBottomY(displayWidth)
  return [
    projectInitTicketCornerDepth(0, 0, displayWidth, fullHeight, rotateXDeg, rotateYDeg, perspective),
    projectInitTicketCornerDepth(displayWidth, 0, displayWidth, fullHeight, rotateXDeg, rotateYDeg, perspective),
    projectInitTicketCornerDepth(displayWidth, bottomY, displayWidth, fullHeight, rotateXDeg, rotateYDeg, perspective),
    projectInitTicketCornerDepth(0, bottomY, displayWidth, fullHeight, rotateXDeg, rotateYDeg, perspective),
  ]
}

/** Keep the projected ticket centered as tilt shifts its bounding box. */
export function centerTicketVideoQuadInFrame(
  quad: [TicketVideoPoint, TicketVideoPoint, TicketVideoPoint, TicketVideoPoint],
  frameWidth: number,
  frameHeight: number,
): [TicketVideoPoint, TicketVideoPoint, TicketVideoPoint, TicketVideoPoint] {
  const xs = quad.map((point) => point.x)
  const ys = quad.map((point) => point.y)
  const dx = frameWidth / 2 - (Math.min(...xs) + Math.max(...xs)) / 2
  const dy = frameHeight / 2 - (Math.min(...ys) + Math.max(...ys)) / 2
  return translateTicketVideoQuad(quad, dx, dy)
}

export function projectInitTicketQuad(
  width: number,
  height: number,
  rotateXDeg: number,
  rotateYDeg: number,
  perspective: number,
): [TicketVideoPoint, TicketVideoPoint, TicketVideoPoint, TicketVideoPoint] {
  return [
    projectInitTicketPoint(0, 0, width, height, rotateXDeg, rotateYDeg, perspective),
    projectInitTicketPoint(width, 0, width, height, rotateXDeg, rotateYDeg, perspective),
    projectInitTicketPoint(width, height, width, height, rotateXDeg, rotateYDeg, perspective),
    projectInitTicketPoint(0, height, width, height, rotateXDeg, rotateYDeg, perspective),
  ]
}

export function translateTicketVideoQuad(
  quad: [TicketVideoPoint, TicketVideoPoint, TicketVideoPoint, TicketVideoPoint],
  dx: number,
  dy: number,
): [TicketVideoPoint, TicketVideoPoint, TicketVideoPoint, TicketVideoPoint] {
  return quad.map((point) => ({
    x: point.x + dx,
    y: point.y + dy,
  })) as [TicketVideoPoint, TicketVideoPoint, TicketVideoPoint, TicketVideoPoint]
}
