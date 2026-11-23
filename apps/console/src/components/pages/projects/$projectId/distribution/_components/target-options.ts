import type {
  DistributionPlatform,
  DistributionProvider,
  DistributionTargetConfiguration,
  DistributionVersionStrategy,
} from '@/lib/appwrite/distribution'
import { PLATFORM_PROVIDERS } from './platform'

export const NONE = 'none'

export const TARGET_TITLES: Record<DistributionPlatform, string> = {
  ios: 'iOS target',
  android: 'Android target',
}

export const TRACKS: Record<DistributionProvider, readonly string[]> = {
  apple: ['testflight', 'testflight-external', 'production'],
  google: ['internal', 'alpha', 'beta', 'production'],
}

const TRACK_LABELS: Record<string, string> = {
  testflight: 'TestFlight (internal)',
  'testflight-external': 'TestFlight (external)',
  internal: 'Internal testing',
  alpha: 'Closed testing',
  beta: 'Open testing',
  production: 'Production',
}

export function trackLabel(track: string): string {
  return TRACK_LABELS[track] ?? track
}

export function defaultTrack(platform: DistributionPlatform): string {
  return TRACKS[PLATFORM_PROVIDERS[platform]][0]
}

export const SPECIFICATIONS: readonly string[] = ['d-4vcpu-8gb', 'd-8vcpu-16gb']

const SPECIFICATION_LABELS: Record<string, string> = {
  'd-4vcpu-8gb': '4 vCPU, 8 GB RAM',
  'd-8vcpu-16gb': '8 vCPU, 16 GB RAM',
}

export function specificationLabel(specification: string): string {
  return SPECIFICATION_LABELS[specification] ?? specification
}

export const VERSION_STRATEGIES: readonly DistributionVersionStrategy[] = [
  'automatic',
  'manual',
]

export const VERSION_STRATEGY_LABELS: Record<
  DistributionVersionStrategy,
  string
> = {
  automatic: 'Automatic',
  manual: 'Manual',
}

export type ConfigurationKey = keyof DistributionTargetConfiguration

export type ConfigurationField = {
  key: ConfigurationKey
  label: string
  placeholder: string
}

export const CONFIGURATION_FIELDS: Record<
  DistributionPlatform,
  readonly ConfigurationField[]
> = {
  ios: [
    { key: 'scheme', label: 'Scheme', placeholder: 'Runner' },
    {
      key: 'workspace',
      label: 'Workspace',
      placeholder: 'ios/Runner.xcworkspace',
    },
    {
      key: 'project',
      label: 'Xcode project',
      placeholder: 'ios/Runner.xcodeproj',
    },
    {
      key: 'configuration',
      label: 'Build configuration',
      placeholder: 'Release',
    },
  ],
  android: [
    { key: 'flavor', label: 'Flavor', placeholder: 'production' },
    {
      key: 'gradleTask',
      label: 'Gradle task',
      placeholder: 'bundleProductionRelease',
    },
  ],
}
