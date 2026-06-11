import type { LucideIcon } from 'lucide-react'
import {
  Code2,
  FileText,
  Globe,
  LayoutGrid,
  Mail,
  MessageSquare,
  Rocket,
  Smartphone,
} from 'lucide-react'
import { getFrameworkIconFile } from '@/lib/frameworks/icons'

const MARKDOC_CARD_ICON_OVERRIDES: Record<string, string> = {
  'icon-node_js': 'node.svg',
  'icon-js': 'js.svg',
  'icon-dotnet': 'dotnet.svg',
  'icon-react-native': 'react-native.svg',
}

const MARKDOC_CARD_LUCIDE_ICONS: Record<string, LucideIcon> = {
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

/**
 * Resolves a markdoc cards_item icon attribute to a public SVG path or Lucide icon.
 */
export function resolveMarkdocCardIcon(icon: string | undefined):
  | { type: 'image'; src: string }
  | { type: 'lucide'; Icon: LucideIcon }
  | null {
  if (!icon) return null

  const lucideIcon = MARKDOC_CARD_LUCIDE_ICONS[icon]
  if (lucideIcon) {
    return { type: 'lucide', Icon: lucideIcon }
  }

  const overrideFile = MARKDOC_CARD_ICON_OVERRIDES[icon]
  if (overrideFile) {
    return { type: 'image', src: `/icons/${overrideFile}` }
  }

  const stripped = stripMarkdocIconPrefix(icon)
  const frameworkFile = getFrameworkIconFile(stripped.replace(/_/g, '-'))
  if (frameworkFile) {
    return { type: 'image', src: `/icons/${frameworkFile}` }
  }

  const filename = `${stripped.replace(/_/g, '-')}.svg`
  return { type: 'image', src: `/icons/${filename}` }
}
