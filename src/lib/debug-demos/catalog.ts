export const DEBUG_DEMO_CATEGORIES = [
  'Auth',
  'Console',
  'Git',
  'GitHub Education',
  'OAuth2',
  'Sites',
  'Product',
  'Tools',
] as const

export type DebugDemoCategory = (typeof DEBUG_DEMO_CATEGORIES)[number]

export type DebugDemoEntry =
  | {
      id: string
      label: string
      category: DebugDemoCategory
      type: 'route'
      href: string
    }
  | {
      id: string
      label: string
      category: DebugDemoCategory
      type: 'fullscreen-loader'
      enabled: boolean
    }
  | {
      id: string
      label: string
      category: DebugDemoCategory
      type: 'community-wizard'
    }
  | {
      id: string
      label: string
      category: DebugDemoCategory
      type: 'community-share-examples'
    }

function buildCatalog(): DebugDemoEntry[] {
  const authRoutes: DebugDemoEntry[] = [
    {
      id: 'auth-sign-in',
      label: 'Sign in',
      category: 'Auth',
      type: 'route',
      href: '/debug/sign-in-preview',
    },
    {
      id: 'auth-sign-up',
      label: 'Sign up',
      category: 'Auth',
      type: 'route',
      href: '/debug/sign-up-preview',
    },
    {
      id: 'auth-recovery',
      label: 'Password recovery',
      category: 'Auth',
      type: 'route',
      href: '/debug/recovery-preview',
    },
    {
      id: 'auth-reset',
      label: 'Reset password (mock link)',
      category: 'Auth',
      type: 'route',
      href: '/debug/reset-preview',
    },
    {
      id: 'auth-mfa',
      label: 'MFA challenge',
      category: 'Auth',
      type: 'route',
      href: '/debug/mfa-preview',
    },
    {
      id: 'auth-magic-url',
      label: 'Magic URL login',
      category: 'Auth',
      type: 'route',
      href: '/debug/magic-url-preview',
    },
    {
      id: 'auth-verify-email',
      label: 'Verify email',
      category: 'Auth',
      type: 'route',
      href: '/debug/verify-email-preview',
    },
    {
      id: 'auth-join-invite',
      label: 'Organization invite',
      category: 'Auth',
      type: 'route',
      href: '/debug/join-invite-preview',
    },
    {
      id: 'auth-impersonate',
      label: 'Impersonate user (email lookup)',
      category: 'Auth',
      type: 'route',
      href: '/debug/impersonate-preview',
    },
  ]

  const consoleRoutes: DebugDemoEntry[] = [
    {
      id: 'console-error-page',
      label: 'Error page',
      category: 'Console',
      type: 'route',
      href: '/debug/error-preview',
    },
    {
      id: 'console-org-setup',
      label: 'Organization setup progress',
      category: 'Console',
      type: 'route',
      href: '/debug/org-setup-preview',
    },
    {
      id: 'console-functions-editor',
      label: 'Functions code editor',
      category: 'Console',
      type: 'route',
      href: '/debug/code-editor-preview',
    },
  ]

  const githubEducationRoutes: DebugDemoEntry[] = [
    {
      id: 'auth-education-join',
      label: 'Education program join',
      category: 'GitHub Education',
      type: 'route',
      href: '/debug/education-join-preview',
    },
    {
      id: 'education-plan-curtain',
      label: 'Education plan curtain',
      category: 'GitHub Education',
      type: 'route',
      href: '/debug/education-plan-preview?view=reminder',
    },
  ]

  const gitRoutes: DebugDemoEntry[] = [
    {
      id: 'git-contributor-authorization',
      label: 'Git authorization',
      category: 'Git',
      type: 'route',
      href: '/debug/authorize-contributor-preview?status=awaiting',
    },
  ]

  const sitesRoutes: DebugDemoEntry[] = [
    {
      id: 'sites-auth-preview',
      label: 'Sites auth preview',
      category: 'Sites',
      type: 'route',
      href: '/debug/sites-auth-preview',
    },
  ]

  const oauth2Routes: DebugDemoEntry[] = [
    {
      id: 'oauth2-consent',
      label: 'OAuth2 consent',
      category: 'OAuth2',
      type: 'route',
      href: '/debug/oauth2-preview?screen=consent',
    },
    {
      id: 'oauth2-device-flow',
      label: 'OAuth2 device flow',
      category: 'OAuth2',
      type: 'route',
      href: '/debug/oauth2-preview?screen=device-code',
    },
    {
      id: 'oauth2-outcomes',
      label: 'OAuth2 outcomes',
      category: 'OAuth2',
      type: 'route',
      href: '/debug/oauth2-preview?screen=outcome-approved',
    },
    {
      id: 'oauth2-relay',
      label: 'OAuth2 login relay',
      category: 'OAuth2',
      type: 'route',
      href: '/debug/oauth2-relay-preview?variant=success',
    },
  ]

  const productEntries: DebugDemoEntry[] = [
    {
      id: 'product-community-wizard',
      label: 'Community support wizard',
      category: 'Product',
      type: 'community-wizard',
    },
    {
      id: 'product-community-share',
      label: 'X share examples',
      category: 'Product',
      type: 'community-share-examples',
    },
  ]

  const toolEntries: DebugDemoEntry[] = [
    {
      id: 'tools-fullscreen-loader',
      label: 'Fullscreen loader',
      category: 'Tools',
      type: 'fullscreen-loader',
      enabled: true,
    },
  ]

  return [
    ...authRoutes,
    ...consoleRoutes,
    ...gitRoutes,
    ...githubEducationRoutes,
    ...oauth2Routes,
    ...sitesRoutes,
    ...productEntries,
    ...toolEntries,
  ]
}

export const DEBUG_DEMO_CATALOG: DebugDemoEntry[] = buildCatalog()

export function getDebugDemoById(id: string): DebugDemoEntry | undefined {
  return DEBUG_DEMO_CATALOG.find((entry) => entry.id === id)
}

export function getDebugDemosGroupedByCategory(): Array<{
  category: DebugDemoCategory
  items: DebugDemoEntry[]
}> {
  const map = new Map<DebugDemoCategory, DebugDemoEntry[]>()
  for (const category of DEBUG_DEMO_CATEGORIES) {
    map.set(category, [])
  }
  for (const entry of DEBUG_DEMO_CATALOG) {
    map.get(entry.category)?.push(entry)
  }
  return DEBUG_DEMO_CATEGORIES.map((category) => ({
    category,
    items: map.get(category) ?? [],
  })).filter((group) => group.items.length > 0)
}

export const DEFAULT_DEBUG_DEMO_ID = DEBUG_DEMO_CATALOG[0]?.id ?? 'auth-sign-in'
