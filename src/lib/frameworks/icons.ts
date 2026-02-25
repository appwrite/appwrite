/**
 * Framework icon mapping: framework key → icon filename in /public/icons/.
 *
 * Keys use normalized form: lowercase, spaces/underscores/dots replaced with dash.
 * Add aliases for common variants (e.g. next-js, tanstack-start).
 */

/** Normalize for icon lookup: lowercase, replace spaces/underscores/dots with dash. */
function normalizeForIcon(frameworkKey: string | null | undefined): string {
  if (!frameworkKey || typeof frameworkKey !== 'string') return ''
  return frameworkKey
    .toLowerCase()
    .replace(/\s+/g, '-')
    .replace(/_/g, '-')
    .replace(/\./g, '-')
    .trim()
}

/** Map normalized framework key to icon filename (e.g. 'nextjs' → 'nextjs.svg'). */
export const FRAMEWORK_ICON_MAP: Record<string, string> = {
  react: 'react.svg',
  reactjs: 'react.svg',
  'react-js': 'react.svg',
  'react-native': 'react.svg',
  reactnative: 'react.svg',

  nextjs: 'nextjs.svg',
  'next-js': 'nextjs.svg',
  next: 'nextjs.svg',

  remix: 'remix.svg',
  remixjs: 'remix.svg',
  'remix-js': 'remix.svg',

  tanstack: 'tanstack.svg',
  'tanstack-start': 'tanstack.svg',
  tanstackstart: 'tanstack.svg',

  vue: 'vue.svg',
  vuejs: 'vue.svg',
  'vue-js': 'vue.svg',

  nuxt: 'nuxt.svg',
  nuxtjs: 'nuxt.svg',
  'nuxt-js': 'nuxt.svg',

  angular: 'angular.svg',
  angularjs: 'angular.svg',
  'angular-js': 'angular.svg',

  analog: 'analog.svg',
  analogjs: 'analog.svg',
  'analog-js': 'analog.svg',

  svelte: 'svelte.svg',
  sveltekit: 'svelte.svg',
  'svelte-kit': 'svelte.svg',

  solid: 'solid.svg',
  solidjs: 'solid.svg',
  'solid-js': 'solid.svg',

  refine: 'refine.svg',

  astro: 'astro.svg',
  astrojs: 'astro.svg',
  'astro-js': 'astro.svg',

  vite: 'vite.svg',
  vitejs: 'vite.svg',
  'vite-js': 'vite.svg',

  flutter: 'flutter.svg',

  python: 'python.svg',
  dart: 'dart.svg',
  php: 'php.svg',
  ruby: 'ruby.svg',
  dotnet: 'dotnet.svg',
  go: 'go.svg',
  swift: 'swift.svg',
  kotlin: 'kotlin.svg',
  node: 'node.svg',
  express: 'node.svg',
  deno: 'deno.svg',

  pnpm: 'pnpm.svg',
  npm: 'npm.svg',

  lynx: 'lynx.svg',
  lynxjs: 'lynx.svg',
  'lynx-js': 'lynx.svg',

  static: 'js.svg',
  vanilla: 'js.svg',
}

/**
 * Returns the icon filename for the given framework key, or null if none.
 */
export function getFrameworkIconFile(
  frameworkKey: string | null | undefined,
): string | null {
  const normalized = normalizeForIcon(frameworkKey)
  return (normalized && FRAMEWORK_ICON_MAP[normalized]) ?? null
}
