import type { editor } from 'monaco-editor'
import { monacoSyntaxHighlightRules } from '@/lib/code-syntax-theme'

const MONACO_THEME_PREFIX = 'app-console'
const EDITOR_SURFACE_CSS = 'var(--editor-bg, var(--background))'

const FALLBACK_DARK_BG = '#141416'
const FALLBACK_LIGHT_BG = '#ffffff'

const CONSOLE_THEME_CLASSES = [
  'light',
  'dark',
  'crazy',
  'stealth',
  'premium',
  'high-contrast',
  'barbie',
  'nineties',
  'legacy',
] as const

function rgbChannelsToHex(
  r: string,
  g: string,
  b: string,
  alpha?: string,
): string {
  const channels = [r, g, b].map((channel) =>
    Number(channel).toString(16).padStart(2, '0'),
  )
  if (alpha !== undefined) {
    const normalized = alpha.endsWith('%')
      ? Math.round((parseFloat(alpha) / 100) * 255)
      : Math.round(parseFloat(alpha) * 255)
    if (normalized < 255) {
      channels.push(normalized.toString(16).padStart(2, '0'))
    }
  }
  return `#${channels.join('')}`
}

/** Parse rgb()/rgba() strings returned by getComputedStyle (comma or space syntax). */
function parseRgbStringToHex(value: string): string | null {
  const trimmed = value.trim()
  if (/^#[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$/.test(trimmed)) {
    return trimmed.slice(0, 7)
  }

  const commaMatch = trimmed.match(
    /^rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+%?))?\)$/,
  )
  if (commaMatch) {
    return rgbChannelsToHex(
      commaMatch[1],
      commaMatch[2],
      commaMatch[3],
      commaMatch[4],
    )
  }

  const spaceMatch = trimmed.match(
    /^rgba?\((\d+)\s+(\d+)\s+(\d+)(?:\s*\/\s*([\d.]+%?))?\)$/,
  )
  if (spaceMatch) {
    return rgbChannelsToHex(
      spaceMatch[1],
      spaceMatch[2],
      spaceMatch[3],
      spaceMatch[4],
    )
  }

  return null
}

/**
 * Resolve a CSS color (hex, oklch, rgb, var()) to #rrggbb for Monaco.
 */
export function resolveCssColorToHex(
  cssValue: string,
  fallback: string,
): string {
  if (typeof document === 'undefined' || !cssValue?.trim()) return fallback

  const trimmed = cssValue.trim()
  const direct = parseRgbStringToHex(trimmed)
  if (direct) return direct

  const el = document.createElement('div')
  el.style.cssText =
    'position:absolute;left:-9999px;top:0;width:1px;height:1px;visibility:hidden;pointer-events:none;background:' +
    trimmed
  document.body.appendChild(el)
  const computed = getComputedStyle(el).backgroundColor
  document.body.removeChild(el)

  return parseRgbStringToHex(computed) ?? fallback
}

function isTransparentCssColor(value: string): boolean {
  return (
    !value ||
    value === 'transparent' ||
    value === 'rgba(0, 0, 0, 0)' ||
    value === 'rgba(0,0,0,0)'
  )
}

function readComputedStyleColorHex(
  styleProperty: 'background' | 'color',
  cssValue: string,
  fallback: string,
): string {
  if (typeof document === 'undefined') return fallback

  const el = document.createElement('div')
  el.style.cssText =
    styleProperty === 'background'
      ? `position:absolute;left:-9999px;top:0;width:1px;height:1px;${styleProperty}:${cssValue}`
      : `position:absolute;left:-9999px;${styleProperty}:${cssValue}`

  document.documentElement.appendChild(el)
  const raw =
    styleProperty === 'background'
      ? getComputedStyle(el).backgroundColor
      : getComputedStyle(el).color
  document.documentElement.removeChild(el)

  if (isTransparentCssColor(raw)) return fallback
  return resolveCssColorToHex(raw, fallback)
}

/** Read a theme token from :root and return a Monaco-friendly hex color. */
export function readThemeColorHex(
  varName: string,
  kind: 'background' | 'foreground',
  fallback: string,
): string {
  return readComputedStyleColorHex(
    kind === 'background' ? 'background' : 'color',
    `var(${varName})`,
    fallback,
  )
}

/** Same surface token as Postgres SQL chrome (`--editor-bg` with `--background` fallback). */
function editorSurfaceHex(isDarkChrome: boolean): string {
  const fallback = isDarkChrome ? FALLBACK_DARK_BG : FALLBACK_LIGHT_BG
  return readComputedStyleColorHex(
    'background',
    EDITOR_SURFACE_CSS,
    fallback,
  )
}

export function getEffectiveConsoleTheme(
  resolvedTheme: string | undefined,
): string {
  if (resolvedTheme && resolvedTheme !== 'system') return resolvedTheme
  if (typeof document === 'undefined') return 'light'

  const html = document.documentElement
  for (const themeClass of CONSOLE_THEME_CLASSES) {
    if (html.classList.contains(themeClass)) return themeClass
  }
  return 'light'
}

export function monacoAppThemeId(
  resolvedTheme: string | undefined,
  isDarkChrome: boolean,
): string {
  const themeName = getEffectiveConsoleTheme(resolvedTheme)
  const chrome = isDarkChrome ? 'dark' : 'light'
  return `${MONACO_THEME_PREFIX}-${chrome}-${themeName}`
}

/** Define the active Monaco theme from the current :root CSS variables. */
export function defineMonacoAppTheme(
  monaco: typeof import('monaco-editor'),
  resolvedTheme: string | undefined,
  isDarkChrome: boolean,
): void {
  const themeId = monacoAppThemeId(resolvedTheme, isDarkChrome)
  const surface = editorSurfaceHex(isDarkChrome)

  if (isDarkChrome) {
    const fgDark = readThemeColorHex('--foreground', 'foreground', '#fafafa')
    const mutedDark = readThemeColorHex(
      '--muted-foreground',
      'foreground',
      '#71717a',
    )
    const accentDark = readThemeColorHex('--accent', 'background', '#2d2d31')

    monaco.editor.defineTheme(themeId, {
      base: 'vs-dark',
      inherit: true,
      rules: monacoSyntaxHighlightRules(true),
      colors: {
        'editor.background': surface,
        'editorGutter.background': surface,
        'editor.foreground': fgDark,
        'editorLineNumber.foreground': mutedDark,
        'editorLineNumber.activeForeground': fgDark,
        'editorCursor.foreground': fgDark,
        'editor.selectionBackground': accentDark,
        'editorWidget.background': surface,
        'editorSuggestWidget.background': surface,
        'minimap.background': surface,
        'minimapGutter.background': surface,
      },
    })
    return
  }

  const fgLight = readThemeColorHex('--foreground', 'foreground', '#18181b')
  const mutedLight = readThemeColorHex(
    '--muted-foreground',
    'foreground',
    '#71717a',
  )
  const accentLight = readThemeColorHex('--accent', 'background', '#e5e7eb')

  monaco.editor.defineTheme(themeId, {
    base: 'vs',
    inherit: true,
    rules: monacoSyntaxHighlightRules(false),
    colors: {
      'editor.background': surface,
      'editorGutter.background': surface,
      'editor.foreground': fgLight,
      'editorLineNumber.foreground': mutedLight,
      'editorLineNumber.activeForeground': fgLight,
      'editorCursor.foreground': fgLight,
      'editor.selectionBackground': accentLight,
      'editorWidget.background': surface,
      'editorSuggestWidget.background': surface,
      'minimap.background': surface,
      'minimapGutter.background': surface,
    },
  })
}

export function applyMonacoAppTheme(
  monaco: typeof import('monaco-editor'),
  resolvedTheme: string | undefined,
  isDarkChrome: boolean,
): void {
  defineMonacoAppTheme(monaco, resolvedTheme, isDarkChrome)
  monaco.editor.setTheme(monacoAppThemeId(resolvedTheme, isDarkChrome))
}

/** Update an existing editor instance when the console theme changes. */
export function refreshMonacoEditorTheme(
  editorInstance: editor.IStandaloneCodeEditor | null,
  monaco: typeof import('monaco-editor'),
  resolvedTheme: string | undefined,
  isDarkChrome: boolean,
): void {
  const themeId = monacoAppThemeId(resolvedTheme, isDarkChrome)
  applyMonacoAppTheme(monaco, resolvedTheme, isDarkChrome)
  editorInstance?.updateOptions({ theme: themeId })
}
