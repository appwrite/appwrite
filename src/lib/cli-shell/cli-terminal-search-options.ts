import type { ISearchOptions } from '@xterm/addon-search'
import { isResolvedThemeDarkChrome } from '@/lib/html-theme'

/** xterm search decorations require 6-digit `#RRGGBB` colors (no alpha channel). */
export function getCliTerminalSearchOptions(
  resolvedTheme: string | undefined,
  caseSensitive = false,
): ISearchOptions {
  const isDark = isResolvedThemeDarkChrome(resolvedTheme)

  const matchBackground = isDark ? '#334155' : '#dbeafe'
  const activeMatchBackground = isDark ? '#1d4ed8' : '#3b82f6'
  const matchBorder = isDark ? '#64748b' : '#93c5fd'
  const activeMatchBorder = isDark ? '#93c5fd' : '#1d4ed8'
  const overviewRuler = isDark ? '#60a5fa' : '#2563eb'

  return {
    caseSensitive,
    decorations: {
      matchBackground,
      matchBorder,
      matchOverviewRuler: overviewRuler,
      activeMatchBackground,
      activeMatchBorder,
      activeMatchColorOverviewRuler: overviewRuler,
    },
  }
}
