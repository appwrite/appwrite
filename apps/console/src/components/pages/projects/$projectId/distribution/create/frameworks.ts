import type { DistributionFramework } from '@/lib/appwrite/distribution'

const FRAMEWORK_DESCRIPTION: Record<DistributionFramework, string> = {
  flutter: 'One Dart codebase for iOS and Android.',
  'react-native':
    'JavaScript or TypeScript with native iOS and Android builds.',
  android: 'A native Android project built with Gradle.',
  ios: 'A native iOS project built with Xcode.',
}

export function frameworkDescription(framework: DistributionFramework): string {
  return FRAMEWORK_DESCRIPTION[framework]
}
