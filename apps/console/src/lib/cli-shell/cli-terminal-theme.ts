import type { ITheme } from '@xterm/xterm'
import { isResolvedThemeDarkChrome } from '@/lib/html-theme'

function rgbCssToHex(rgb: string): string | null {
  const m = rgb.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/)
  if (!m) return null
  return (
    '#' +
    [Number(m[1]), Number(m[2]), Number(m[3])]
      .map((x) => x.toString(16).padStart(2, '0'))
      .join('')
  )
}

function cssVarToHex(
  varName: string,
  kind: 'background' | 'foreground' | 'border',
  fallback: string,
): string {
  if (typeof document === 'undefined') return fallback

  const el = document.createElement('div')
  if (kind === 'background') {
    el.style.cssText = `position:absolute;left:-9999px;width:1px;height:1px;background:var(${varName})`
  } else if (kind === 'foreground') {
    el.style.cssText = `position:absolute;left:-9999px;color:var(${varName})`
  } else {
    el.style.cssText = `position:absolute;left:-9999px;border:1px solid var(${varName})`
  }

  document.documentElement.appendChild(el)
  const style = getComputedStyle(el)
  const raw =
    kind === 'background'
      ? style.backgroundColor
      : kind === 'foreground'
        ? style.color
        : style.borderColor
  document.documentElement.removeChild(el)

  if (!raw || raw === 'transparent') return fallback
  const hex = rgbCssToHex(raw)
  return hex && /^#[0-9a-fA-F]{6}$/.test(hex) ? hex : fallback
}

/** xterm theme aligned with Console CSS variables and the active color scheme. */
export function getCliTerminalTheme(
  resolvedTheme: string | undefined,
): ITheme {
  const isDark = isResolvedThemeDarkChrome(resolvedTheme)

  const background = cssVarToHex(
    '--background',
    'background',
    isDark ? '#19191c' : '#ffffff',
  )
  const foreground = cssVarToHex(
    '--foreground',
    'foreground',
    isDark ? '#fafafa' : '#18181b',
  )
  const mutedForeground = cssVarToHex(
    '--muted-foreground',
    'foreground',
    isDark ? '#a1a1aa' : '#71717a',
  )
  const primary = cssVarToHex('--primary', 'foreground', '#e91e63')
  const destructive = cssVarToHex('--destructive', 'foreground', '#ef4444')
  return {
    background,
    foreground,
    cursor: foreground,
    cursorAccent: background,
    selectionBackground: isDark ? '#3f3f4666' : '#e4e4e7b3',
    selectionForeground: foreground,
    black: isDark ? '#27272a' : '#f4f4f5',
    red: destructive,
    green: isDark ? '#4ade80' : '#16a34a',
    yellow: isDark ? '#facc15' : '#ca8a04',
    blue: isDark ? '#60a5fa' : '#2563eb',
    magenta: primary,
    cyan: isDark ? '#22d3ee' : '#0891b2',
    white: foreground,
    brightBlack: mutedForeground,
    brightRed: destructive,
    brightGreen: isDark ? '#86efac' : '#22c55e',
    brightYellow: isDark ? '#fde047' : '#eab308',
    brightBlue: isDark ? '#93c5fd' : '#3b82f6',
    brightMagenta: primary,
    brightCyan: isDark ? '#67e8f9' : '#06b6d4',
    brightWhite: foreground,
  }
}
