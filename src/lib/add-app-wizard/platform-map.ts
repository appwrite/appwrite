import type { AddAppKind, WebFrameworkKey } from './types'

/** Default variant when `kind` is chosen without `variant` in the URL. */
export function defaultVariantForKind(kind: AddAppKind): string {
  switch (kind) {
    case 'web':
      return 'web'
    case 'android':
      return 'android'
    case 'apple':
      return 'apple-ios'
    case 'flutter':
      return 'flutter-android'
    case 'react-native':
      return 'react-native-android'
    case 'windows':
      return 'flutter-windows'
    case 'linux':
      return 'flutter-linux'
  }
}

export function variantNeedsHostname(variant: string): boolean {
  return variant === 'web' || variant === 'flutter-web'
}

export function variantNeedsKey(variant: string): boolean {
  if (variant === 'web') return false
  if (variant === 'flutter-web') return false
  return true
}

export function isValidHostname(value: string): boolean {
  const v = value.trim()
  if (!v) return false
  if (v === 'localhost') return true
  // No protocol or port; allow common dev/prod hostnames
  if (/[\s/:]/.test(v)) return false
  return /^[a-zA-Z0-9][a-zA-Z0-9.-]*[a-zA-Z0-9]$/.test(v) || v.length >= 3
}

/** Simple package/bundle id check (reverse-DNS style). */
export function isValidKey(value: string): boolean {
  const v = value.trim()
  if (v.length < 3) return false
  return /^[a-zA-Z][a-zA-Z0-9._-]*(\.[a-zA-Z][a-zA-Z0-9._-]*)+$/.test(v)
}

export function isValidKeyForVariant(variant: string, value: string): boolean {
  const v = value.trim()
  if (v.length < 2) return false
  if (variant === 'flutter-linux') {
    return /^[a-zA-Z0-9._-]+$/.test(v)
  }
  return isValidKey(value)
}

export const FLUTTER_VARIANT_OPTIONS: { value: string; label: string }[] = [
  { value: 'flutter-android', label: 'Android' },
  { value: 'flutter-ios', label: 'iOS' },
  { value: 'flutter-web', label: 'Web' },
  { value: 'flutter-linux', label: 'Linux' },
  { value: 'flutter-macos', label: 'macOS' },
  { value: 'flutter-windows', label: 'Windows' },
]

export const APPLE_VARIANT_OPTIONS: { value: string; label: string }[] = [
  { value: 'apple-ios', label: 'iOS' },
  { value: 'apple-macos', label: 'macOS' },
  { value: 'apple-watchos', label: 'watchOS' },
  { value: 'apple-tvos', label: 'tvOS' },
]

export const REACT_NATIVE_VARIANT_OPTIONS: { value: string; label: string }[] =
  [
    { value: 'react-native-android', label: 'Android' },
    { value: 'react-native-ios', label: 'iOS' },
  ]

export type WebFrameworkMetaEntry = {
  label: string
  port: number
  runCommand: string
  envPrefix: string
  /**
   * GitHub repo name under `appwrite/` when it is not `starter-for-<framework key>`.
   * Example: `vite` uses the generic JS starter.
   */
  starterRepo?: string
}

export const WEB_FRAMEWORK_META: Record<WebFrameworkKey, WebFrameworkMetaEntry> = {
  react: {
    label: 'React',
    port: 5173,
    runCommand: 'pnpm dev',
    envPrefix: 'VITE_',
  },
  vue: {
    label: 'Vue',
    port: 5173,
    runCommand: 'pnpm dev',
    envPrefix: 'VITE_',
  },
  svelte: {
    label: 'Svelte',
    port: 5173,
    runCommand: 'pnpm dev',
    envPrefix: 'PUBLIC_',
  },
  sveltekit: {
    label: 'SvelteKit',
    port: 5173,
    runCommand: 'pnpm dev',
    envPrefix: 'PUBLIC_',
    starterRepo: 'starter-for-svelte',
  },
  'tanstack-start': {
    label: 'TanStack Start',
    port: 3000,
    runCommand: 'pnpm dev',
    envPrefix: 'VITE_',
  },
  nextjs: {
    label: 'Next.js',
    port: 3000,
    runCommand: 'pnpm dev',
    envPrefix: 'NEXT_PUBLIC_',
  },
  nuxt: {
    label: 'Nuxt',
    port: 3000,
    runCommand: 'pnpm dev',
    envPrefix: 'NUXT_PUBLIC_',
  },
  angular: {
    label: 'Angular',
    port: 4200,
    runCommand: 'pnpm start',
    envPrefix: '',
  },
  analog: {
    label: 'Analog',
    port: 5173,
    runCommand: 'pnpm dev',
    envPrefix: 'VITE_',
    starterRepo: 'starter-for-analog',
  },
  remix: {
    label: 'Remix',
    port: 3000,
    runCommand: 'pnpm dev',
    envPrefix: 'VITE_',
    starterRepo: 'starter-for-remix',
  },
  solid: {
    label: 'Solid',
    port: 5173,
    runCommand: 'pnpm dev',
    envPrefix: 'VITE_',
    starterRepo: 'starter-for-js',
  },
  vite: {
    label: 'Vite',
    port: 5173,
    runCommand: 'pnpm dev',
    envPrefix: 'VITE_',
    starterRepo: 'starter-for-js',
  },
  astro: {
    label: 'Astro',
    port: 4321,
    runCommand: 'pnpm dev',
    envPrefix: 'PUBLIC_',
    starterRepo: 'starter-for-astro',
  },
  js: {
    label: 'No framework',
    port: 5173,
    runCommand: 'pnpm dev',
    envPrefix: 'VITE_',
  },
}

/** Repo folder name after `git clone` (matches appwrite org starter repos). */
export function getWebStarterRepoName(framework: WebFrameworkKey): string {
  const m = WEB_FRAMEWORK_META[framework]
  return m.starterRepo ?? `starter-for-${framework}`
}
