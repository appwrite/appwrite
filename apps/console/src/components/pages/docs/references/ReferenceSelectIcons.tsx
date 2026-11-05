import { ArrowUpDown, Tags } from 'lucide-react'
import { CloudMarkIcon } from '@/components/global/shared/CloudMarkIcon'
import { PlatformIcon } from '@/components/global/shared/Icon'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import type { ReferencePlatform, ReferenceVersion } from '@/lib/docs/references/constants'
import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { cn } from '@/lib/utils'

const ICON_SIZE_CLASS = 'h-4 w-4 shrink-0'

const CLIENT_PLATFORM_ICON: Partial<Record<ReferencePlatform, string>> = {
  'client-web': 'web',
  'client-flutter': 'flutter',
  'client-react-native': 'react-native',
  'client-apple': 'apple',
  'client-android-kotlin': 'android',
  'client-android-java': 'android',
}

const SERVER_FRAMEWORK_ICON: Partial<Record<ReferencePlatform, string>> = {
  'server-nodejs': 'node',
  'server-python': 'python',
  'server-dart': 'dart',
  'server-php': 'php',
  'server-ruby': 'ruby',
  'server-dotnet': 'dotnet',
  'server-deno': 'deno',
  'server-go': 'go',
  'server-swift': 'swift',
  'server-kotlin': 'kotlin',
  'server-rust': 'rust',
  'server-java': 'java',
}

type ReferenceSelectIconProps = {
  className?: string
}

export function ReferencePlatformIcon({
  platform,
  className,
}: ReferenceSelectIconProps & { platform: ReferencePlatform }) {
  if (platform === 'client-graphql' || platform === 'server-graphql') {
    return (
      <img
        src="/icons/graphql.svg"
        alt=""
        aria-hidden
        className={cn(ICON_SIZE_CLASS, PUBLIC_ICON_MUTED_CLASSES, className)}
      />
    )
  }

  if (platform === 'client-rest' || platform === 'server-rest') {
    return (
      <ArrowUpDown
        className={cn(ICON_SIZE_CLASS, 'text-muted-foreground', className)}
        aria-hidden
      />
    )
  }

  const clientPlatform = CLIENT_PLATFORM_ICON[platform]
  if (clientPlatform) {
    return <PlatformIcon platform={clientPlatform} size="sm" className={className} />
  }

  const serverFramework = SERVER_FRAMEWORK_ICON[platform]
  if (serverFramework) {
    return <FrameworkIcon framework={serverFramework} size="sm" className={className} />
  }

  return <PlatformIcon platform="web" size="sm" className={className} />
}

export function ReferenceVersionIcon({
  version,
  className,
}: ReferenceSelectIconProps & { version: ReferenceVersion }) {
  if (version === 'cloud') {
    return (
      <CloudMarkIcon
        className={cn(ICON_SIZE_CLASS, 'text-muted-foreground', className)}
      />
    )
  }

  return (
    <Tags
      className={cn(ICON_SIZE_CLASS, 'text-muted-foreground', className)}
      aria-hidden
    />
  )
}
