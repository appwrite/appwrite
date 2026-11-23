import { Apple, Smartphone } from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import type {
  DistributionFramework,
  DistributionPlatform,
  DistributionProvider,
} from '@/lib/appwrite/distribution'
import { cn } from '@/lib/utils'

export const PLATFORMS = [
  'ios',
  'android',
] as const satisfies readonly DistributionPlatform[]

export const FRAMEWORKS = [
  'flutter',
  'react-native',
  'android',
  'ios',
] as const satisfies readonly DistributionFramework[]

const FRAMEWORK_PLATFORMS: Record<
  DistributionFramework,
  readonly DistributionPlatform[]
> = {
  flutter: ['ios', 'android'],
  'react-native': ['ios', 'android'],
  android: ['android'],
  ios: ['ios'],
}

export const PLATFORM_PROVIDERS: Record<
  DistributionPlatform,
  DistributionProvider
> = {
  ios: 'apple',
  android: 'google',
}

const PLATFORM_ICONS: Record<DistributionPlatform, LucideIcon> = {
  ios: Apple,
  android: Smartphone,
}

const PLATFORM_LABELS: Record<DistributionPlatform, string> = {
  ios: 'iOS',
  android: 'Android',
}

const FRAMEWORK_LABELS: Record<DistributionFramework, string> = {
  flutter: 'Flutter',
  'react-native': 'React Native',
  android: 'Android',
  ios: 'iOS',
}

const PROVIDER_LABELS: Record<DistributionProvider, string> = {
  apple: 'App Store Connect',
  google: 'Google Play',
}

export function frameworkPlatforms(
  framework: DistributionFramework,
): DistributionPlatform[] {
  return [...FRAMEWORK_PLATFORMS[framework]]
}

export function platformLabel(platform: DistributionPlatform): string {
  return PLATFORM_LABELS[platform]
}

export function frameworkLabel(framework: DistributionFramework): string {
  return FRAMEWORK_LABELS[framework]
}

export function providerLabel(provider: DistributionProvider): string {
  return PROVIDER_LABELS[provider]
}

export function PlatformIcon({
  platform,
  className,
}: {
  platform: DistributionPlatform
  className?: string
}) {
  const Icon = PLATFORM_ICONS[platform]
  return (
    <Icon
      className={cn('h-4 w-4', className)}
      aria-label={platformLabel(platform)}
    />
  )
}
