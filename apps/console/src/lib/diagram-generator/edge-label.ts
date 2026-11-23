/** Approximate Inter Medium glyph widths at 12px for edge label pills. */
function estimateDiagramEdgeLabelTextWidth(label: string): number {
  let width = 0
  for (const char of label) {
    if (/[MW@]/.test(char)) width += 9
    else if (/[A-Z]/.test(char)) width += 7.4
    else if (/[mw]/.test(char)) width += 8
    else if (/[ilj1.'|:;!]/.test(char)) width += 3.4
    else if (char === ' ') width += 3.6
    else width += 6.5
  }
  return Math.ceil(width)
}

export type DiagramEdgeLabelMetrics = {
  width: number
  height: number
  rx: number
  /** Half-width; subtract from labelX for the pill's left edge. */
  offsetX: number
  /** Half-height; subtract from labelY for the pill's top edge. */
  offsetY: number
}

/** Sized pill for an edge label so text does not overflow the badge. */
export function getDiagramEdgeLabelMetrics(label: string): DiagramEdgeLabelMetrics {
  const paddingX = 12
  const height = 24
  const width = Math.max(44, estimateDiagramEdgeLabelTextWidth(label) + paddingX * 2)

  return {
    width,
    height,
    rx: height / 2,
    offsetX: width / 2,
    offsetY: height / 2,
  }
}
