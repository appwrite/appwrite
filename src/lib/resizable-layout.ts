/**
 * Helpers for `react-resizable-panels`, which only accepts % sizes.
 * Define layout in px, convert at runtime from a measured container width.
 */

/** Reference width when migrating legacy sidebar prefs stored as percent (5–60). */
export const LEGACY_SIDEBAR_PERCENT_REFERENCE_WIDTH_PX = 1280

/** Table / storage workspace left nav (`TableViewResizableLayout`). */
export const TABLE_VIEW_SIDEBAR_MIN_WIDTH_PX = 224
export const TABLE_VIEW_SIDEBAR_MAX_WIDTH_PX = 480
export const TABLE_VIEW_SIDEBAR_DEFAULT_WIDTH_PX = TABLE_VIEW_SIDEBAR_MIN_WIDTH_PX
export const TABLE_VIEW_MAIN_MIN_WIDTH_PX = 360

/** Functions local editor explorer split. */
export const FUNCTIONS_EDITOR_EXPLORER_MIN_WIDTH_PX = 200
export const FUNCTIONS_EDITOR_EXPLORER_MAX_WIDTH_PX = 480
export const FUNCTIONS_EDITOR_EXPLORER_DEFAULT_WIDTH_PX = 280
export const FUNCTIONS_EDITOR_MAIN_MIN_WIDTH_PX = 400

/** CLI terminal: main output area | sessions list (right). */
export const CLI_SHELL_SESSIONS_SIDEBAR_MIN_WIDTH_PX = 176
export const CLI_SHELL_SESSIONS_SIDEBAR_MAX_WIDTH_PX = 320
export const CLI_SHELL_SESSIONS_SIDEBAR_DEFAULT_WIDTH_PX = 208
export const CLI_SHELL_TERMINAL_MAIN_MIN_WIDTH_PX = 240
/** Below this width, sessions render as a horizontal strip above output instead of a right sidebar. */
export const CLI_SHELL_SESSIONS_STRIP_MAX_WIDTH_PX = 640

/** AI assistant fullscreen: conversations list | chat. */
export const AI_CHAT_CONVERSATIONS_SIDEBAR_MIN_WIDTH_PX = 220
export const AI_CHAT_CONVERSATIONS_SIDEBAR_MAX_WIDTH_PX = 420
export const AI_CHAT_CONVERSATIONS_SIDEBAR_DEFAULT_WIDTH_PX = 288
export const AI_CHAT_MAIN_MIN_WIDTH_PX = 360

const LEGACY_SIDEBAR_PERCENT_MAX = 60

/** Use when the group has not been measured yet so % ↔ px math stays consistent. */
export function effectivePanelGroupWidthPx(measuredWidth: number): number {
  return measuredWidth > 0
    ? measuredWidth
    : LEGACY_SIDEBAR_PERCENT_REFERENCE_WIDTH_PX
}

export function panelPercentFromPx(
  px: number,
  containerWidth: number,
  fallbackPercent = 0,
): number {
  const w = effectivePanelGroupWidthPx(containerWidth)
  if (w <= 0) return fallbackPercent
  return Math.min(100, (px / w) * 100)
}

export function panelPxFromPercent(
  percent: number,
  containerWidth: number,
): number {
  const w = effectivePanelGroupWidthPx(containerWidth)
  if (w <= 0) return 0
  return Math.round((percent / 100) * w)
}

/**
 * Clamps the first (left) pane in a horizontal split so it respects min/max px
 * and leaves at least `secondMinPx` for the right pane.
 */
export function clampSplitFirstPaneWidthPx(
  widthPx: number,
  containerWidth: number,
  firstMinPx: number,
  firstMaxPx: number,
  secondMinPx: number,
): number {
  const w = effectivePanelGroupWidthPx(containerWidth)
  const maxFirstPx = Math.min(firstMaxPx, Math.max(firstMinPx, w - secondMinPx))
  return Math.min(maxFirstPx, Math.max(firstMinPx, Math.round(widthPx)))
}

/**
 * When a split's container shrinks (e.g. outer sidebar resize), only shrink the
 * first pane if it no longer fits. Do not snap toward min when the container
 * grows - that keeps nested splits independent from sibling resizes.
 */
export function fitSplitFirstPaneWidthOnContainerResize(
  currentWidthPx: number,
  containerWidth: number,
  firstMinPx: number,
  firstMaxPx: number,
  secondMinPx: number,
): number {
  const w = effectivePanelGroupWidthPx(containerWidth)
  const maxFirstPx = Math.min(firstMaxPx, Math.max(firstMinPx, w - secondMinPx))
  const current = Math.round(currentWidthPx)
  if (current > maxFirstPx) return maxFirstPx
  return current
}

export type TwoPanelHorizontalLayout = {
  firstPercent: number
  secondPercent: number
  firstMinPercent: number
  firstMaxPercent: number
  secondMinPercent: number
  firstPx: number
}

/**
 * Derives `react-resizable-panels` % sizes from px constraints. Ensures mins fit
 * in the group (sidebar + main cannot require more than 100%).
 */
type TwoPanelHorizontalConstraints = {
  firstMinPx: number
  firstMaxPx: number
  secondMinPx: number
}

/**
 * Keeps the first pane at a stable px width when the panel group container
 * resizes (e.g. devtools open/close). Optionally imperatively resizes the panel.
 */
export function syncPanelGroupFirstPanePx(
  panel: { getSize: () => number; resize: (size: number) => void } | null | undefined,
  containerWidth: number,
  firstPx: number,
  constraints: TwoPanelHorizontalConstraints,
): number {
  if (containerWidth <= 0) return firstPx

  const fittedFirstPx = fitSplitFirstPaneWidthOnContainerResize(
    firstPx,
    containerWidth,
    constraints.firstMinPx,
    constraints.firstMaxPx,
    constraints.secondMinPx,
  )
  const layout = computeTwoPanelHorizontalLayout({
    containerWidth,
    firstPx: fittedFirstPx,
    ...constraints,
  })

  if (panel) {
    const currentSize = panel.getSize()
    if (Math.abs(currentSize - layout.firstPercent) > 0.5) {
      panel.resize(layout.firstPercent)
    }
  }

  return layout.firstPx
}

export function computeTwoPanelHorizontalLayout(input: {
  containerWidth: number
  firstPx: number
} & TwoPanelHorizontalConstraints): TwoPanelHorizontalLayout {
  const w = effectivePanelGroupWidthPx(input.containerWidth)
  const firstPx = clampSplitFirstPaneWidthPx(
    input.firstPx,
    w,
    input.firstMinPx,
    input.firstMaxPx,
    input.secondMinPx,
  )

  const secondMinPercent = panelPercentFromPx(input.secondMinPx, w)
  const firstMinPercent = panelPercentFromPx(input.firstMinPx, w)
  const firstMaxPercent = Math.min(
    panelPercentFromPx(input.firstMaxPx, w, 100),
    Math.max(firstMinPercent, 100 - secondMinPercent),
  )
  const effectiveFirstMinPercent = Math.min(
    firstMinPercent,
    Math.max(0, 100 - secondMinPercent),
  )

  let firstPercent = panelPercentFromPx(firstPx, w, effectiveFirstMinPercent)
  firstPercent = Math.min(
    firstMaxPercent,
    Math.max(effectiveFirstMinPercent, firstPercent),
  )

  return {
    firstPercent,
    secondPercent: 100 - firstPercent,
    firstMinPercent: effectiveFirstMinPercent,
    firstMaxPercent,
    secondMinPercent,
    firstPx,
  }
}

export function clampTableViewSidebarWidthPx(px: number): number {
  return Math.min(
    TABLE_VIEW_SIDEBAR_MAX_WIDTH_PX,
    Math.max(TABLE_VIEW_SIDEBAR_MIN_WIDTH_PX, Math.round(px)),
  )
}

export function clampCliShellSessionsSidebarWidthPx(px: number): number {
  return Math.min(
    CLI_SHELL_SESSIONS_SIDEBAR_MAX_WIDTH_PX,
    Math.max(CLI_SHELL_SESSIONS_SIDEBAR_MIN_WIDTH_PX, Math.round(px)),
  )
}

export function clampAIChatConversationsSidebarWidthPx(px: number): number {
  return Math.min(
    AI_CHAT_CONVERSATIONS_SIDEBAR_MAX_WIDTH_PX,
    Math.max(AI_CHAT_CONVERSATIONS_SIDEBAR_MIN_WIDTH_PX, Math.round(px)),
  )
}

/** Postgres SQL workbench: resizable editor container height (toolbar + editor). */
export const POSTGRES_SQL_EDITOR_MIN_HEIGHT_PX = 120
export const POSTGRES_SQL_EDITOR_MAX_HEIGHT_PX = 720
export const POSTGRES_SQL_EDITOR_DEFAULT_HEIGHT_PX = 220
export const POSTGRES_SQL_RESULTS_MIN_HEIGHT_PX = 160

export function clampPostgresSqlEditorHeightPx(px: number): number {
  return Math.min(
    POSTGRES_SQL_EDITOR_MAX_HEIGHT_PX,
    Math.max(POSTGRES_SQL_EDITOR_MIN_HEIGHT_PX, Math.round(px)),
  )
}

/** MySQL SQL workbench: same height bounds as Postgres (shared UX, separate prefs). */
export const MYSQL_SQL_EDITOR_MIN_HEIGHT_PX = POSTGRES_SQL_EDITOR_MIN_HEIGHT_PX
export const MYSQL_SQL_EDITOR_MAX_HEIGHT_PX = POSTGRES_SQL_EDITOR_MAX_HEIGHT_PX
export const MYSQL_SQL_EDITOR_DEFAULT_HEIGHT_PX =
  POSTGRES_SQL_EDITOR_DEFAULT_HEIGHT_PX
export const MYSQL_SQL_RESULTS_MIN_HEIGHT_PX = POSTGRES_SQL_RESULTS_MIN_HEIGHT_PX

export function clampMysqlSqlEditorHeightPx(px: number): number {
  return clampPostgresSqlEditorHeightPx(px)
}

/** Cover generator: templates | canvas | properties (%). */
export const COVER_GENERATOR_COLUMNS_DEFAULT_LAYOUT = [22, 53, 25] as const
export const COVER_GENERATOR_COLUMNS_MIN = [16, 28, 19] as const
export const COVER_GENERATOR_COLUMNS_MAX = [38, 100, 38] as const

/** API Explorer: services | methods | request (%). */
export const API_EXPLORER_COLUMNS_DEFAULT_LAYOUT = [20, 24, 56] as const
export const API_EXPLORER_COLUMNS_MIN = [14, 18, 36] as const
export const API_EXPLORER_COLUMNS_MAX = [28, 34, 100] as const

/** API Explorer: request form | response (%). */
export const API_EXPLORER_RESPONSE_SPLIT_DEFAULT_LAYOUT = [58, 42] as const
export const API_EXPLORER_RESPONSE_SPLIT_MIN = [20, 15] as const
export const API_EXPLORER_RESPONSE_SPLIT_MAX = [85, 75] as const

/** Docs API reference: methods + request columns (explorer columns 2 and 3 proportions). */
export const API_REFERENCE_COLUMNS_DEFAULT_LAYOUT = [24, 76] as const
export const API_REFERENCE_COLUMNS_MIN = [18, 36] as const
export const API_REFERENCE_COLUMNS_MAX = [34, 100] as const

/** Diagram generator: properties | layers (%). */
export const DIAGRAM_GENERATOR_PROPERTIES_SPLIT_DEFAULT_LAYOUT = [62, 38] as const
export const DIAGRAM_GENERATOR_PROPERTIES_SPLIT_MIN = [22, 18] as const
export const DIAGRAM_GENERATOR_PROPERTIES_SPLIT_MAX = [82, 78] as const

function normalizePanelLayout(
  sizes: number[],
  mins: readonly number[],
  maxs: readonly number[],
  fallback: readonly number[],
): number[] {
  if (sizes.length !== mins.length) return [...fallback]
  const clamped = sizes.map((size, index) => {
    if (typeof size !== 'number' || !Number.isFinite(size)) {
      return fallback[index] ?? 0
    }
    const min = mins[index] ?? 0
    const max = maxs[index] ?? 100
    return Math.min(max, Math.max(min, size))
  })
  const sum = clamped.reduce((total, size) => total + size, 0)
  if (sum <= 0) return [...fallback]
  if (Math.abs(sum - 100) < 0.01) return clamped
  return clamped.map((size) => (size / sum) * 100)
}

export function normalizeCoverGeneratorColumnsLayout(sizes: number[]): number[] {
  return normalizePanelLayout(
    sizes,
    COVER_GENERATOR_COLUMNS_MIN,
    COVER_GENERATOR_COLUMNS_MAX,
    COVER_GENERATOR_COLUMNS_DEFAULT_LAYOUT,
  )
}

export function normalizeApiExplorerColumnsLayout(sizes: number[]): number[] {
  return normalizePanelLayout(
    sizes,
    API_EXPLORER_COLUMNS_MIN,
    API_EXPLORER_COLUMNS_MAX,
    API_EXPLORER_COLUMNS_DEFAULT_LAYOUT,
  )
}

export function normalizeApiExplorerResponseSplitLayout(
  sizes: number[],
): number[] {
  return normalizePanelLayout(
    sizes,
    API_EXPLORER_RESPONSE_SPLIT_MIN,
    API_EXPLORER_RESPONSE_SPLIT_MAX,
    API_EXPLORER_RESPONSE_SPLIT_DEFAULT_LAYOUT,
  )
}

export function normalizeApiReferenceColumnsLayout(sizes: number[]): number[] {
  return normalizePanelLayout(
    sizes,
    API_REFERENCE_COLUMNS_MIN,
    API_REFERENCE_COLUMNS_MAX,
    API_REFERENCE_COLUMNS_DEFAULT_LAYOUT,
  )
}

export function normalizeDiagramGeneratorPropertiesSplitLayout(
  sizes: number[],
): number[] {
  return normalizePanelLayout(
    sizes,
    DIAGRAM_GENERATOR_PROPERTIES_SPLIT_MIN,
    DIAGRAM_GENERATOR_PROPERTIES_SPLIT_MAX,
    DIAGRAM_GENERATOR_PROPERTIES_SPLIT_DEFAULT_LAYOUT,
  )
}

/**
 * Values below {@link TABLE_VIEW_SIDEBAR_MIN_WIDTH_PX} in account prefs were
 * stored as percent (typically 5–60). Convert using a fixed reference width.
 */
export function normalizeLegacySidebarWidthPrefValue(raw: number): number {
  if (
    raw > 0 &&
    raw <= LEGACY_SIDEBAR_PERCENT_MAX &&
    raw < TABLE_VIEW_SIDEBAR_MIN_WIDTH_PX
  ) {
    return clampTableViewSidebarWidthPx(
      (raw / 100) * LEGACY_SIDEBAR_PERCENT_REFERENCE_WIDTH_PX,
    )
  }
  return clampTableViewSidebarWidthPx(raw)
}
