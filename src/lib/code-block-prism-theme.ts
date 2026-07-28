import type { CSSProperties } from 'react'
import type { PrismTheme } from 'prism-react-renderer'
import { themes } from 'prism-react-renderer'
import {
  getCodeSyntaxColors,
  prismSyntaxHighlightStyles,
} from '@/lib/code-syntax-theme'
import {
  isHtmlDarkChrome,
  isResolvedThemeDarkChrome,
} from '@/lib/html-theme'

const PRISM_BACKGROUND_KEYS = [
  'background',
  'backgroundColor',
  'backgroundImage',
  'backgroundSize',
  'backgroundPosition',
  'backgroundRepeat',
  'backdropFilter',
] as const

/** Tailwind classes applied to every CodeBlock <pre> (prism-code) - no inner frame. */
export const CODE_BLOCK_PRISM_SURFACE_CLASS =
  'border-0 outline-none ring-0 shadow-none !bg-transparent [background:transparent!important] [background-color:transparent!important] [&_.token-line]:!bg-transparent [&_.token-line]:[background:transparent!important] [&_code]:!bg-transparent'

/**
 * Syntax highlighting theme for CodeBlock (connect modal, docs, CLI modals, etc.).
 *
 * Connect modal and docs both render through ConnectCodeExample → CodeBlock →
 * buildCodeBlockPrismTheme(). Edit token colors here once; both surfaces update.
 */
export function buildCodeBlockPrismTheme(
  resolvedTheme: string | undefined,
): PrismTheme {
  const isDark =
    resolvedTheme !== undefined
      ? isResolvedThemeDarkChrome(resolvedTheme)
      : isHtmlDarkChrome()

  const base = isDark ? themes.vsDark : themes.vsLight
  const colors = getCodeSyntaxColors(isDark)

  return {
    plain: {
      color: base.plain.color,
      backgroundColor: 'transparent',
      background: 'transparent',
    },
    styles: prismSyntaxHighlightStyles(colors, base.plain.color),
  }
}

/** Fully transparent editor surface - no Prism gray, no alpha tint. */
export const CODE_BLOCK_EDITOR_SURFACE_STYLE: CSSProperties = {
  background: 'transparent',
  backgroundColor: 'transparent',
}

export function stripPrismTokenBackground(
  style?: CSSProperties,
): CSSProperties {
  if (!style) return { ...CODE_BLOCK_EDITOR_SURFACE_STYLE }

  const next: CSSProperties = { ...style }
  for (const key of PRISM_BACKGROUND_KEYS) {
    delete next[key]
  }

  return {
    ...next,
    ...CODE_BLOCK_EDITOR_SURFACE_STYLE,
  }
}

/** Pre/root style: keep default text color, never inherit Prism fill. */
export function resolvePrismPreSurfaceStyle(
  style?: CSSProperties,
): CSSProperties {
  return {
    margin: 0,
    color: style?.color,
    ...CODE_BLOCK_EDITOR_SURFACE_STYLE,
  }
}
