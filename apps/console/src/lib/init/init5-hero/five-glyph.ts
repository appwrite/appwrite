/**
 * The Init 5 "5", traced from `AeonikPro-BlackItalic.woff2` (the face behind the
 * `init_` wordmark) at 1000px and simplified to 170 points (IoU 0.994 against the
 * rendered glyph).
 *
 * Units match `InitWordmark`: 100px font size, baseline at y = 0, y grows downward.
 */
export const INIT5_FIVE_GLYPH_PATH =
  'M13.4 -70L61.5 -70L59.24 -56L59.15 -55.6L27 -55.55L24.67 -44.6L25.9 -45.23L27.4 -46.03L29.1 -46.74L30.9 -47.32L32.2 -47.64L33.8 -47.93L35.6 -48.11L37.2 -48.15L38.4 -48.13L39.8 -48.03L41.5 -47.83L42.6 -47.62L43.8 -47.34L44.8 -47.04L46.4 -46.44L47.3 -46.03L48.1 -45.61L49.1 -45.03L50 -44.43L50.8 -43.83L51.5 -43.24L52.3 -42.5L52.9 -41.88L53.52 -41.2L54.24 -40.3L54.74 -39.6L55.43 -38.5L56.25 -36.9L56.63 -36L57.23 -34.2L57.64 -32.4L57.83 -31.2L57.94 -30.2L58.03 -28.9L58.05 -27.7L58.02 -26.2L57.93 -24.7L57.73 -23L57.54 -21.8L57.12 -19.9L56.84 -18.9L56.43 -17.6L55.93 -16.3L55.34 -15L54.72 -13.8L54.14 -12.8L53.13 -11.3L52.53 -10.5L51.44 -9.2L50.5 -8.22L49.4 -7.16L48.5 -6.37L47.5 -5.58L46.1 -4.57L45 -3.87L43.6 -3.08L42.4 -2.47L41 -1.86L39.7 -1.36L38.5 -0.97L37.1 -0.57L35.8 -0.26L34.3 0.03L32.9 0.23L31 0.43L29.4 0.53L26.3 0.53L24.7 0.44L23.5 0.33L21.9 0.13L20.7 -0.07L19.3 -0.38L17.5 -0.87L16.1 -1.36L15.1 -1.77L13.8 -2.37L12.9 -2.86L11.9 -3.46L11 -4.07L10.2 -4.67L9.1 -5.59L8.35 -6.3L7.58 -7.1L6.88 -7.9L5.96 -9.1L5.06 -10.5L4.57 -11.4L4.09 -12.4L3.67 -13.4L3.27 -14.6L2.97 -15.7L2.77 -16.6L2.57 -17.8L2.46 -18.7L2.37 -19.9L2.35 -20.9L2.37 -21.9L2.45 -22.9L19.7 -22.95L19.78 -21.5L19.87 -20.8L19.96 -20.3L20.17 -19.5L20.38 -18.9L20.86 -17.9L21.38 -17.1L21.86 -16.5L22.4 -15.95L23.2 -15.27L24.1 -14.67L25.3 -14.07L25.9 -13.87L27 -13.59L28 -13.49L28.8 -13.45L30 -13.5L30.6 -13.57L31.2 -13.66L32.5 -13.98L33.5 -14.35L34.4 -14.79L35.6 -15.56L36.2 -16.06L37.34 -17.2L38.04 -18.1L38.74 -19.2L39.24 -20.2L39.64 -21.2L40.02 -22.5L40.24 -23.6L40.4 -25L40.45 -26.3L40.42 -27.2L40.32 -28L40.13 -28.9L39.94 -29.5L39.55 -30.4L39.14 -31.1L38.61 -31.8L37.8 -32.63L36.9 -33.33L36.4 -33.62L35.6 -34.02L34.7 -34.32L33.8 -34.52L33.1 -34.62L32.2 -34.65L31.2 -34.63L30.3 -34.54L29.2 -34.33L28.8 -34.24L27.8 -33.92L27.1 -33.63L26 -33.03L25.1 -32.42L24.5 -31.92L23.58 -31L23.07 -30.4L22.49 -29.6L21.97 -28.8L21.54 -28L6.2 -28.37L4.4 -28.45L13.27 -69.7Z'

export const INIT5_FIVE_GLYPH_BOUNDS = {
  minX: 2.35,
  minY: -70,
  maxX: 61.65,
  maxY: 0.53,
} as const

/** Tight SVG viewBox around the glyph (with a hairline of padding for strokes). */
export const INIT5_FIVE_GLYPH_VIEWBOX = '1.35 -71 61.3 72.53'

let cachedPoints: Array<[number, number]> | null = null

/** The traced outline as `[x, y]` pairs in font units (y down). */
export function getInit5FiveGlyphPoints(): Array<[number, number]> {
  if (cachedPoints) return cachedPoints
  const points: Array<[number, number]> = []
  const pattern = /[ML](-?\d+(?:\.\d+)?) (-?\d+(?:\.\d+)?)/g
  for (const match of INIT5_FIVE_GLYPH_PATH.matchAll(pattern)) {
    points.push([Number(match[1]), Number(match[2])])
  }
  cachedPoints = points
  return points
}
