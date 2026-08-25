import type { ProductId } from '@/lib/products/types'

export type ProductHeroLogoStripItem = {
  name: string
  key: string
}

export type ProductHeroLogoStripConfig = {
  variant: 'frameworks' | 'runtimes' | 'engines'
  title: string
  items: ProductHeroLogoStripItem[]
}

/**
 * Sites frameworks from docs quick-starts and supported frameworks list.
 * @see src/content/docs/products/sites/frameworks/index.markdoc
 */
const SITES_FRAMEWORK_ITEMS: ProductHeroLogoStripItem[] = [
  { name: 'TanStack Start', key: 'tanstack-start' },
  { name: 'Next.js', key: 'nextjs' },
  { name: 'Nuxt', key: 'nuxt' },
  { name: 'SvelteKit', key: 'sveltekit' },
  { name: 'Angular', key: 'angular' },
  { name: 'Remix', key: 'remix' },
  { name: 'React', key: 'react' },
  { name: 'Vue', key: 'vue' },
  { name: 'Vite', key: 'vite' },
  { name: 'Analog', key: 'analog' },
  { name: 'Astro', key: 'astro' },
  { name: 'Flutter', key: 'flutter' },
  { name: 'React Native', key: 'react-native' },
  { name: 'JavaScript', key: 'vanilla' },
]

/**
 * Functions runtimes from docs (one entry per runtime family).
 * @see src/content/docs/products/functions/runtimes/index.markdoc
 */
const FUNCTIONS_RUNTIME_ITEMS: ProductHeroLogoStripItem[] = [
  { name: 'Node.js', key: 'node-22' },
  { name: 'Bun', key: 'bun-1.3' },
  { name: 'Deno', key: 'deno-2.0' },
  { name: 'Go', key: 'go-1.23' },
  { name: 'Python', key: 'python-3.12' },
  { name: 'Dart', key: 'dart-3.11' },
  { name: 'PHP', key: 'php-8.3' },
  { name: 'Ruby', key: 'ruby-3.3' },
  { name: '.NET', key: 'dotnet-8.0' },
  { name: 'Java', key: 'java-21.0' },
  { name: 'Swift', key: 'swift-5.10' },
  { name: 'Kotlin', key: 'kotlin-2.0' },
  { name: 'Flutter', key: 'flutter-3.41' },
  { name: 'C++', key: 'cpp-20' },
  { name: 'Rust', key: 'rust-1.83' },
]

/** Databases engines shown in the product hero (Appwrite DBs + native SQL). */
const DATABASES_ENGINE_ITEMS: ProductHeroLogoStripItem[] = [
  { name: 'TablesDB', key: 'tablesdb' },
  { name: 'DocumentsDB', key: 'documentsdb' },
  { name: 'VectorsDB', key: 'vectorsdb' },
  { name: 'PostgreSQL', key: 'postgresql' },
  { name: 'MySQL', key: 'mysql' },
]

/** Popular Sites frameworks, Functions runtimes, and Databases engines shown below the product hero. */
export const PRODUCT_HERO_LOGO_STRIPS: Partial<
  Record<ProductId, ProductHeroLogoStripConfig>
> = {
  sites: {
    variant: 'frameworks',
    title: 'Deploy with the framework your team already uses',
    items: SITES_FRAMEWORK_ITEMS,
  },
  functions: {
    variant: 'runtimes',
    title: '13+ runtimes. Develop locally, deploy when ready.',
    items: FUNCTIONS_RUNTIME_ITEMS,
  },
  databases: {
    variant: 'engines',
    title: 'Five engines for tables, documents, vectors, and native SQL',
    items: DATABASES_ENGINE_ITEMS,
  },
}
