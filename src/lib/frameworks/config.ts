/**
 * Framework configuration: adapter copy (SSR/Static), and future metadata.
 *
 * Display text for adapters comes from this local config only; the API
 * (sites.listFrameworks()) does not provide labels, descriptions, or doc URLs.
 *
 * In adapter copy, desc uses `$` as placeholders; they are replaced in order
 * by the corresponding values from code[], rendered as inline <code> in the UI.
 */

export type AdapterOptionCopy = {
  /** Short description. Use `$` as placeholders; replaced in order by values from code. */
  desc: string
  /** Snippets shown as inline code. For each `$` in desc, the next code[i] is used. */
  code: string[]
  /** Optional "Learn more" link. */
  url?: string
}

export type FrameworkAdapterCopy = {
  ssr?: AdapterOptionCopy
  static?: AdapterOptionCopy
}

/** Single framework config entry. Key must match API framework key (case-insensitive). */
export type FrameworkConfig = {
  /** Framework key; must match API framework key (case-insensitive). */
  key: string
  /** SSR/Static adapter display copy for build settings. */
  adapters?: FrameworkAdapterCopy
}

/**
 * Single source of truth for framework-related config (adapter copy, etc.).
 * Keyed by framework key; UI looks up with normalizeFrameworkKey(key).
 */
export const FRAMEWORK_CONFIGS: FrameworkConfig[] = [
  {
    key: 'sveltekit',
    adapters: {
      ssr: {
        desc: 'Use $ adapter in $ file.',
        code: ['@sveltejs/adapter-node', 'svelte.config.js'],
        url: 'https://kit.svelte.dev/docs#adapter-node',
      },
      static: {
        desc: 'Use $ adapter in $ file.',
        code: ['@sveltejs/adapter-static', 'svelte.config.js'],
        url: 'https://kit.svelte.dev/docs#adapter-static',
      },
    },
  },
  {
    key: 'astro',
    adapters: {
      ssr: {
        desc: 'Use $ adapter in $ file.',
        code: ['@astro/node', 'astro.config.mjs'],
        url: 'https://docs.astro.build/en/guides/server-side-rendering/',
      },
      static: {
        desc: "Ensure you don't set $ adapter in $ file.",
        code: ['adapter', 'astro.config.mjs'],
        url: 'https://docs.astro.build/en/guides/deploy/',
      },
    },
  },
  {
    key: 'remix',
    adapters: {
      ssr: {
        desc: 'Ensure $ file uses $ package.',
        code: ['entry.server.tsx', '@remix-run/node'],
      },
      static: {
        desc: 'Set $ in $ plugin in $ file.',
        code: ['ssr: false', 'remix', 'vite.config.ts'],
      },
    },
  },
  {
    key: 'nuxt',
    adapters: {
      ssr: {
        desc: 'Set build command to $ in site settings.',
        code: ['npm run build'],
        url: 'https://nuxt.com/docs/getting-started/deployment',
      },
      static: {
        desc: 'Set build command to $ in site settings.',
        code: ['npm run generate'],
        url: 'https://nuxt.com/docs/getting-started/deployment#static-hosting',
      },
    },
  },
  {
    key: 'tanstack-start',
    adapters: {
      ssr: {
        desc: 'Ensure $ includes $ plugin.',
        code: ['vite.config.js', 'tanstackStart()'],
        url: 'https://tanstack.com/start/latest/docs/framework/react/guide/hosting',
      },
      static: {
        desc: 'Set $ to $ in $.',
        code: ['prerender', 'enabled', 'vite.config.js'],
        url: 'https://tanstack.com/start/latest/docs/framework/react/guide/static-prerendering',
      },
    },
  },
  {
    key: 'nextjs',
    adapters: {
      ssr: {
        desc: 'Set $ in $ file.',
        code: ["output: 'standalone'", 'next.config.js'],
        url: 'https://nextjs.org/docs/pages/building-your-application/deploying',
      },
      static: {
        desc: 'Set $ in $ file.',
        code: ["output: 'export'", 'next.config.js'],
        url: 'https://nextjs.org/docs/pages/building-your-application/deploying/static-exports',
      },
    },
  },
  {
    key: 'analog',
    adapters: {
      ssr: {
        desc: 'Set $ in $ plugin in $.',
        code: ['ssr: true', 'analog', 'vite.config.ts'],
        url: 'https://analogjs.org/docs/features/server/server-side-rendering',
      },
      static: {
        desc: 'Set $ in $ plugin in $.',
        code: ['static: true', 'analog', 'vite.config.ts'],
        url: 'https://analogjs.org/docs/features/server/static-site-generation',
      },
    },
  },
  {
    key: 'angular',
    adapters: {
      ssr: {
        desc: 'Ensure $ file uses $ package.',
        code: ['src/server.ts', '@angular/ssr/node'],
        url: 'https://angular.dev/guide/ssr',
      },
      static: {
        desc: "Angular's default build is static. No further action needed.",
        code: [],
      },
    },
  },
]

/** Normalize framework key for lookups (lowercase, trim). */
export function normalizeFrameworkKey(key: string | null | undefined): string {
  return key?.toLowerCase().trim() ?? ''
}

const GENERIC_ADAPTER_COPY: Record<'ssr' | 'static', AdapterOptionCopy> = {
  ssr: {
    desc: 'Run the app with a server at runtime.',
    code: [],
  },
  static: {
    desc: 'Serve pre-built static files (HTML/CSS/JS).',
    code: [],
  },
}

const ADAPTER_LABELS: Record<'ssr' | 'static', string> = {
  ssr: 'Server side rendering',
  static: 'Static site',
}

/**
 * Returns display copy for the given framework and adapter.
 * Uses normalizeFrameworkKey for lookup. Falls back to generic text when missing.
 */
export function getAdapterCopy(
  frameworkKey: string | null | undefined,
  adapter: 'ssr' | 'static',
): { label: string; desc: string; code: string[]; url?: string } {
  const label = ADAPTER_LABELS[adapter]
  const normalized = normalizeFrameworkKey(frameworkKey)
  const config = FRAMEWORK_CONFIGS.find(
    (c) => normalizeFrameworkKey(c.key) === normalized,
  )
  const option = config?.adapters?.[adapter] ?? GENERIC_ADAPTER_COPY[adapter]
  return {
    label,
    desc: option.desc,
    code: option.code ?? [],
    url: option.url,
  }
}

/**
 * Splits desc on `$` and interleaves with code[] for rendering.
 * Returns segments: text parts and code parts in order.
 */
export function getAdapterDescriptionSegments(
  desc: string,
  code: string[],
): Array<{ type: 'text'; value: string } | { type: 'code'; value: string }> {
  const segments: Array<
    { type: 'text'; value: string } | { type: 'code'; value: string }
  > = []
  const parts = desc.split('$')
  parts.forEach((text, i) => {
    if (text.length > 0) segments.push({ type: 'text', value: text })
    if (i < code.length && code[i] != null) {
      segments.push({ type: 'code', value: code[i]! })
    }
  })
  return segments
}

/**
 * Returns the framework config for the given key, or undefined.
 */
export function getFrameworkConfig(
  frameworkKey: string | null | undefined,
): FrameworkConfig | undefined {
  const normalized = normalizeFrameworkKey(frameworkKey)
  return FRAMEWORK_CONFIGS.find(
    (c) => normalizeFrameworkKey(c.key) === normalized,
  )
}
