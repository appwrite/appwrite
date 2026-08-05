import type { LucideIcon } from 'lucide-react'
import {
  ArrowUpDown,
  BotMessageSquare,
  Database,
  Folder,
  Globe,
  Radio,
  Send,
  Server,
  Share2,
  UserCircle,
  Users,
  Zap,
} from 'lucide-react'

export type DocsHomeProductCard = {
  title: string
  description: string
  href: string
  icon: LucideIcon
}

export type DocsHomeToolBadge = {
  label: string
  variant: 'info' | 'inactive'
}

export type DocsHomeToolCard = {
  title: string
  href: string
  iconSrc?: string
  badges?: DocsHomeToolBadge[]
}

export type DocsHomeIntegrationCard = {
  title: string
  description: string
  href: string
  iconSrc?: string
}

export type DocsHomeTutorialCard = {
  title: string
  description: string
  href: string
  iconSrc: string
}

export type DocsHomeMigrationCard = {
  title: string
  description: string
  href: string
  iconSrc?: string
}

export const DOCS_HOME_PRODUCTS: DocsHomeProductCard[] = [
  {
    title: 'Auth',
    description: 'Sign in users with multiple OAuth providers.',
    href: '/docs/products/auth',
    icon: Users,
  },
  {
    title: 'Databases',
    description: 'Store your application and user data.',
    href: '/docs/products/databases',
    icon: Database,
  },
  {
    title: 'Functions',
    description: "Extend and customize your server's functionality.",
    href: '/docs/products/functions',
    icon: Zap,
  },
  {
    title: 'Sites',
    description: 'Deploy websites on the internet at scale.',
    href: '/docs/products/sites',
    icon: Globe,
  },
  {
    title: 'Messaging',
    description: 'Send and schedule email, SMS, and push notifications.',
    href: '/docs/products/messaging',
    icon: Send,
  },
  {
    title: 'Storage',
    description: 'Store images, videos, documents, and files.',
    href: '/docs/products/storage',
    icon: Folder,
  },
  {
    title: 'Avatars',
    description: 'Generate icons, screenshots, and QR codes for your apps.',
    href: '/docs/products/avatars',
    icon: UserCircle,
  },
  {
    title: 'Realtime',
    description: 'Respond to server events in realtime.',
    href: '/docs/apis/realtime',
    icon: Radio,
  },
  {
    title: 'Agent',
    description: 'Chat in the Console to inspect projects and take approved actions.',
    href: '/docs/products/agent',
    icon: BotMessageSquare,
  },
]

const OFFICIAL_TOOL_BADGE: DocsHomeToolBadge = { label: 'Official', variant: 'info' }

export const DOCS_HOME_IDE_AI_TOOLS: DocsHomeToolCard[] = [
  {
    title: 'Claude Code',
    href: '/docs/tooling/ai/agents/claude-code',
    iconSrc: '/icons/claude.svg',
    badges: [OFFICIAL_TOOL_BADGE],
  },
  {
    title: 'Codex',
    href: '/docs/tooling/ai/agents/codex',
    iconSrc: '/icons/chatgpt.svg',
    badges: [OFFICIAL_TOOL_BADGE],
  },
  {
    title: 'Cursor',
    href: '/docs/tooling/ai/agents/cursor',
    iconSrc: '/icons/cursor-ai.svg',
    badges: [OFFICIAL_TOOL_BADGE],
  },
  {
    title: 'VS Code',
    href: '/docs/tooling/ai/agents/vscode',
    iconSrc: '/icons/vscode.svg',
  },
  {
    title: 'Zed',
    href: '/docs/tooling/ai/agents/zed',
    iconSrc: '/icons/zed.svg',
  },
  {
    title: 'OpenCode',
    href: '/docs/tooling/ai/agents/opencode',
    iconSrc: '/icons/opencode.svg',
  },
  {
    title: 'Google Antigravity',
    href: '/docs/tooling/ai/agents/antigravity',
    iconSrc: '/icons/google-antigravity.svg',
  },
]

export const DOCS_HOME_VIBE_AI_TOOLS: DocsHomeToolCard[] = [
  {
    title: 'Claude Desktop',
    href: '/docs/tooling/ai/vibe-coding/claude-desktop',
    iconSrc: '/icons/claude.svg',
  },
  {
    title: 'Lovable',
    href: '/docs/tooling/ai/vibe-coding/lovable',
    iconSrc: '/icons/lovable.svg',
  },
  {
    title: 'Emergent',
    href: '/docs/tooling/ai/vibe-coding/emergent',
    iconSrc: '/icons/emergent.svg',
  },
  {
    title: 'Bolt',
    href: '/docs/tooling/ai/vibe-coding/bolt',
    iconSrc: '/icons/bolt.svg',
  },
  {
    title: 'Zenflow',
    href: '/docs/tooling/ai/vibe-coding/zenflow',
    iconSrc: '/icons/zenflow.svg',
  },
]

export const DOCS_HOME_INTEGRATIONS: DocsHomeIntegrationCard[] = [
  {
    title: 'SDKs',
    description: 'Light-weight SDKs for your favorite platforms.',
    href: '/docs/sdks',
  },
  {
    title: 'REST API',
    description: 'Integrate with HTTP requests without needing an SDK.',
    href: '/docs/apis/rest',
  },
  {
    title: 'GraphQL',
    description:
      'Leverage GraphQL through our SDKs or integrate directly with REST endpoints.',
    href: '/docs/apis/graphql',
    iconSrc: '/icons/graphql.svg',
  },
  {
    title: 'Realtime',
    description:
      'Respond to auth, databases, storage, and function events in realtime.',
    href: '/docs/apis/realtime',
  },
]

export const DOCS_HOME_TUTORIALS: DocsHomeTutorialCard[] = [
  {
    title: 'React tutorial',
    description: 'Learn Appwrite Auth, Databases, and more with React.',
    href: '/docs/tutorials/react/step-1',
    iconSrc: '/icons/react.svg',
  },
  {
    title: 'Next.js tutorial',
    description: 'Learn Appwrite Auth, Databases, and more with Next.js.',
    href: '/docs/tutorials/nextjs/step-1',
    iconSrc: '/icons/nextjs.svg',
  },
  {
    title: 'Next.js SSR auth',
    description: 'Build authenticated SSR apps with Next.js and Appwrite.',
    href: '/docs/tutorials/nextjs-ssr-auth/step-1',
    iconSrc: '/icons/nextjs.svg',
  },
  {
    title: 'Vue tutorial',
    description: 'Learn Appwrite Auth, Databases, and more with Vue.',
    href: '/docs/tutorials/vue/step-1',
    iconSrc: '/icons/vue.svg',
  },
  {
    title: 'Nuxt tutorial',
    description: 'Learn Appwrite Auth, Databases, and more with Nuxt.',
    href: '/docs/tutorials/nuxt/step-1',
    iconSrc: '/icons/nuxt.svg',
  },
  {
    title: 'Nuxt SSR auth',
    description: 'Build authenticated SSR apps with Nuxt and Appwrite.',
    href: '/docs/tutorials/nuxt-ssr-auth/step-1',
    iconSrc: '/icons/nuxt.svg',
  },
  {
    title: 'SvelteKit tutorial',
    description: 'Learn Appwrite Auth, Databases, and more with SvelteKit.',
    href: '/docs/tutorials/sveltekit/step-1',
    iconSrc: '/icons/svelte.svg',
  },
  {
    title: 'SvelteKit SSR auth',
    description: 'Build authenticated SSR apps with SvelteKit and Appwrite.',
    href: '/docs/tutorials/sveltekit-ssr-auth/step-1',
    iconSrc: '/icons/svelte.svg',
  },
  {
    title: 'SvelteKit CSR auth',
    description: 'Add client-side auth to SvelteKit apps with Appwrite.',
    href: '/docs/tutorials/sveltekit-csr-auth/step-1',
    iconSrc: '/icons/svelte.svg',
  },
  {
    title: 'Astro SSR auth',
    description: 'Build authenticated SSR apps with Astro and Appwrite.',
    href: '/docs/tutorials/astro-ssr-auth/step-1',
    iconSrc: '/icons/astro.svg',
  },
  {
    title: 'React Native tutorial',
    description: 'Learn Appwrite Auth, Databases, and more with React Native.',
    href: '/docs/tutorials/react-native/step-1',
    iconSrc: '/icons/react-native.svg',
  },
  {
    title: 'Flutter tutorial',
    description: 'Learn Appwrite Auth, Databases, and more with Flutter.',
    href: '/docs/tutorials/flutter/step-1',
    iconSrc: '/icons/flutter.svg',
  },
  {
    title: 'Android tutorial',
    description: 'Learn Appwrite Auth, Databases, and more with Android.',
    href: '/docs/tutorials/android/step-1',
    iconSrc: '/icons/android.svg',
  },
  {
    title: 'iOS tutorial',
    description: 'Learn Appwrite Auth, Databases, and more with iOS.',
    href: '/docs/tutorials/apple/step-1',
    iconSrc: '/icons/apple.svg',
  },
  {
    title: 'Refine tutorial',
    description: 'Build admin panels and internal tools with Refine and Appwrite.',
    href: '/docs/tutorials/refine/step-1',
    iconSrc: '/icons/refine.svg',
  },
]

export const DOCS_HOME_MIGRATIONS: DocsHomeMigrationCard[] = [
  {
    title: 'Self-hosted',
    description: 'Move data from self-hosted to Appwrite Cloud.',
    href: '/docs/advanced/migrations/self-hosted',
  },
  {
    title: 'Firebase',
    description: 'Migrate users and data from Firebase to Appwrite.',
    href: '/docs/advanced/migrations/firebase',
    iconSrc: '/icons/firebase.svg',
  },
  {
    title: 'Supabase',
    description: 'Migrate users and data from Supabase to Appwrite.',
    href: '/docs/advanced/migrations/supabase',
    iconSrc: '/icons/supabase.svg',
  },
  {
    title: 'Vercel',
    description: 'Migrate web applications from Vercel to Appwrite Sites.',
    href: '/docs/products/sites/migrations/vercel',
    iconSrc: '/icons/vercel.svg',
  },
  {
    title: 'Nhost',
    description: 'Migrate users and data from NHost to Appwrite.',
    href: '/docs/advanced/migrations/nhost',
    iconSrc: '/icons/nhost.svg',
  },
]

export const DOCS_HOME_INTEGRATION_ICONS = {
  sdks: Share2,
  rest: ArrowUpDown,
  realtime: Radio,
} as const

export const DOCS_HOME_MIGRATION_ICONS = {
  'self-hosted': Server,
} as const
