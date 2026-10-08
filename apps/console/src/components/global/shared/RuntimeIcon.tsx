import { Brain, Code, FileCode } from 'lucide-react'
import { cn } from '@/lib/utils'
import { LanguageIcon } from './LanguageIcon'

interface RuntimeIconProps {
  runtime: string
  className?: string
  size?: 'sm' | 'md' | 'lg'
}

const sizeClasses = {
  sm: 'h-4 w-4',
  md: 'h-5 w-5',
  lg: 'h-6 w-6',
}

function getRuntimeLanguage(runtime: string): string | null {
  if (!runtime) return null

  const normalized = runtime.toLowerCase()

  if (normalized.startsWith('python-ml')) {
    return 'python-ml'
  }

  // Extract base runtime name (e.g., "node-22.0" -> "node", "python-3.11" -> "python")
  const baseRuntime = normalized.split('-')[0]

  // Map runtime names to language icons
  const runtimeToLanguage: Record<string, string> = {
    node: 'node',
    python: 'python',
    php: 'php',
    ruby: 'ruby',
    java: 'java',
    go: 'go',
    deno: 'deno',
    bun: 'bun',
    dart: 'dart',
    flutter: 'flutter',
    swift: 'swift',
    kotlin: 'kotlin',
    dotnet: 'dotnet',
    cpp: 'cpp',
    rust: 'rust',
  }

  return runtimeToLanguage[baseRuntime] || null
}

export function RuntimeIcon({
  runtime,
  className,
  size = 'md',
}: RuntimeIconProps) {
  if (!runtime) {
    return <FileCode className={cn(sizeClasses[size], className)} />
  }

  const language = getRuntimeLanguage(runtime)

  if (language === 'python-ml') {
    return <Brain className={cn(sizeClasses[size], 'text-muted-foreground', className)} />
  }

  if (language) {
    return (
      <LanguageIcon language={language} className={className} size={size} />
    )
  }

  // Fallback to Code icon if runtime not recognized
  return <Code className={cn(sizeClasses[size], className)} />
}
