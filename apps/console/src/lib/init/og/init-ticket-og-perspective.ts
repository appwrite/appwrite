import {
  INIT_TICKET_IMAGE_HEIGHT,
  INIT_TICKET_IMAGE_WIDTH,
} from '@/lib/init/ticket-layout'

/** Matches InitTicketCard perspective + a natural idle tilt on /init. */
export const INIT_TICKET_OG_3D = {
  rotateX: 6,
  rotateY: 14,
  perspective: 1000,
} as const

export type InitTicketOg3DConfig = typeof INIT_TICKET_OG_3D

type Vec3 = { x: number; y: number; z: number }
type Point = { x: number; y: number }

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
): Point {
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

/** CSS applies rotateY first, then rotateX (rotateX() rotateY()). */
export function computeInitTicketOgProjectedQuad(
  width: number,
  height: number,
  config: InitTicketOg3DConfig = INIT_TICKET_OG_3D,
): [Point, Point, Point, Point] {
  const centerX = width / 2
  const centerY = height / 2
  const halfW = width / 2
  const halfH = height / 2
  const rx = degToRad(config.rotateX)
  const ry = degToRad(config.rotateY)

  const corners: Vec3[] = [
    { x: -halfW, y: -halfH, z: 0 },
    { x: halfW, y: -halfH, z: 0 },
    { x: halfW, y: halfH, z: 0 },
    { x: -halfW, y: halfH, z: 0 },
  ]

  const quad = corners.map((corner) => {
    const rotated = rotateX(rotateY(corner, ry), rx)
    return projectPoint(rotated, config.perspective, centerX, centerY)
  }) as [Point, Point, Point, Point]

  return centerInitTicketOgQuadInCanvas(quad, width, height)
}

function centerInitTicketOgQuadInCanvas(
  quad: [Point, Point, Point, Point],
  width: number,
  height: number,
): [Point, Point, Point, Point] {
  const xs = quad.map((point) => point.x)
  const ys = quad.map((point) => point.y)
  const bboxCenterX = (Math.min(...xs) + Math.max(...xs)) / 2
  const bboxCenterY = (Math.min(...ys) + Math.max(...ys)) / 2
  const dx = width / 2 - bboxCenterX
  const dy = height / 2 - bboxCenterY

  return quad.map((point) => ({
    x: point.x + dx,
    y: point.y + dy,
  })) as [Point, Point, Point, Point]
}

export function initTicketOgShadowPlacement(
  width: number,
  height: number,
  config: InitTicketOg3DConfig = INIT_TICKET_OG_3D,
) {
  const [,, br, bl] = computeInitTicketOgProjectedQuad(width, height, config)
  const anchorX = (br.x + bl.x) / 2
  const anchorY = Math.max(br.y, bl.y)
  const shadowW = Math.round(width * 0.62)
  const shadowH = Math.round(height * 0.12)

  return {
    left: Math.round(anchorX - shadowW / 2),
    top: Math.round(anchorY - shadowH * 0.35),
    width: shadowW,
    height: shadowH,
  }
}

function solveLinearSystem(matrix: number[][], values: number[]): number[] {
  const size = values.length
  const augmented = matrix.map((row, index) => [...row, values[index]])

  for (let column = 0; column < size; column++) {
    let pivotRow = column
    for (let row = column + 1; row < size; row++) {
      if (Math.abs(augmented[row][column]) > Math.abs(augmented[pivotRow][column])) {
        pivotRow = row
      }
    }

    const pivot = augmented[pivotRow]
    augmented[pivotRow] = augmented[column]
    augmented[column] = pivot

    const pivotValue = augmented[column][column]
    if (Math.abs(pivotValue) < 1e-8) continue

    for (let row = 0; row < size; row++) {
      if (row === column) continue
      const factor = augmented[row][column] / pivotValue
      for (let col = column; col <= size; col++) {
        augmented[row][col] -= factor * augmented[column][col]
      }
    }
  }

  return augmented.map((row, index) => row[size] / (row[index] || 1))
}

/** Homography mapping the source rectangle to the projected quad. */
function computeRectToQuadHomography(
  width: number,
  height: number,
  quad: [Point, Point, Point, Point],
): number[] {
  const [tl, tr, br, bl] = quad
  const src = [
    { x: 0, y: 0, u: tl.x, v: tl.y },
    { x: width, y: 0, u: tr.x, v: tr.y },
    { x: width, y: height, u: br.x, v: br.y },
    { x: 0, y: height, u: bl.x, v: bl.y },
  ]

  const matrix: number[][] = []
  const values: number[] = []

  for (const point of src) {
    matrix.push([point.x, point.y, 1, 0, 0, 0, -point.u * point.x, -point.u * point.y])
    values.push(point.u)
    matrix.push([0, 0, 0, point.x, point.y, 1, -point.v * point.x, -point.v * point.y])
    values.push(point.v)
  }

  const [h1, h2, h3, h4, h5, h6, h7, h8] = solveLinearSystem(matrix, values)
  return [h1, h2, h3, h4, h5, h6, h7, h8, 1]
}

function invertHomography3x3(matrix: number[]): number[] | null {
  const [a, b, c, d, e, f, g, h, i] = matrix
  const det =
    a * (e * i - f * h) - b * (d * i - f * g) + c * (d * h - e * g)
  if (Math.abs(det) < 1e-10) return null

  const invDet = 1 / det
  return [
    (e * i - f * h) * invDet,
    (c * h - b * i) * invDet,
    (b * f - c * e) * invDet,
    (f * g - d * i) * invDet,
    (a * i - c * g) * invDet,
    (c * d - a * f) * invDet,
    (d * h - e * g) * invDet,
    (b * g - a * h) * invDet,
    (a * e - b * d) * invDet,
  ]
}

function applyHomography(matrix: number[], x: number, y: number): Point | null {
  const [a, b, c, d, e, f, g, h, i] = matrix
  const denom = g * x + h * y + i
  if (Math.abs(denom) < 1e-8) return null
  return {
    x: (a * x + b * y + c) / denom,
    y: (d * x + e * y + f) / denom,
  }
}

function sampleBilinear(
  source: Uint8ClampedArray,
  width: number,
  height: number,
  x: number,
  y: number,
): [number, number, number, number] {
  const clampedX = Math.min(width - 1, Math.max(0, x))
  const clampedY = Math.min(height - 1, Math.max(0, y))
  const x0 = Math.floor(clampedX)
  const y0 = Math.floor(clampedY)
  const x1 = Math.min(width - 1, x0 + 1)
  const y1 = Math.min(height - 1, y0 + 1)
  const tx = clampedX - x0
  const ty = clampedY - y0

  const sample = (sx: number, sy: number) => {
    const index = (sy * width + sx) * 4
    return [
      source[index],
      source[index + 1],
      source[index + 2],
      source[index + 3],
    ] as const
  }

  const c00 = sample(x0, y0)
  const c10 = sample(x1, y0)
  const c01 = sample(x0, y1)
  const c11 = sample(x1, y1)

  const channel = (index: 0 | 1 | 2 | 3) =>
    c00[index] * (1 - tx) * (1 - ty) +
    c10[index] * tx * (1 - ty) +
    c01[index] * (1 - tx) * ty +
    c11[index] * tx * ty

  return [channel(0), channel(1), channel(2), channel(3)]
}

export async function warpInitTicketOgPerspective(
  input: Buffer,
  width = INIT_TICKET_IMAGE_WIDTH,
  height = INIT_TICKET_IMAGE_HEIGHT,
  config: InitTicketOg3DConfig = INIT_TICKET_OG_3D,
): Promise<Buffer> {
  const sharp = (await import('sharp')).default
  const { data, info } = await sharp(input)
    .ensureAlpha()
    .raw()
    .toBuffer({ resolveWithObject: true })

  const source = new Uint8ClampedArray(data)
  const sourceWidth = info.width
  const sourceHeight = info.height
  const quad = computeInitTicketOgProjectedQuad(width, height, config)
  const homography = computeRectToQuadHomography(sourceWidth, sourceHeight, quad)
  const inverse = invertHomography3x3(homography)
  if (!inverse) return input

  const output = new Uint8ClampedArray(width * height * 4)

  for (let y = 0; y < height; y++) {
    for (let x = 0; x < width; x++) {
      const mapped = applyHomography(inverse, x, y)
      const outIndex = (y * width + x) * 4
      if (!mapped) {
        output[outIndex + 3] = 0
        continue
      }

      const [r, g, b, a] = sampleBilinear(
        source,
        sourceWidth,
        sourceHeight,
        mapped.x,
        mapped.y,
      )
      output[outIndex] = r
      output[outIndex + 1] = g
      output[outIndex + 2] = b
      output[outIndex + 3] = a
    }
  }

  return sharp(Buffer.from(output), {
    raw: { width, height, channels: 4 },
  })
    .png()
    .toBuffer()
}
