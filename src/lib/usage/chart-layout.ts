/** Y-axis width for usage/overview time-series charts — wide enough to avoid label clipping. */
export const USAGE_CHART_Y_AXIS_WIDTH = 64

/** Area chart margins — reserve space so x-axis labels are not clipped at the edges. */
export const USAGE_CHART_MARGIN = {
  top: 10,
  right: 16,
  left: 0,
  bottom: 0,
} as const

/** Inset end ticks so first/last x-axis labels stay inside the chart area. */
export const USAGE_CHART_X_AXIS_PADDING = {
  left: 4,
  right: 16,
} as const

export const CHART_X_AXIS_DEFAULT_TICK = {
  fill: 'currentColor',
  fontSize: 10,
} as const

export const CHART_X_AXIS_DEFAULT_DY = 10
