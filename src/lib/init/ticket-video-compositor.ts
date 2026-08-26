import {
  INIT_TICKET_VIDEO_CSS_PERSPECTIVE,
  INIT_TICKET_VIDEO_DOT_SPACING_CSS_PX,
  INIT_TICKET_VIDEO_TICKET_WIDTH_RATIO,
  getInitTicketMotionForProgress,
  isInitTicketVideoShowingBack,
  resolveInitTicketVideoProjectionRotateY,
} from '@/lib/init/ticket-video-capture'
import { INIT_TICKET_MAX_WIDTH_PX } from '@/lib/init/ticket-layout'
import {
  initTicketDisplayAspectRatio,
  initTicketVideoSceneHeight,
  initTicketVideoTextureVMax,
  initTicketVideoVisibleBottomY,
  projectInitTicketPoint,
  projectInitTicketVisibleCornerDepths,
  projectInitTicketVisibleQuad,
  translateTicketVideoQuad,
  type TicketVideoPoint,
} from '@/lib/init/ticket-video-perspective'
import { createInitTicketVideoPerspectiveBlitter } from '@/lib/init/ticket-video-homography'

export type InitTicketVideoCompositorOptions = {
  ticketImage: CanvasImageSource
  ticketBackImage?: CanvasImageSource
  ticketWidth: number
  ticketHeight: number
  width: number
  height: number
  backgroundColor: string
  borderColor: string
  usesDarkChrome?: boolean
}

export type InitTicketVideoCompositor = {
  canvas: HTMLCanvasElement
  drawFrame: (progress: number) => void
  dispose: () => void
}

function parseRgba(color: string): [number, number, number, number] | null {
  const match = color.match(
    /rgba?\(\s*([\d.]+)[,\s]+([\d.]+)[,\s]+([\d.]+)(?:[,\s/]+([\d.]+))?\s*\)/i,
  )
  if (!match) return null
  const alpha = match[4] === undefined ? 1 : Number(match[4])
  return [Number(match[1]), Number(match[2]), Number(match[3]), Number.isNaN(alpha) ? 1 : alpha]
}

function withAlpha(color: string, alpha: number) {
  const rgba = parseRgba(color)
  if (!rgba) return color
  return `rgba(${rgba[0]}, ${rgba[1]}, ${rgba[2]}, ${alpha})`
}

function drawDotPattern(
  ctx: CanvasRenderingContext2D,
  width: number,
  height: number,
  color: string,
  spacing: number,
) {
  ctx.save()
  ctx.fillStyle = color
  const radius = Math.max(0.8, spacing / 18)
  for (let y = spacing / 2; y < height; y += spacing) {
    for (let x = spacing / 2; x < width; x += spacing) {
      ctx.beginPath()
      ctx.arc(x, y, radius, 0, Math.PI * 2)
      ctx.fill()
    }
  }
  ctx.restore()
}

function solveAffine(
  s0: TicketVideoPoint,
  s1: TicketVideoPoint,
  s2: TicketVideoPoint,
  d0: TicketVideoPoint,
  d1: TicketVideoPoint,
  d2: TicketVideoPoint,
): [number, number, number, number, number, number] | null {
  const denom =
    s0.x * (s1.y - s2.y) + s1.x * (s2.y - s0.y) + s2.x * (s0.y - s1.y)
  if (Math.abs(denom) < 1e-8) return null

  const a =
    (d0.x * (s1.y - s2.y) + d1.x * (s2.y - s0.y) + d2.x * (s0.y - s1.y)) / denom
  const c =
    (d0.x * (s2.x - s1.x) + d1.x * (s0.x - s2.x) + d2.x * (s1.x - s0.x)) / denom
  const e =
    (d0.x * (s1.x * s2.y - s2.x * s1.y) +
      d1.x * (s2.x * s0.y - s0.x * s2.y) +
      d2.x * (s0.x * s1.y - s1.x * s0.y)) /
    denom
  const b =
    (d0.y * (s1.y - s2.y) + d1.y * (s2.y - s0.y) + d2.y * (s0.y - s1.y)) / denom
  const d =
    (d0.y * (s2.x - s1.x) + d1.y * (s0.x - s2.x) + d2.y * (s1.x - s0.x)) / denom
  const f =
    (d0.y * (s1.x * s2.y - s2.x * s1.y) +
      d1.y * (s2.x * s0.y - s0.x * s2.y) +
      d2.y * (s0.x * s1.y - s1.x * s0.y)) /
    denom

  return [a, b, c, d, e, f]
}

function subdivideVisibleProjectedQuad(
  displayWidth: number,
  rotateX: number,
  rotateY: number,
  perspective: number,
  originX: number,
  originY: number,
  cols: number,
  rows: number,
) {
  const fullHeight = initTicketVideoSceneHeight(displayWidth)
  const bottomY = initTicketVideoVisibleBottomY(displayWidth)
  const points: TicketVideoPoint[][] = []
  for (let row = 0; row <= rows; row += 1) {
    const line: TicketVideoPoint[] = []
    for (let col = 0; col <= cols; col += 1) {
      const projected = projectInitTicketPoint(
        (col / cols) * displayWidth,
        (row / rows) * bottomY,
        displayWidth,
        fullHeight,
        rotateX,
        rotateY,
        perspective,
      )
      line.push({ x: projected.x + originX, y: projected.y + originY })
    }
    points.push(line)
  }
  return points
}

function meshSubdivisionForTilt(rotateX: number, rotateY: number) {
  const maxTilt = Math.max(Math.abs(rotateX), Math.abs(rotateY))
  if (maxTilt < 6) return { cols: 10, rows: 7 }
  if (maxTilt < 14) return { cols: 18, rows: 12 }
  return { cols: 28, rows: 18 }
}

function drawTexturedTriangle(
  ctx: CanvasRenderingContext2D,
  image: CanvasImageSource,
  s0: TicketVideoPoint,
  s1: TicketVideoPoint,
  s2: TicketVideoPoint,
  d0: TicketVideoPoint,
  d1: TicketVideoPoint,
  d2: TicketVideoPoint,
) {
  const transform = solveAffine(s0, s1, s2, d0, d1, d2)
  if (!transform) return

  ctx.save()
  ctx.beginPath()
  ctx.moveTo(d0.x, d0.y)
  ctx.lineTo(d1.x, d1.y)
  ctx.lineTo(d2.x, d2.y)
  ctx.closePath()
  ctx.clip()
  ctx.transform(...transform)
  ctx.drawImage(image, 0, 0)
  ctx.restore()
}

/** Fallback when WebGL is unavailable: dense affine mesh. */
function drawTicketWithMesh(
  ctx: CanvasRenderingContext2D,
  image: CanvasImageSource,
  imageWidth: number,
  imageHeight: number,
  rotateX: number,
  rotateY: number,
  perspective: number,
  originX: number,
  originY: number,
  localWidth: number,
) {
  const { cols, rows } = meshSubdivisionForTilt(rotateX, rotateY)
  const grid = subdivideVisibleProjectedQuad(
    localWidth,
    rotateX,
    rotateY,
    perspective,
    originX,
    originY,
    cols,
    rows,
  )
  const textureVMax = initTicketVideoTextureVMax()

  ctx.imageSmoothingEnabled = true
  ctx.imageSmoothingQuality = 'high'

  for (let row = 0; row < rows; row += 1) {
    for (let col = 0; col < cols; col += 1) {
      const s00 = {
        x: (col / cols) * imageWidth,
        y: (row / rows) * textureVMax * imageHeight,
      }
      const s10 = {
        x: ((col + 1) / cols) * imageWidth,
        y: (row / rows) * textureVMax * imageHeight,
      }
      const s11 = {
        x: ((col + 1) / cols) * imageWidth,
        y: ((row + 1) / rows) * textureVMax * imageHeight,
      }
      const s01 = {
        x: (col / cols) * imageWidth,
        y: ((row + 1) / rows) * textureVMax * imageHeight,
      }
      const d00 = grid[row][col]
      const d10 = grid[row][col + 1]
      const d11 = grid[row + 1][col + 1]
      const d01 = grid[row + 1][col]

      drawTexturedTriangle(ctx, image, s00, s10, s01, d00, d10, d01)
      drawTexturedTriangle(ctx, image, s10, s11, s01, d10, d11, d01)
    }
  }
}

function drawTicketWithPerspective(
  ctx: CanvasRenderingContext2D,
  image: CanvasImageSource,
  imageWidth: number,
  imageHeight: number,
  rotateX: number,
  rotateY: number,
  perspective: number,
  meshOriginX: number,
  meshOriginY: number,
  localWidth: number,
  quad: [TicketVideoPoint, TicketVideoPoint, TicketVideoPoint, TicketVideoPoint],
  depths: [number, number, number, number],
  textureVMax: number,
  perspectiveBlitter: ReturnType<typeof createInitTicketVideoPerspectiveBlitter>,
  face: 'front' | 'back',
) {
  if (perspectiveBlitter) {
    perspectiveBlitter.draw(ctx, quad, depths, textureVMax, face)
    return
  }

  drawTicketWithMesh(
    ctx,
    image,
    imageWidth,
    imageHeight,
    rotateX,
    rotateY,
    perspective,
    meshOriginX,
    meshOriginY,
    localWidth,
  )
}

function drawTicketShadow(
  ctx: CanvasRenderingContext2D,
  quad: [TicketVideoPoint, TicketVideoPoint, TicketVideoPoint, TicketVideoPoint],
  localWidth: number,
  localHeight: number,
  rotateX: number,
  isDark: boolean,
) {
  const [, , br, bl] = quad
  const anchorX = (br.x + bl.x) / 2
  const anchorY = Math.max(br.y, bl.y)
  const shadowW = localWidth * 0.62
  const shadowH = localHeight * 0.1
  const offsetY = Math.abs(rotateX) * 0.08

  ctx.save()
  ctx.fillStyle = isDark ? 'rgba(0,0,0,0.42)' : 'rgba(0,0,0,0.16)'
  ctx.filter = 'blur(28px)'
  ctx.beginPath()
  ctx.ellipse(
    anchorX,
    anchorY - shadowH * 0.2 + offsetY,
    shadowW / 2,
    shadowH / 2,
    0,
    0,
    Math.PI * 2,
  )
  ctx.fill()
  ctx.restore()
}

export function createInitTicketVideoCompositor({
  ticketImage,
  ticketBackImage,
  ticketWidth,
  ticketHeight,
  width,
  height,
  backgroundColor,
  borderColor,
  usesDarkChrome = true,
}: InitTicketVideoCompositorOptions): InitTicketVideoCompositor {
  const canvas = document.createElement('canvas')
  canvas.width = width
  canvas.height = height
  const ctx = canvas.getContext('2d', { alpha: false })
  if (!ctx) {
    throw new Error('Could not create ticket video canvas')
  }

  const maxTicketWidth = Math.round(width * INIT_TICKET_VIDEO_TICKET_WIDTH_RATIO)
  const displayAspect = initTicketDisplayAspectRatio()
  let localWidth = maxTicketWidth
  let localHeight = Math.round(localWidth / displayAspect)
  if (localHeight > height * 0.86) {
    localHeight = Math.round(height * 0.86)
    localWidth = Math.round(localHeight * displayAspect)
  }
  const originX = (width - localWidth) / 2
  const originY = (height - localHeight) / 2
  const perspective =
    INIT_TICKET_VIDEO_CSS_PERSPECTIVE * (localWidth / INIT_TICKET_MAX_WIDTH_PX)
  const textureVMax = initTicketVideoTextureVMax()
  const dotSpacing =
    INIT_TICKET_VIDEO_DOT_SPACING_CSS_PX * (width / INIT_TICKET_MAX_WIDTH_PX)
  const dotColor = withAlpha(borderColor, 1)
  const dotsCanvas = document.createElement('canvas')
  dotsCanvas.width = width
  dotsCanvas.height = height
  const dotsCtx = dotsCanvas.getContext('2d', { alpha: true })
  if (dotsCtx) {
    dotsCtx.clearRect(0, 0, width, height)
    drawDotPattern(dotsCtx, width, height, dotColor, dotSpacing)
  }

  const perspectiveBlitter = createInitTicketVideoPerspectiveBlitter(
    width,
    height,
    ticketImage,
    ticketBackImage,
  )

  const drawFrame = (progress: number) => {
    const motion = getInitTicketMotionForProgress(progress)
    const rotateY = motion.flipY + motion.y
    const showingBack = Boolean(ticketBackImage && isInitTicketVideoShowingBack(rotateY))
    const projectionRotateY = resolveInitTicketVideoProjectionRotateY(rotateY)
    const activeFace: 'front' | 'back' = showingBack ? 'back' : 'front'
    const activeImage = showingBack ? ticketBackImage! : ticketImage

    ctx.setTransform(1, 0, 0, 1, 0, 0)
    ctx.globalCompositeOperation = 'source-over'
    ctx.filter = 'none'
    ctx.shadowBlur = 0
    ctx.fillStyle = backgroundColor
    ctx.fillRect(0, 0, width, height)

    if (dotsCtx) {
      ctx.drawImage(dotsCanvas, 0, 0)
    } else {
      drawDotPattern(ctx, width, height, dotColor, dotSpacing)
    }

    const localQuad = projectInitTicketVisibleQuad(
      localWidth,
      motion.x,
      projectionRotateY,
      perspective,
    )
    const depths = projectInitTicketVisibleCornerDepths(
      localWidth,
      motion.x,
      projectionRotateY,
      perspective,
    )
    let quad = translateTicketVideoQuad(localQuad, originX, originY)
    const xs = quad.map((point) => point.x)
    const ys = quad.map((point) => point.y)
    const centerDx = width / 2 - (Math.min(...xs) + Math.max(...xs)) / 2
    const centerDy = height / 2 - (Math.min(...ys) + Math.max(...ys)) / 2
    quad = translateTicketVideoQuad(quad, centerDx, centerDy)
    drawTicketShadow(
      ctx,
      quad,
      localWidth,
      localHeight,
      motion.x,
      usesDarkChrome,
    )
    drawTicketWithPerspective(
      ctx,
      activeImage,
      ticketWidth,
      ticketHeight,
      motion.x,
      projectionRotateY,
      perspective,
      originX + centerDx,
      originY + centerDy,
      localWidth,
      quad,
      depths,
      textureVMax,
      perspectiveBlitter,
      activeFace,
    )
  }

  return {
    canvas,
    drawFrame,
    dispose: () => {
      perspectiveBlitter?.dispose()
      canvas.width = 0
      canvas.height = 0
      dotsCanvas.width = 0
      dotsCanvas.height = 0
    },
  }
}
