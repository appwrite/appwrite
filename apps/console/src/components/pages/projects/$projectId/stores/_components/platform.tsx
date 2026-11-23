import { Apple, MonitorSmartphone, Smartphone, Box } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { cn } from '@/lib/utils'

const PLATFORM_ICON: Record<string, LucideIcon> = {
  android: Smartphone,
  ios: Apple,
  windows: MonitorSmartphone,
}

const PLATFORM_LABEL: Record<string, string> = {
  android: 'Android',
  ios: 'iOS',
  windows: 'Windows',
}

const FRAMEWORK_LABEL: Record<string, string> = {
  flutter: 'Flutter',
  'react-native': 'React Native',
  expo: 'Expo',
  android: 'Android',
  ios: 'iOS',
  maui: '.NET MAUI',
  other: 'Other',
}

const PROVIDER_LABEL: Record<string, string> = {
  'google-play': 'Google Play',
  'app-store-connect': 'App Store Connect',
  'microsoft-store': 'Microsoft Store',
}

function titleCase(value: string) {
  if (!value) return '-'
  return value.charAt(0).toUpperCase() + value.slice(1)
}

export function platformLabel(platform: string) {
  return PLATFORM_LABEL[platform] ?? titleCase(platform)
}

export function frameworkLabel(framework: string) {
  return FRAMEWORK_LABEL[framework] ?? titleCase(framework)
}

export function providerLabel(provider: string) {
  return PROVIDER_LABEL[provider] ?? titleCase(provider)
}

export function PlatformIcon({
  platform,
  className,
}: {
  platform: string
  className?: string
}) {
  const Icon = PLATFORM_ICON[platform] ?? Box
  return (
    <Icon
      className={cn('h-4 w-4', className)}
      aria-label={platformLabel(platform)}
    />
  )
}

export function PlatformIcons({
  platforms,
  className,
}: {
  platforms: string[]
  className?: string
}) {
  return (
    <div
      className={cn(
        'flex items-center gap-1.5 text-muted-foreground',
        className,
      )}
    >
      {platforms.map((platform) => (
        <PlatformIcon key={platform} platform={platform} />
      ))}
    </div>
  )
}
