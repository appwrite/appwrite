/** Sans-serif stack that renders reliably in Sharp/librsvg. */
export const COVER_SVG_EXPORT_FONT_FAMILY = 'Arial, Helvetica, sans-serif'

/** Distance from line box top to alphabetic baseline (Aeonik Pro caps). */
const COVER_SVG_CAP_HEIGHT = 0.82

/**
 * Convert layout Y (line box top, px from canvas top) to SVG text baseline.
 * layoutY values in templates are tops - not baselines.
 */
export function coverSvgTextBaseline(layoutY: number, fontSize: number): number {
  return Math.round(layoutY + fontSize * COVER_SVG_CAP_HEIGHT)
}

/** Vertically center a connector glyph in the logo row (local coords). */
export function coverSvgConnectorBaseline(fontSize: number): number {
  return Math.round(fontSize * 0.35)
}
