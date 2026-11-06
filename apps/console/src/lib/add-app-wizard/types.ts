/**
 * User-facing buckets for the Add app wizard (URL param `kind`).
 * Maps to project `create*Platform` calls via wizard `variant` (see platform hooks).
 */
export const ADD_APP_KINDS = [
  'web',
  'android',
  'apple',
  'flutter',
  'react-native',
  'windows',
  'linux',
] as const

export type AddAppKind = (typeof ADD_APP_KINDS)[number]

export const WEB_FRAMEWORK_KEYS = [
  'react',
  'vue',
  'svelte',
  'sveltekit',
  'tanstack-start',
  'nextjs',
  'nuxt',
  'angular',
  'analog',
  'remix',
  'solid',
  'vite',
  'astro',
  'js',
] as const

export type WebFrameworkKey = (typeof WEB_FRAMEWORK_KEYS)[number]
