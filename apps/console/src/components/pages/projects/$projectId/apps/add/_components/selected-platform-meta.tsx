import { Globe } from 'lucide-react'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { PlatformIcon } from '@/components/global/shared/Icon'
import { getPlatformDisplayName } from '@/lib/utils/platform'
import { WEB_FRAMEWORK_META } from '@/lib/add-app-wizard/platform-map'
import type { AddAppKind, WebFrameworkKey } from '@/lib/add-app-wizard/types'

export function iconPlatformForKind(k: AddAppKind): string {
  if (k === 'windows' || k === 'linux') return k
  if (k === 'react-native') return 'react-native'
  if (k === 'apple') return 'apple'
  return k
}

const VARIANT_LABELS: Record<string, string> = {
  'flutter-android': 'Android',
  'flutter-ios': 'iOS',
  'flutter-web': 'Web',
  'flutter-linux': 'Linux',
  'flutter-macos': 'macOS',
  'flutter-windows': 'Windows',
  'apple-ios': 'iOS',
  'apple-macos': 'macOS',
  'apple-watchos': 'watchOS',
  'apple-tvos': 'tvOS',
  'react-native-android': 'Android',
  'react-native-ios': 'iOS',
}

/**
 * Strip Flutter / RN prefixes so the icon renders as a single muted brand
 * SVG (avoids the white-pill corner badge in `PlatformIcon`). Returns `null`
 * for variants that should fall back to a Globe icon (e.g. `flutter-web`).
 */
function variantToIconKey(variant: string): string | null {
  if (variant.startsWith('flutter-')) {
    const os = variant.replace('flutter-', '')
    if (os === 'web') return null
    return os
  }
  if (variant.startsWith('react-native-')) {
    return variant.replace('react-native-', '')
  }
  return variant
}

export type SelectionSummary = {
  kindLabel: string
  /** Optional sub-selection (framework for web, target for flutter/apple/RN). */
  secondary: {
    label: string
    icon: React.ReactNode
  } | null
}

/** Derive the user-facing selection summary from the wizard search state. */
export function getSelectionSummary(
  kind: AddAppKind,
  variant: string,
  framework: WebFrameworkKey,
): SelectionSummary {
  const kindLabel = getPlatformDisplayName(kind)

  if (kind === 'web') {
    const iconKey = framework === 'js' ? 'vanilla' : framework
    return {
      kindLabel,
      secondary: {
        label: WEB_FRAMEWORK_META[framework].label,
        icon: <FrameworkIcon framework={iconKey} size="md" />,
      },
    }
  }

  if (kind === 'flutter' || kind === 'apple' || kind === 'react-native') {
    const iconKey = variantToIconKey(variant)
    return {
      kindLabel,
      secondary: {
        label: VARIANT_LABELS[variant] ?? variant,
        icon: iconKey ? (
          <PlatformIcon platform={iconKey} size="md" />
        ) : (
          <Globe className="h-6 w-6" />
        ),
      },
    }
  }

  return { kindLabel, secondary: null }
}

export function PlatformKindIcon({ kind }: { kind: AddAppKind }) {
  return <PlatformIcon platform={iconPlatformForKind(kind)} size="md" />
}
