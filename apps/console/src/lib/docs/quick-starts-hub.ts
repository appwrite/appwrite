export type QuickStartHubItem = {
  title: string
  href: string
  iconSrc: string
}

export type QuickStartHubCategory = {
  title: string
  items: QuickStartHubItem[]
}

export const QUICK_STARTS_HUB_CATEGORIES: QuickStartHubCategory[] = [
  {
    title: 'Web app',
    items: [
      {
        title: 'TanStack Start',
        href: '/docs/quick-starts/tanstack-start',
        iconSrc: '/icons/react.svg',
      },
      { title: 'Next.js', href: '/docs/quick-starts/nextjs', iconSrc: '/icons/nextjs.svg' },
      { title: 'React', href: '/docs/quick-starts/react', iconSrc: '/icons/react.svg' },
      { title: 'Vue.js', href: '/docs/quick-starts/vue', iconSrc: '/icons/vue.svg' },
      { title: 'SvelteKit', href: '/docs/quick-starts/sveltekit', iconSrc: '/icons/svelte.svg' },
      { title: 'Angular', href: '/docs/quick-starts/angular', iconSrc: '/icons/angular.svg' },
      { title: 'Nuxt', href: '/docs/quick-starts/nuxt', iconSrc: '/icons/nuxt.svg' },
      { title: 'Refine', href: '/docs/quick-starts/refine', iconSrc: '/icons/refine.svg' },
      { title: 'Solid', href: '/docs/quick-starts/solid', iconSrc: '/icons/solid.svg' },
      { title: 'Qwik', href: '/docs/quick-starts/qwik', iconSrc: '/icons/qwik.svg' },
      { title: 'Astro', href: '/docs/quick-starts/astro', iconSrc: '/icons/astro.svg' },
      { title: 'Web', href: '/docs/quick-starts/web', iconSrc: '/icons/js.svg' },
    ],
  },
  {
    title: 'Mobile and native',
    items: [
      { title: 'Flutter', href: '/docs/quick-starts/flutter', iconSrc: '/icons/flutter.svg' },
      {
        title: 'React Native',
        href: '/docs/quick-starts/react-native',
        iconSrc: '/icons/react-native.svg',
      },
      { title: 'Android', href: '/docs/quick-starts/android', iconSrc: '/icons/android.svg' },
      {
        title: 'Android (Java)',
        href: '/docs/quick-starts/android-java',
        iconSrc: '/icons/java.svg',
      },
      { title: 'Apple', href: '/docs/quick-starts/apple', iconSrc: '/icons/apple.svg' },
    ],
  },
  {
    title: 'Server',
    items: [
      { title: 'Node.js', href: '/docs/quick-starts/node', iconSrc: '/icons/node.svg' },
      { title: 'Python', href: '/docs/quick-starts/python', iconSrc: '/icons/python.svg' },
      { title: '.NET', href: '/docs/quick-starts/dotnet', iconSrc: '/icons/dotnet.svg' },
      { title: 'PHP', href: '/docs/quick-starts/php', iconSrc: '/icons/php.svg' },
      { title: 'Dart', href: '/docs/quick-starts/dart', iconSrc: '/icons/dart.svg' },
      { title: 'Ruby', href: '/docs/quick-starts/ruby', iconSrc: '/icons/js.svg' },
      { title: 'Deno', href: '/docs/quick-starts/deno', iconSrc: '/icons/js.svg' },
      { title: 'Go', href: '/docs/quick-starts/go', iconSrc: '/icons/go.svg' },
      { title: 'Swift', href: '/docs/quick-starts/swift', iconSrc: '/icons/swift.svg' },
      { title: 'Kotlin', href: '/docs/quick-starts/kotlin', iconSrc: '/icons/kotlin.svg' },
      { title: 'Rust', href: '/docs/quick-starts/rust', iconSrc: '/icons/rust.svg' },
    ],
  },
]
