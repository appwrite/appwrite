import { Apple, MonitorSmartphone, Smartphone } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import { cn } from '@/lib/utils'
import type {
  DistributionFramework,
  DistributionPlatform,
  DistributionProvider,
} from '@/lib/react-query/hooks'

const PLATFORM_ICON: Record<DistributionPlatform, LucideIcon> = {
  android: Smartphone,
  ios: Apple,
  windows: MonitorSmartphone,
}

const PLATFORM_LABEL: Record<DistributionPlatform, string> = {
  android: 'Android',
  ios: 'iOS',
  windows: 'Windows',
}

const FRAMEWORK_LABEL: Record<DistributionFramework, string> = {
  flutter: 'Flutter',
  'react-native': 'React Native',
  expo: 'Expo',
  android: 'Android',
  ios: 'iOS',
  maui: '.NET MAUI',
  other: 'Other',
}

const PROVIDER_LABEL: Record<DistributionProvider, string> = {
  'google-play': 'Google Play',
  'app-store-connect': 'App Store Connect',
  'microsoft-store': 'Microsoft Store',
}

export function platformLabel(platform: DistributionPlatform) {
  return PLATFORM_LABEL[platform]
}

export function frameworkLabel(framework: DistributionFramework) {
  return FRAMEWORK_LABEL[framework]
}

export function providerLabel(provider: DistributionProvider) {
  return PROVIDER_LABEL[provider]
}

export function PlatformIcon({
  platform,
  className,
}: {
  platform: DistributionPlatform
  className?: string
}) {
  const Icon = PLATFORM_ICON[platform]
  return (
    <Icon
      className={cn('h-4 w-4', className)}
      aria-label={PLATFORM_LABEL[platform]}
    />
  )
}

export function PlatformIcons({
  platforms,
  className,
}: {
  platforms: DistributionPlatform[]
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
