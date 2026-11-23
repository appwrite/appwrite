import { PUBLIC_ICON_MUTED_CLASSES } from '@/lib/public-icon-classes'
import { cn } from '@/lib/utils'

interface LanguageIconProps {
  language: string
  className?: string
  size?: 'sm' | 'md' | 'lg'
}

const sizeClasses = {
  sm: 'h-5 w-5',
  md: 'h-6 w-6',
  lg: 'h-8 w-8',
}

// Map language IDs to icon file names
const languageIconMap: Record<string, string> = {
  node: 'node.svg',
  python: 'python.svg',
  php: 'php.svg',
  ruby: 'ruby.svg',
  go: 'go.svg',
  deno: 'deno.svg',
  bun: 'bun.svg',
  dart: 'dart.svg',
  flutter: 'flutter.svg',
  swift: 'swift.svg',
  kotlin: 'kotlin.svg',
  java: 'java.svg',
  dotnet: 'dotnet.svg',
  rust: 'rust.svg',
  cpp: 'cpp.svg',
}

export function LanguageIcon({
  language,
  className,
  size = 'md',
}: LanguageIconProps) {
  const normalized = language.toLowerCase()
  const iconFile = languageIconMap[normalized]
  const sizeClass = sizeClasses[size as keyof typeof sizeClasses]

  if (!iconFile) {
    return null
  }

  // Go icon uses #414146 (dark gray) instead of #C4C6D7 (light gray) like others
  const isGoIcon = normalized === 'go'

  return (
    <img
      src={`/icons/${iconFile}`}
      alt={language}
      className={cn(
        sizeClass,
        // Make icons work in both light and dark mode (match muted-foreground in light)
        // Go icon uses #414146 (dark gray) - keep dark in light mode, invert to light in dark mode
        isGoIcon
          ? 'brightness-0 dark:brightness-0 dark:invert'
          : PUBLIC_ICON_MUTED_CLASSES,
        className,
      )}
    />
  )
}
