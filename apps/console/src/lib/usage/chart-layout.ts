/** Y-axis width for usage/overview time-series charts - wide enough to avoid label clipping. */
export const USAGE_CHART_Y_AXIS_WIDTH = 48

/** Area chart margins - keep plot area flush with the container edges. */
export const USAGE_CHART_MARGIN = {
  top: 8,
  right: 0,
  left: 0,
  bottom: 0,
} as const

/** Inset first/last x-axis ticks so labels stay inside the plot without large side gutters. */
export const USAGE_CHART_X_AXIS_PADDING = {
  left: 8,
  right: 8,
} as const

/** ResponsiveContainer defaults for flex layouts - minWidth prevents under-fill in flex rows. */
export const USAGE_CHART_RESPONSIVE_CONTAINER_PROPS = {
  width: '100%' as const,
  height: '100%' as const,
  minWidth: 0,
  debounce: 150,
}

export const CHART_X_AXIS_DEFAULT_TICK = {
  fill: 'currentColor',
  fontSize: 10,
} as const

export const CHART_X_AXIS_DEFAULT_DY = 10
