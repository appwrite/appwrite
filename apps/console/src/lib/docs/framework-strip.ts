export type DocsFrameworkStripItem = {
  name: string
  href: string
  iconSrc: string
}

/** Priority order for docs hero / quick-start strips (matches marketing homepage). */
const FRAMEWORK_STRIP_PRIORITY = [
  'React',
  'TanStack Start',
  'Next.js',
  'Vue',
  'SvelteKit',
  'Nuxt',
  'Astro',
  'Android',
  'iOS',
  'Flutter',
] as const

const FRAMEWORK_STRIP_SOURCE: DocsFrameworkStripItem[] = [
  { name: 'React', href: '/docs/quick-starts/react', iconSrc: '/icons/react.svg' },
  {
    name: 'TanStack Start',
    href: '/docs/quick-starts/tanstack-start',
    iconSrc: '/icons/tanstack.svg',
  },
  { name: 'Next.js', href: '/docs/quick-starts/nextjs', iconSrc: '/icons/nextjs.svg' },
  { name: 'Vue', href: '/docs/quick-starts/vue', iconSrc: '/icons/vue.svg' },
  { name: 'Angular', href: '/docs/quick-starts/angular', iconSrc: '/icons/angular.svg' },
  {
    name: 'SvelteKit',
    href: '/docs/quick-starts/sveltekit',
    iconSrc: '/icons/svelte.svg',
  },
  { name: 'Nuxt', href: '/docs/quick-starts/nuxt', iconSrc: '/icons/nuxt.svg' },
  { name: 'Qwik', href: '/docs/quick-starts/qwik', iconSrc: '/icons/qwik.svg' },
  { name: 'Solid', href: '/docs/quick-starts/solid', iconSrc: '/icons/solid.svg' },
  { name: 'Refine', href: '/docs/quick-starts/refine', iconSrc: '/icons/refine.svg' },
  {
    name: 'Remix',
    href: '/docs/products/sites/quick-start/remix',
    iconSrc: '/icons/remix.svg',
  },
  { name: 'Astro', href: '/docs/quick-starts/astro', iconSrc: '/icons/astro.svg' },
  { name: 'Web', href: '/docs/quick-starts/web', iconSrc: '/icons/js.svg' },
  {
    name: 'React Native',
    href: '/docs/quick-starts/react-native',
    iconSrc: '/icons/react-native.svg',
  },
  { name: 'Node.js', href: '/docs/quick-starts/node', iconSrc: '/icons/node.svg' },
  { name: 'Python', href: '/docs/quick-starts/python', iconSrc: '/icons/python.svg' },
  { name: 'PHP', href: '/docs/quick-starts/php', iconSrc: '/icons/php.svg' },
  { name: 'Ruby', href: '/docs/quick-starts/ruby', iconSrc: '/icons/ruby.svg' },
  { name: '.NET', href: '/docs/quick-starts/dotnet', iconSrc: '/icons/dotnet.svg' },
  { name: 'Go', href: '/docs/quick-starts/go', iconSrc: '/icons/go.svg' },
  { name: 'Deno', href: '/docs/quick-starts/deno', iconSrc: '/icons/deno.svg' },
  { name: 'Dart', href: '/docs/quick-starts/dart', iconSrc: '/icons/dart.svg' },
  { name: 'Rust', href: '/docs/quick-starts/rust', iconSrc: '/icons/rust.svg' },
  { name: 'Kotlin', href: '/docs/quick-starts/kotlin', iconSrc: '/icons/kotlin.svg' },
  { name: 'Swift', href: '/docs/quick-starts/swift', iconSrc: '/icons/swift.svg' },
  { name: 'Android', href: '/docs/quick-starts/android', iconSrc: '/icons/android.svg' },
  {
    name: 'Android (Java)',
    href: '/docs/quick-starts/android-java',
    iconSrc: '/icons/java.svg',
  },
  { name: 'iOS', href: '/docs/quick-starts/apple', iconSrc: '/icons/apple.svg' },
  { name: 'Flutter', href: '/docs/quick-starts/flutter', iconSrc: '/icons/flutter.svg' },
]

function buildOrderedFrameworkStrip(
  source: DocsFrameworkStripItem[],
): DocsFrameworkStripItem[] {
  const byName = new Map(source.map((item) => [item.name, item]))
  const ordered: DocsFrameworkStripItem[] = []
  const used = new Set<string>()

  for (const name of FRAMEWORK_STRIP_PRIORITY) {
    const item = byName.get(name)
    if (item) {
      ordered.push(item)
      used.add(item.name)
    }
  }

  for (const item of source) {
    if (!used.has(item.name)) ordered.push(item)
  }

  return ordered
}

export const DOCS_FRAMEWORK_STRIP = buildOrderedFrameworkStrip(FRAMEWORK_STRIP_SOURCE)
