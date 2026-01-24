import { Globe } from 'lucide-react'
import { cn } from '@/lib/utils'

export interface FrameworkIconProps {
  framework: string | null | undefined
  className?: string
  size?: 'sm' | 'md' | 'lg'
}

const sizeClasses = {
  sm: 'h-4 w-4',
  md: 'h-5 w-5',
  lg: 'h-6 w-6',
}

// Map framework IDs to icon file names (using existing SVG icons from /public/icons/)
// Only includes frameworks that are actually supported by Appwrite Sites
// Based on: src/components/pages/onboarding/onboarding-data.ts
const frameworkIconMap: Record<string, string> = {
  // Supported frameworks
  react: 'react.svg',
  'react.js': 'react.svg',
  
  nextjs: 'nextjs.svg',
  'next.js': 'nextjs.svg',
  next: 'nextjs.svg',
  
  remix: 'react.svg', // Remix is built on React
  
  tanstack: 'tanstack.svg',
  'tanstack-start': 'tanstack.svg',
  tanstackstart: 'tanstack.svg',
  
  vue: 'vue.svg',
  'vue.js': 'vue.svg',
  
  nuxt: 'vue.svg', // Nuxt is built on Vue
  'nuxt.js': 'vue.svg',
  
  angular: 'angular.svg',
  'angular.js': 'angular.svg',
  
  svelte: 'js.svg', // Using JS icon as fallback
  sveltekit: 'js.svg',
  'svelte-kit': 'js.svg',
  
  astro: 'js.svg',
  
  static: 'js.svg', // Static site
}

/**
 * FrameworkIcon component that displays framework icons based on framework ID
 * Uses existing SVG icons from /public/icons/ when available
 */
export function FrameworkIcon({
  framework,
  className,
  size = 'md',
}: FrameworkIconProps) {
  if (!framework) {
    return <Globe className={cn(sizeClasses[size], className)} />
  }

  // Normalize framework name: lowercase, replace spaces/underscores with dashes, remove dots
  const normalized = framework
    .toLowerCase()
    .replace(/\s+/g, '-')
    .replace(/_/g, '-')
    .replace(/\./g, '-')
  const iconFile = frameworkIconMap[normalized]
  const sizeClass = sizeClasses[size as keyof typeof sizeClasses]

  if (iconFile) {
    return (
      <img
        src={`/icons/${iconFile}`}
        alt={framework}
        className={cn(
          sizeClass,
          // Make icons work in both light and dark mode
          'brightness-0 dark:brightness-100',
          className,
        )}
      />
    )
  }

  // Fallback to Globe icon for frameworks without SVG icons
  return <Globe className={cn(sizeClass, className)} />
}
