import type { LucideIcon } from 'lucide-react'
import {
  Code2,
  FileText,
  Globe,
  LayoutGrid,
  Mail,
  MessageSquare,
  Plus,
  Rocket,
  Smartphone,
} from 'lucide-react'
import { getFrameworkIconFile } from '@/lib/frameworks/icons'

export type MarkdocIconSize = 's' | 'm' | 'l'

/** Fixed display size for framework/platform SVG icons across docs tables and lists. */
export const MARKDOC_BRAND_ICON_CLASS =
  'h-6 w-6 min-h-6 min-w-6 max-h-6 max-w-6 shrink-0 object-contain'

/** Inline Lucide icons in prose (e.g. plus in navigation paths). */
export const MARKDOC_INLINE_LUCIDE_ICON_CLASS = 'size-4 shrink-0'

/** @deprecated Prefer MARKDOC_BRAND_ICON_CLASS for consistent brand icon sizing. */
export const MARKDOC_ICON_SIZE_CLASSES: Record<MarkdocIconSize, string> = {
  s: 'size-4',
  m: 'size-6',
  l: 'size-6',
}

const MARKDOC_ICON_FILE_OVERRIDES: Record<string, string> = {
  node_js: 'node.svg',
  'bun-sh': 'bun.svg',
  js: 'js.svg',
  javascript: 'js.svg',
  cpp: 'cpp.svg',
  c: 'cpp.svg',
  'react-native': 'react-native.svg',
  dotnet: 'dotnet.svg',
  aws: 'amazon.svg',
  'icon-node_js': 'node.svg',
  'icon-js': 'js.svg',
  'icon-dotnet': 'dotnet.svg',
  'icon-react-native': 'react-native.svg',
}

const MARKDOC_LUCIDE_ICONS: Record<string, LucideIcon> = {
  plus: Plus,
  'icon-mail': Mail,
  'icon-annotation': MessageSquare,
  'icon-device-mobile': Smartphone,
  'icon-globe-alt': Globe,
  'icon-document-text': FileText,
  'icon-code-bracket': Code2,
  'icon-rocket-launch': Rocket,
  'icon-squares-2x2': LayoutGrid,
}

function stripMarkdocIconPrefix(icon: string): string {
  return icon.replace(/^(icon-|web-icon-)/, '')
}

function getMarkdocIconFile(name: string): string | null {
  const normalized = name.toLowerCase().replace(/_/g, '-')
  const override =
    MARKDOC_ICON_FILE_OVERRIDES[name] ??
    MARKDOC_ICON_FILE_OVERRIDES[normalized] ??
    MARKDOC_ICON_FILE_OVERRIDES[name.replace(/-/g, '_')]
  if (override) return override

  return getFrameworkIconFile(normalized) ?? getFrameworkIconFile(name)
}

export function resolveMarkdocIconByName(icon: string | undefined):
  | { type: 'image'; src: string }
  | { type: 'lucide'; Icon: LucideIcon }
  | null {
  if (!icon) return null

  const lucideIcon = MARKDOC_LUCIDE_ICONS[icon] ?? MARKDOC_LUCIDE_ICONS[`icon-${icon}`]
  if (lucideIcon) {
    return { type: 'lucide', Icon: lucideIcon }
  }

  const stripped = stripMarkdocIconPrefix(icon)
  const iconFile = getMarkdocIconFile(stripped)
  if (iconFile) {
    return { type: 'image', src: `/icons/${iconFile}` }
  }

  const fallbackFile = `${stripped.replace(/_/g, '-')}.svg`
  return { type: 'image', src: `/icons/${fallbackFile}` }
}

/** Resolves cards_item icon attributes (supports icon-* / web-icon-* prefixes). */
export function resolveMarkdocCardIcon(icon: string | undefined):
  | { type: 'image'; src: string }
  | { type: 'lucide'; Icon: LucideIcon }
  | null {
  return resolveMarkdocIconByName(icon)
}

/** Maps legacy /images/platforms/* and /images/one-click/* paths to /icons/*. */
export function resolveMarkdocIconImageSrc(src: string | undefined): string | null {
  if (!src) return null

  const filename = src.split('/').pop()?.replace(/\.svg$/i, '')
  if (!filename) return null

  const iconFile = getMarkdocIconFile(filename)
  if (iconFile) {
    return `/icons/${iconFile}`
  }

  return `/icons/${filename.replace(/_/g, '-')}.svg`
}
