/**
 * Connect to your project – simplified modal for project credentials and SDK setup.
 * Adapted from Supabase-style connect flow; tailored to Appwrite (endpoint, project ID, API keys).
 */

import { useState, useMemo, useEffect, useCallback } from 'react'
import { Check, Copy, ExternalLink, Key, Plus } from 'lucide-react'
import { useNavigate } from '@tanstack/react-router'
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useAuth } from '@/components/global/auth/RequireAuth'
import {
  useConnectProjectTab,
  useCreateApiKey,
  useOrganizationScopes,
  useProject,
} from '@/lib/react-query/hooks'
import { toast } from 'sonner'
import { cn } from '@/lib/utils'
import { getApiEndpoint, getBaseEndpoint } from '@/lib/appwrite/sdk'
import { PlatformIcon } from '@/components/global/shared/Icon'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { PackageManagerIcon } from '@/components/global/shared/PackageManagerIcon'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { MCPSection } from '@/components/pages/projects/$projectId/shared/MCPSection'
import { CLISection } from '@/components/pages/projects/$projectId/shared/CLISection'
import { S3ConnectSection } from '@/components/pages/projects/$projectId/shared/S3ConnectSection'
import { TerraformConnectSection } from '@/components/pages/projects/$projectId/shared/TerraformConnectSection'
import { ApiKeyDrawer } from '@/components/pages/projects/$projectId/api-keys/ApiKeyDrawer'
import { ConnectCodePanel } from '@/components/global/shared/ConnectCodeExample'
import {
  CodeBlock,
  type CodeBlockLanguage,
} from '@/components/global/shared/CodeBlock'
import { Tabs, TabsContent } from '@/components/ui/tabs'
import { useT } from '@/lib/i18n/translate'
import { getVcsProvider } from '@/lib/vcs/providers'
import {
  APPWRITE_AGENT_SKILLS_INSTALL,
  APPWRITE_AGENT_SKILLS_REPO,
} from '@/lib/seo/agent-discovery'
import { SKILLS_TRY_IT_PROMPTS } from '@/lib/skills-adoption'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { canCreateKey } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { getErrorMessage } from '@/lib/utils/error-formatting'

const APPWRITE_DOCS_URL = '/docs'
const APPWRITE_SKILLS_DOCS_URL = '/docs/tooling/skills'
const SDK_API_KEY_DEFAULT_NAME = 'SDK'

// This link always points at a GitHub-hosted repo (appwrite/skills),
// not a user-connected VCS installation, so the provider is hardcoded here.
const { Icon: GitHubIcon } = getVcsProvider('github')

interface CodeFile {
  label: string
  code: string
  language?: CodeBlockLanguage
}

/** Client SDKs – [docs](https://appwrite.io/docs/sdks#client) */
const CLIENT_SDK_OPTIONS: { id: string; platform: string; label: string }[] = [
  { id: 'web', platform: 'web', label: 'Web' },
  { id: 'flutter', platform: 'flutter', label: 'Flutter' },
  { id: 'react-native', platform: 'react-native', label: 'React Native' },
  { id: 'apple', platform: 'apple', label: 'Apple' },
  { id: 'android', platform: 'android', label: 'Android' },
]

/** Server SDKs – [docs](https://appwrite.io/docs/sdks#server) */
const SERVER_SDK_OPTIONS: { id: string; platform: string; label: string }[] = [
  { id: 'node', platform: 'web', label: 'Node.js' },
  { id: 'deno', platform: 'web', label: 'Deno' },
  { id: 'bun', platform: 'web', label: 'Bun' },
  { id: 'go', platform: 'web', label: 'Go' },
  { id: 'python', platform: 'web', label: 'Python' },
  { id: 'php', platform: 'web', label: 'PHP' },
  { id: 'ruby', platform: 'web', label: 'Ruby' },
  { id: 'dart', platform: 'web', label: 'Dart' },
  { id: 'swift', platform: 'apple', label: 'Swift' },
  { id: 'dotnet', platform: 'web', label: '.NET' },
  { id: 'kotlin', platform: 'android', label: 'Kotlin' },
  { id: 'java', platform: 'web', label: 'Java' },
  { id: 'rust', platform: 'web', label: 'Rust' },
]

/** Framework options per SDK (id + label). Aligned with https://appwrite.io/docs/quick-starts */
const FRAMEWORK_OPTIONS: Record<string, { id: string; label: string }[]> = {
  web: [
    { id: 'next', label: 'Next.js' },
    { id: 'tanstack', label: 'TanStack Start' },
    { id: 'react', label: 'React' },
    { id: 'sveltekit', label: 'SvelteKit' },
    { id: 'svelte', label: 'Svelte' },
    { id: 'nuxt', label: 'Nuxt' },
    { id: 'vue', label: 'Vue.js' },
    { id: 'analog', label: 'Analog' },
    { id: 'angular', label: 'Angular' },
    { id: 'solidstart', label: 'SolidStart' },
    { id: 'solid', label: 'Solid' },
    { id: 'vanilla', label: 'Vanilla' },
  ],
  node: [
    { id: 'express', label: 'Express' },
    { id: 'koa', label: 'Koa' },
    { id: 'vanilla', label: 'Vanilla' },
  ],
  bun: [
    { id: 'hono', label: 'Hono' },
    { id: 'elysia', label: 'ElysiaJS' },
    { id: 'vanilla', label: 'Vanilla' },
  ],
  // Single-framework platforms keep their platform-specific id (for the
  // icon and code lookups) but read "Vanilla" when the framework would
  // otherwise just repeat the platform name.
  flutter: [{ id: 'flutter', label: 'Vanilla' }],
  'react-native': [{ id: 'react-native', label: 'Vanilla' }],
  apple: [{ id: 'swift', label: 'Swift' }],
  android: [{ id: 'kotlin', label: 'Kotlin' }],
  python: [{ id: 'python', label: 'Vanilla' }],
  dart: [{ id: 'dart', label: 'Vanilla' }],
  php: [{ id: 'php', label: 'Vanilla' }],
  ruby: [{ id: 'ruby', label: 'Vanilla' }],
  dotnet: [{ id: 'dotnet', label: 'Vanilla' }],
  go: [{ id: 'go', label: 'Vanilla' }],
  java: [{ id: 'java', label: 'Vanilla' }],
  rust: [{ id: 'rust', label: 'Vanilla' }],
  swift: [{ id: 'swift', label: 'Vanilla' }],
  kotlin: [{ id: 'kotlin', label: 'Vanilla' }],
  deno: [
    { id: 'fresh', label: 'Fresh' },
    { id: 'vanilla', label: 'Vanilla' },
  ],
}

/**
 * "Using" variants per framework (e.g. React: Vite vs CRA; Next: App Router
 * vs Pages Router). Single-entry lists render as a disabled select showing
 * the implied tooling. Keyed by framework id, so the shared 'vanilla' id
 * (used by web, node, bun, deno) cannot declare one here.
 */
const USING_OPTIONS: Record<string, { id: string; label: string }[]> = {
  react: [
    { id: 'vite', label: 'Vite' },
    { id: 'cra', label: 'Create React App' },
  ],
  next: [
    { id: 'app', label: 'App Router' },
    { id: 'pages', label: 'Pages Router' },
  ],
  tanstack: [{ id: 'vite', label: 'Vite' }],
  sveltekit: [{ id: 'vite', label: 'Vite' }],
  svelte: [{ id: 'vite', label: 'Vite' }],
  nuxt: [{ id: 'vite', label: 'Vite' }],
  vue: [{ id: 'vite', label: 'Vite' }],
  analog: [{ id: 'vite', label: 'Vite' }],
  angular: [{ id: 'cli', label: 'Angular CLI' }],
  solidstart: [{ id: 'vite', label: 'Vite' }],
  solid: [{ id: 'vite', label: 'Vite' }],
}

/** Package manager options per SDK; null = not applicable (use all in install). */
const PACKAGE_MANAGER_OPTIONS: Record<
  string,
  { id: string; label: string }[] | null
> = {
  web: [
    { id: 'npm', label: 'npm' },
    { id: 'bun', label: 'bun' },
    { id: 'pnpm', label: 'pnpm' },
    { id: 'yarn', label: 'yarn' },
  ],
  node: [
    { id: 'npm', label: 'npm' },
    { id: 'bun', label: 'bun' },
    { id: 'pnpm', label: 'pnpm' },
    { id: 'yarn', label: 'yarn' },
  ],
  bun: [
    { id: 'npm', label: 'npm' },
    { id: 'bun', label: 'bun' },
    { id: 'pnpm', label: 'pnpm' },
    { id: 'yarn', label: 'yarn' },
  ],
  'react-native': [
    { id: 'npm', label: 'npm' },
    { id: 'bun', label: 'bun' },
    { id: 'pnpm', label: 'pnpm' },
    { id: 'yarn', label: 'yarn' },
  ],
  deno: [
    { id: 'jsr', label: 'jsr' },
    { id: 'npm', label: 'npm' },
  ],
  flutter: [{ id: 'pub', label: 'pub' }],
  python: [{ id: 'pip', label: 'pip' }],
  dart: [{ id: 'pub', label: 'pub' }],
  php: [{ id: 'composer', label: 'Composer' }],
  ruby: [{ id: 'gem', label: 'gem' }],
  dotnet: [{ id: 'nuget', label: 'NuGet' }],
  go: [{ id: 'go', label: 'go get' }],
  apple: [{ id: 'spm', label: 'Swift Package Manager' }],
  swift: [{ id: 'spm', label: 'Swift Package Manager' }],
  android: [{ id: 'gradle', label: 'Gradle' }],
  kotlin: [{ id: 'gradle', label: 'Gradle' }],
  rust: [{ id: 'cargo', label: 'cargo' }],
  // Java intentionally has no single manager: Gradle and Maven are both
  // shown in the install instructions.
  java: null,
}

function getEnvExample(
  sdkId: string,
  runtime: 'client' | 'server',
  frameworkId: string,
  usingId: string,
  endpoint: string,
  projectId: string,
): string {
  const isServer = runtime === 'server' || sdkId === 'node' || sdkId === 'deno'
  if (sdkId === 'web' && !isServer) {
    if (frameworkId === 'next') {
      return `NEXT_PUBLIC_APPWRITE_ENDPOINT=${endpoint}\nNEXT_PUBLIC_APPWRITE_PROJECT_ID=${projectId}`
    }
    if (frameworkId === 'react' && usingId === 'cra') {
      return `REACT_APP_APPWRITE_ENDPOINT=${endpoint}\nREACT_APP_APPWRITE_PROJECT_ID=${projectId}`
    }
    return `VITE_APPWRITE_ENDPOINT=${endpoint}\nVITE_APPWRITE_PROJECT_ID=${projectId}`
  }
  return `APPWRITE_ENDPOINT=${endpoint}\nAPPWRITE_PROJECT_ID=${projectId}\nAPPWRITE_API_KEY=your-api-key`
}

/** Returns file-based code snippets tailored to the selected SDK, framework, variant (using), and package manager. */
function getCodeFiles(
  sdkId: string,
  frameworkId: string,
  usingId: string,
  runtime: 'client' | 'server',
  endpoint: string,
  projectId: string,
  packageManagerId: string,
): CodeFile[] {
  const envCode = getEnvExample(
    sdkId,
    runtime,
    frameworkId,
    usingId,
    endpoint,
    projectId,
  )
  const envLabel = '.env'
  const tsLang = 'typescript' as CodeBlockLanguage
  const jsLang = 'javascript' as CodeBlockLanguage

  const webEndpoint =
    frameworkId === 'next'
      ? 'process.env.NEXT_PUBLIC_APPWRITE_ENDPOINT'
      : frameworkId === 'react' && usingId === 'cra'
        ? 'process.env.REACT_APP_APPWRITE_ENDPOINT'
        : 'import.meta.env.VITE_APPWRITE_ENDPOINT'
  const webProjectId =
    frameworkId === 'next'
      ? 'process.env.NEXT_PUBLIC_APPWRITE_PROJECT_ID'
      : frameworkId === 'react' && usingId === 'cra'
        ? 'process.env.REACT_APP_APPWRITE_PROJECT_ID'
        : 'import.meta.env.VITE_APPWRITE_PROJECT_ID'
  const clientInitWeb = `const client = new Client()
  .setEndpoint(${webEndpoint})
  .setProject(${webProjectId})`
  const clientInitNode = `const client = new Client()
  .setEndpoint(process.env.APPWRITE_ENDPOINT)
  .setProject(process.env.APPWRITE_PROJECT_ID)
  .setKey(process.env.APPWRITE_API_KEY)`

  switch (sdkId) {
    case 'web': {
      if (frameworkId === 'react') {
        const isCra = usingId === 'cra'
        if (isCra) {
          return [
            { label: envLabel, code: envCode, language: 'env' },
            {
              label: 'src/lib/appwrite.js',
              code: `import { Client } from 'appwrite'

${clientInitWeb}

export { client }
`,
              language: jsLang,
            },
            {
              label: 'src/App.js',
              code: `import { useEffect, useState } from 'react'
import './App.css'
import { client } from './lib/appwrite'
import { Account } from 'appwrite'
import { SignIn } from './pages/SignIn'
import { SignUp } from './pages/SignUp'

function Home() {
  const [user, setUser] = useState(null)

  useEffect(() => {
    const account = new Account(client)
    account
      .get()
      .then((u) => setUser({ name: u.name }))
      .catch(() => {})
  }, [])

  return (
    <div>
      {user ? (
        <p>Hello, {user.name}</p>
      ) : (
        <div>
          <p>Sign in to get started.</p>
          <p>
            <a href="/sign-in">Sign in</a>
            {' · '}
            <a href="/sign-up">Sign up</a>
          </p>
        </div>
      )}
    </div>
  )
}

function App() {
  const path = window.location.pathname
  if (path === '/sign-in') return <SignIn />
  if (path === '/sign-up') return <SignUp />
  return <Home />
}

export default App
`,
              language: jsLang,
            },
            {
              label: 'src/pages/SignIn.js',
              code: `import { useState } from 'react'
import { Account } from 'appwrite'
import { client } from '../lib/appwrite'

export function SignIn() {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')

  async function handleSubmit(e) {
    e.preventDefault()
    setError('')
    try {
      const account = new Account(client)
      await account.createEmailPasswordSession({ email, password })
      window.location.href = '/'
    } catch (err) {
      setError(err?.message || 'Sign in failed')
    }
  }

  return (
    <form onSubmit={handleSubmit}>
      <h1>Sign in</h1>
      {error ? <p>{error}</p> : null}
      <input
        type="email"
        placeholder="Email"
        value={email}
        onChange={(e) => setEmail(e.target.value)}
        required
      />
      <input
        type="password"
        placeholder="Password"
        value={password}
        onChange={(e) => setPassword(e.target.value)}
        required
      />
      <button type="submit">Sign in</button>
      <p>
        No account? <a href="/sign-up">Sign up</a>
      </p>
    </form>
  )
}
`,
              language: jsLang,
            },
            {
              label: 'src/pages/SignUp.js',
              code: `import { useState } from 'react'
import { Account, ID } from 'appwrite'
import { client } from '../lib/appwrite'

export function SignUp() {
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')

  async function handleSubmit(e) {
    e.preventDefault()
    setError('')
    try {
      const account = new Account(client)
      await account.create({
        userId: ID.unique(),
        email,
        password,
        name,
      })
      await account.createEmailPasswordSession({ email, password })
      window.location.href = '/'
    } catch (err) {
      setError(err?.message || 'Sign up failed')
    }
  }

  return (
    <form onSubmit={handleSubmit}>
      <h1>Sign up</h1>
      {error ? <p>{error}</p> : null}
      <input
        type="text"
        placeholder="Name"
        value={name}
        onChange={(e) => setName(e.target.value)}
      />
      <input
        type="email"
        placeholder="Email"
        value={email}
        onChange={(e) => setEmail(e.target.value)}
        required
      />
      <input
        type="password"
        placeholder="Password"
        value={password}
        onChange={(e) => setPassword(e.target.value)}
        required
      />
      <button type="submit">Sign up</button>
      <p>
        Already have an account? <a href="/sign-in">Sign in</a>
      </p>
    </form>
  )
}
`,
              language: jsLang,
            },
          ]
        }
        return [
          { label: envLabel, code: envCode, language: 'env' },
          {
            label: 'src/lib/appwrite.ts',
            code: `import { Client } from 'appwrite'

${clientInitWeb}

export { client }
`,
            language: tsLang,
          },
          {
            label: 'src/App.tsx',
            code: `import { useEffect, useState } from 'react'
import { client } from './lib/appwrite'
import { Account } from 'appwrite'
import { SignIn } from './pages/SignIn'
import { SignUp } from './pages/SignUp'

function Home() {
  const [user, setUser] = useState<{ name: string } | null>(null)

  useEffect(() => {
    const account = new Account(client)
    account.get().then((u) => setUser({ name: u.name })).catch(() => {})
  }, [])

  return (
    <div>
      {user ? (
        <p>Hello, {user.name}</p>
      ) : (
        <div>
          <p>Sign in to get started.</p>
          <p>
            <a href="/sign-in">Sign in</a>
            {' · '}
            <a href="/sign-up">Sign up</a>
          </p>
        </div>
      )}
    </div>
  )
}

export default function App() {
  const path = window.location.pathname
  if (path === '/sign-in') return <SignIn />
  if (path === '/sign-up') return <SignUp />
  return <Home />
}
`,
            language: tsLang,
          },
          {
            label: 'src/pages/SignIn.tsx',
            code: `import { useState, type FormEvent } from 'react'
import { Account } from 'appwrite'
import { client } from '../lib/appwrite'

export function SignIn() {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')

  async function handleSubmit(e: FormEvent) {
    e.preventDefault()
    setError('')
    try {
      const account = new Account(client)
      await account.createEmailPasswordSession({ email, password })
      window.location.href = '/'
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Sign in failed')
    }
  }

  return (
    <form onSubmit={handleSubmit}>
      <h1>Sign in</h1>
      {error ? <p>{error}</p> : null}
      <input
        type="email"
        placeholder="Email"
        value={email}
        onChange={(e) => setEmail(e.target.value)}
        required
      />
      <input
        type="password"
        placeholder="Password"
        value={password}
        onChange={(e) => setPassword(e.target.value)}
        required
      />
      <button type="submit">Sign in</button>
      <p>
        No account? <a href="/sign-up">Sign up</a>
      </p>
    </form>
  )
}
`,
            language: tsLang,
          },
          {
            label: 'src/pages/SignUp.tsx',
            code: `import { useState, type FormEvent } from 'react'
import { Account, ID } from 'appwrite'
import { client } from '../lib/appwrite'

export function SignUp() {
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')

  async function handleSubmit(e: FormEvent) {
    e.preventDefault()
    setError('')
    try {
      const account = new Account(client)
      await account.create({
        userId: ID.unique(),
        email,
        password,
        name,
      })
      await account.createEmailPasswordSession({ email, password })
      window.location.href = '/'
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Sign up failed')
    }
  }

  return (
    <form onSubmit={handleSubmit}>
      <h1>Sign up</h1>
      {error ? <p>{error}</p> : null}
      <input
        type="text"
        placeholder="Name"
        value={name}
        onChange={(e) => setName(e.target.value)}
      />
      <input
        type="email"
        placeholder="Email"
        value={email}
        onChange={(e) => setEmail(e.target.value)}
        required
      />
      <input
        type="password"
        placeholder="Password"
        value={password}
        onChange={(e) => setPassword(e.target.value)}
        required
      />
      <button type="submit">Sign up</button>
      <p>
        Already have an account? <a href="/sign-in">Sign in</a>
      </p>
    </form>
  )
}
`,
            language: tsLang,
          },
        ]
      }
      if (frameworkId === 'vue') {
        return [
          { label: envLabel, code: envCode, language: 'env' },
          {
            label: 'src/lib/appwrite.ts',
            code: `import { Client } from 'appwrite'

${clientInitWeb}

export { client }
`,
            language: tsLang,
          },
          {
            label: 'src/App.vue',
            code: `<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { client } from './lib/appwrite'
import { Account } from 'appwrite'

const user = ref<{ name: string } | null>(null)

onMounted(async () => {
  try {
    const account = new Account(client)
    const u = await account.get()
    user.value = { name: u.name }
  } catch {
    // Not signed in
  }
})
</script>

<template>
  <div>
    <p v-if="user">Hello, {{ user.name }}</p>
    <p v-else>Sign in to get started.</p>
  </div>
</template>
`,
            language: 'markup',
          },
        ]
      }
      if (frameworkId === 'svelte') {
        return [
          { label: envLabel, code: envCode, language: 'env' },
          {
            label: 'src/lib/appwrite.ts',
            code: `import { Client } from 'appwrite'

${clientInitWeb}

export { client }
`,
            language: tsLang,
          },
          {
            label: 'src/App.svelte',
            code: `<script lang="ts">
  import { onMount } from 'svelte'
  import { client } from './lib/appwrite'
  import { Account } from 'appwrite'

  let user: { name: string } | null = null

  onMount(async () => {
    try {
      const account = new Account(client)
      const u = await account.get()
      user = { name: u.name }
    } catch {
      // Not signed in
    }
  })
</script>

<div>
  {#if user}
    <p>Hello, {user.name}</p>
  {:else}
    <p>Sign in to get started.</p>
  {/if}
</div>
`,
            language: 'markup',
          },
        ]
      }
      if (frameworkId === 'next') {
        const isAppRouter = usingId === 'app'
        return [
          { label: envLabel, code: envCode, language: 'env' },
          {
            label: 'lib/appwrite.ts',
            code: `import { Client } from 'appwrite'

const client = new Client()
  .setEndpoint(process.env.NEXT_PUBLIC_APPWRITE_ENDPOINT!)
  .setProject(process.env.NEXT_PUBLIC_APPWRITE_PROJECT_ID!)

export { client }
`,
            language: tsLang,
          },
          ...(isAppRouter
            ? [
                {
                  label: 'app/page.tsx',
                  code: `import Link from 'next/link'
import { client } from '@/lib/appwrite'
import { Account } from 'appwrite'

export default async function HomePage() {
  const account = new Account(client)
  const user = await account.get().catch(() => null)

  if (!user) {
    return (
      <div>
        <p>Sign in to get started.</p>
        <p>
          <Link href="/sign-in">Sign in</Link>
          {' · '}
          <Link href="/sign-up">Sign up</Link>
        </p>
      </div>
    )
  }

  return <p>Hello, {user.name}</p>
}
`,
                  language: tsLang,
                },
                {
                  label: 'app/sign-in/page.tsx',
                  code: `'use client'

import { useState, type FormEvent } from 'react'
import Link from 'next/link'
import { useRouter } from 'next/navigation'
import { Account } from 'appwrite'
import { client } from '@/lib/appwrite'

export default function SignInPage() {
  const router = useRouter()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')

  async function handleSubmit(e: FormEvent) {
    e.preventDefault()
    setError('')
    try {
      const account = new Account(client)
      await account.createEmailPasswordSession({ email, password })
      router.push('/')
      router.refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Sign in failed')
    }
  }

  return (
    <form onSubmit={handleSubmit}>
      <h1>Sign in</h1>
      {error ? <p>{error}</p> : null}
      <input
        type="email"
        placeholder="Email"
        value={email}
        onChange={(e) => setEmail(e.target.value)}
        required
      />
      <input
        type="password"
        placeholder="Password"
        value={password}
        onChange={(e) => setPassword(e.target.value)}
        required
      />
      <button type="submit">Sign in</button>
      <p>
        No account? <Link href="/sign-up">Sign up</Link>
      </p>
    </form>
  )
}
`,
                  language: tsLang,
                },
                {
                  label: 'app/sign-up/page.tsx',
                  code: `'use client'

import { useState, type FormEvent } from 'react'
import Link from 'next/link'
import { useRouter } from 'next/navigation'
import { Account, ID } from 'appwrite'
import { client } from '@/lib/appwrite'

export default function SignUpPage() {
  const router = useRouter()
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')

  async function handleSubmit(e: FormEvent) {
    e.preventDefault()
    setError('')
    try {
      const account = new Account(client)
      await account.create({
        userId: ID.unique(),
        email,
        password,
        name,
      })
      await account.createEmailPasswordSession({ email, password })
      router.push('/')
      router.refresh()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Sign up failed')
    }
  }

  return (
    <form onSubmit={handleSubmit}>
      <h1>Sign up</h1>
      {error ? <p>{error}</p> : null}
      <input
        type="text"
        placeholder="Name"
        value={name}
        onChange={(e) => setName(e.target.value)}
      />
      <input
        type="email"
        placeholder="Email"
        value={email}
        onChange={(e) => setEmail(e.target.value)}
        required
      />
      <input
        type="password"
        placeholder="Password"
        value={password}
        onChange={(e) => setPassword(e.target.value)}
        required
      />
      <button type="submit">Sign up</button>
      <p>
        Already have an account? <Link href="/sign-in">Sign in</Link>
      </p>
    </form>
  )
}
`,
                  language: tsLang,
                },
              ]
            : [
                {
                  label: 'pages/index.tsx',
                  code: `import { useEffect, useState } from 'react'
import Link from 'next/link'
import { client } from '@/lib/appwrite'
import { Account } from 'appwrite'

export default function Home() {
  const [user, setUser] = useState<{ name: string } | null>(null)

  useEffect(() => {
    const account = new Account(client)
    account.get().then((u) => setUser({ name: u.name })).catch(() => {})
  }, [])

  return (
    <div>
      {user ? (
        <p>Hello, {user.name}</p>
      ) : (
        <div>
          <p>Sign in to get started.</p>
          <p>
            <Link href="/sign-in">Sign in</Link>
            {' · '}
            <Link href="/sign-up">Sign up</Link>
          </p>
        </div>
      )}
    </div>
  )
}
`,
                  language: tsLang,
                },
                {
                  label: 'pages/sign-in.tsx',
                  code: `import { useState, type FormEvent } from 'react'
import Link from 'next/link'
import { useRouter } from 'next/router'
import { Account } from 'appwrite'
import { client } from '@/lib/appwrite'

export default function SignInPage() {
  const router = useRouter()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')

  async function handleSubmit(e: FormEvent) {
    e.preventDefault()
    setError('')
    try {
      const account = new Account(client)
      await account.createEmailPasswordSession({ email, password })
      router.push('/')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Sign in failed')
    }
  }

  return (
    <form onSubmit={handleSubmit}>
      <h1>Sign in</h1>
      {error ? <p>{error}</p> : null}
      <input
        type="email"
        placeholder="Email"
        value={email}
        onChange={(e) => setEmail(e.target.value)}
        required
      />
      <input
        type="password"
        placeholder="Password"
        value={password}
        onChange={(e) => setPassword(e.target.value)}
        required
      />
      <button type="submit">Sign in</button>
      <p>
        No account? <Link href="/sign-up">Sign up</Link>
      </p>
    </form>
  )
}
`,
                  language: tsLang,
                },
                {
                  label: 'pages/sign-up.tsx',
                  code: `import { useState, type FormEvent } from 'react'
import Link from 'next/link'
import { useRouter } from 'next/router'
import { Account, ID } from 'appwrite'
import { client } from '@/lib/appwrite'

export default function SignUpPage() {
  const router = useRouter()
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')

  async function handleSubmit(e: FormEvent) {
    e.preventDefault()
    setError('')
    try {
      const account = new Account(client)
      await account.create({
        userId: ID.unique(),
        email,
        password,
        name,
      })
      await account.createEmailPasswordSession({ email, password })
      router.push('/')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Sign up failed')
    }
  }

  return (
    <form onSubmit={handleSubmit}>
      <h1>Sign up</h1>
      {error ? <p>{error}</p> : null}
      <input
        type="text"
        placeholder="Name"
        value={name}
        onChange={(e) => setName(e.target.value)}
      />
      <input
        type="email"
        placeholder="Email"
        value={email}
        onChange={(e) => setEmail(e.target.value)}
        required
      />
      <input
        type="password"
        placeholder="Password"
        value={password}
        onChange={(e) => setPassword(e.target.value)}
        required
      />
      <button type="submit">Sign up</button>
      <p>
        Already have an account? <Link href="/sign-in">Sign in</Link>
      </p>
    </form>
  )
}
`,
                  language: tsLang,
                },
              ]),
        ]
      }
      if (frameworkId === 'sveltekit') {
        return [
          { label: envLabel, code: envCode, language: 'env' },
          {
            label: 'src/lib/appwrite.ts',
            code: `import { Client } from 'appwrite'

${clientInitWeb}

export { client }
`,
            language: tsLang,
          },
          {
            label: 'src/routes/+page.ts',
            code: `import { Account } from 'appwrite'
import { client } from '$lib/appwrite'

export async function load() {
  try {
    const account = new Account(client)
    const user = await account.get()
    return { user: { name: user.name } }
  } catch {
    return { user: null }
  }
}
`,
            language: tsLang,
          },
          {
            label: 'src/routes/+page.svelte',
            code: `<script lang="ts">
  export let data: { user: { name: string } | null }
</script>

<div>
  {#if data.user}
    <p>Hello, {data.user.name}</p>
  {:else}
    <p>Sign in to get started.</p>
  {/if}
</div>
`,
            language: 'markup',
          },
        ]
      }
      if (frameworkId === 'angular') {
        return [
          { label: envLabel, code: envCode, language: 'env' },
          {
            label: 'src/lib/appwrite.ts',
            code: `import { Client } from 'appwrite'

${clientInitWeb}

export { client }
`,
            language: tsLang,
          },
          {
            label: 'src/app/appwrite.service.ts',
            code: `import { Injectable } from '@angular/core'
import { Account } from 'appwrite'
import { client } from '../lib/appwrite'

@Injectable({ providedIn: 'root' })
export class AppwriteService {
  private account = new Account(client)

  getUser() {
    return this.account.get()
  }
}
`,
            language: tsLang,
          },
          {
            label: 'src/app/app.component.ts',
            code: `import { Component } from '@angular/core'
import { from } from 'rxjs'
import { AppwriteService } from './appwrite.service'

@Component({
  selector: 'app-root',
  template: \`
    @if (user$ | async; as user) {
      <p>Hello, {{ user.name }}</p>
    } @else {
      <p>Sign in to get started.</p>
    }
  \`,
})
export class AppComponent {
  user$ = from(this.appwrite.getUser().catch(() => null))

  constructor(private appwrite: AppwriteService) {}
}
`,
            language: tsLang,
          },
        ]
      }
      if (frameworkId === 'analog') {
        return [
          { label: envLabel, code: envCode, language: 'env' },
          {
            label: 'src/lib/appwrite.ts',
            code: `import { Client } from 'appwrite'

${clientInitWeb}

export { client }
`,
            language: tsLang,
          },
          {
            label: 'src/app/pages/index.page.ts',
            code: `import { Component, signal } from '@angular/core'
import { Account } from 'appwrite'
import { client } from '../../lib/appwrite'

@Component({
  standalone: true,
  template: \`
    @if (user(); as u) {
      <p>Hello, {{ u.name }}</p>
    } @else {
      <p>Sign in to get started.</p>
    }
  \`,
})
export default class HomePageComponent {
  user = signal<{ name: string } | null>(null)

  constructor() {
    const account = new Account(client)
    account
      .get()
      .then((u) => this.user.set({ name: u.name }))
      .catch(() => {})
  }
}
`,
            language: tsLang,
          },
        ]
      }
      if (frameworkId === 'nuxt') {
        return [
          { label: envLabel, code: envCode, language: 'env' },
          {
            label: 'utils/appwrite.ts',
            code: `import { Client } from 'appwrite'

${clientInitWeb}

export { client }
`,
            language: tsLang,
          },
          {
            label: 'app.vue',
            code: `<script setup lang="ts">
import { Account } from 'appwrite'
import { client } from '~/utils/appwrite'

const { data: user } = await useAsyncData('user', async () => {
  try {
    const account = new Account(client)
    return await account.get()
  } catch {
    return null
  }
})
</script>

<template>
  <div>
    <p v-if="user">Hello, {{ user.name }}</p>
    <p v-else>Sign in to get started.</p>
  </div>
</template>
`,
            language: 'markup',
          },
        ]
      }
      if (frameworkId === 'tanstack') {
        return [
          { label: envLabel, code: envCode, language: 'env' },
          {
            label: 'src/lib/appwrite.ts',
            code: `import { Client } from 'appwrite'

${clientInitWeb}

export { client }
`,
            language: tsLang,
          },
          {
            label: 'src/routes/index.tsx',
            code: `import { createFileRoute } from '@tanstack/react-router'
import { Account } from 'appwrite'
import { client } from '../lib/appwrite'

export const Route = createFileRoute('/')({
  loader: async () => {
    const account = new Account(client)
    const user = await account.get().catch(() => null)
    return { user }
  },
  component: Home,
})

function Home() {
  const { user } = Route.useLoaderData()

  return (
    <div>
      {user ? <p>Hello, {user.name}</p> : <p>Sign in to get started.</p>}
    </div>
  )
}
`,
            language: tsLang,
          },
        ]
      }
      if (frameworkId === 'solid') {
        return [
          { label: envLabel, code: envCode, language: 'env' },
          {
            label: 'src/lib/appwrite.ts',
            code: `import { Client } from 'appwrite'

${clientInitWeb}

export { client }
`,
            language: tsLang,
          },
          {
            label: 'src/App.tsx',
            code: `import { createResource, Show } from 'solid-js'
import { Account } from 'appwrite'
import { client } from './lib/appwrite'

async function fetchUser() {
  const account = new Account(client)
  return account.get().catch(() => null)
}

export default function App() {
  const [user] = createResource(fetchUser)

  return (
    <Show when={user()} fallback={<p>Sign in to get started.</p>}>
      <p>Hello, {user()?.name}</p>
    </Show>
  )
}
`,
            language: tsLang,
          },
        ]
      }
      if (frameworkId === 'solidstart') {
        return [
          { label: envLabel, code: envCode, language: 'env' },
          {
            label: 'src/lib/appwrite.ts',
            code: `import { Client } from 'appwrite'

${clientInitWeb}

export { client }
`,
            language: tsLang,
          },
          {
            label: 'src/routes/index.tsx',
            code: `import { createAsync, query } from '@solidjs/router'
import { Account } from 'appwrite'
import { client } from '../lib/appwrite'

const getUser = query(async () => {
  const account = new Account(client)
  return account.get().catch(() => null)
}, 'user')

export const route = {
  preload: () => getUser(),
}

export default function Home() {
  const user = createAsync(() => getUser())

  return (
    <div>
      {user() ? <p>Hello, {user()?.name}</p> : <p>Sign in to get started.</p>}
    </div>
  )
}
`,
            language: tsLang,
          },
        ]
      }
      // Vanilla / Web
      return [
        { label: envLabel, code: envCode, language: 'env' },
        {
          label: 'main.js',
          code: `import { Client, Account } from 'appwrite'

${clientInitWeb}

const account = new Account(client)
account.get().then((u) => console.log('Hello,', u.name)).catch(console.error)
`,
          language: 'javascript',
        },
      ]
    }
    case 'node': {
      if (frameworkId === 'express') {
        return [
          { label: '.env', code: envCode, language: 'env' },
          {
            label: 'lib/appwrite.ts',
            code: `import { Client } from 'node-appwrite'

${clientInitNode}

export { client }
`,
            language: tsLang,
          },
          {
            label: 'src/index.ts',
            code: `import express from 'express'
import { client } from '../lib/appwrite'
import { Databases } from 'node-appwrite'

const app = express()
const databases = new Databases(client)

app.get('/data', async (req, res) => {
  try {
    const list = await databases.listCollections('your-database-id')
    res.json(list)
  } catch (err) {
    res.status(500).json({ error: String(err) })
  }
})

app.listen(3000, () => console.log('Listening on http://localhost:3000'))
`,
            language: tsLang,
          },
        ]
      }
      if (frameworkId === 'koa') {
        return [
          { label: '.env', code: envCode, language: 'env' },
          {
            label: 'lib/appwrite.ts',
            code: `import { Client } from 'node-appwrite'

${clientInitNode}

export { client }
`,
            language: tsLang,
          },
          {
            label: 'src/index.ts',
            code: `import Koa from 'koa'
import Router from '@koa/router'
import { client } from '../lib/appwrite'
import { Databases } from 'node-appwrite'

const app = new Koa()
const router = new Router()
const databases = new Databases(client)

router.get('/data', async (ctx) => {
  try {
    ctx.body = await databases.listCollections('your-database-id')
  } catch (err) {
    ctx.status = 500
    ctx.body = { error: String(err) }
  }
})

app.use(router.routes())
app.listen(3000, () => console.log('Listening on http://localhost:3000'))
`,
            language: tsLang,
          },
        ]
      }
      return [
        { label: '.env', code: envCode, language: 'env' },
        {
          label: 'src/index.ts',
          code: `import { Client, Databases } from 'node-appwrite'

${clientInitNode}

const databases = new Databases(client)
// const list = await databases.listCollections('your-database-id')
`,
          language: tsLang,
        },
      ]
    }
    case 'bun': {
      if (frameworkId === 'hono') {
        return [
          { label: '.env', code: envCode, language: 'env' },
          {
            label: 'src/lib/appwrite.ts',
            code: `import { Client } from 'node-appwrite'

${clientInitNode}

export { client }
`,
            language: tsLang,
          },
          {
            label: 'src/index.ts',
            code: `import { Hono } from 'hono'
import { client } from './lib/appwrite'
import { Databases } from 'node-appwrite'

const app = new Hono()
const databases = new Databases(client)

app.get('/data', async (c) => {
  try {
    const list = await databases.listCollections('your-database-id')
    return c.json(list)
  } catch (err) {
    return c.json({ error: String(err) }, 500)
  }
})

export default app
`,
            language: tsLang,
          },
        ]
      }
      if (frameworkId === 'elysia') {
        return [
          { label: '.env', code: envCode, language: 'env' },
          {
            label: 'src/lib/appwrite.ts',
            code: `import { Client } from 'node-appwrite'

${clientInitNode}

export { client }
`,
            language: tsLang,
          },
          {
            label: 'src/index.ts',
            code: `import { Elysia } from 'elysia'
import { client } from './lib/appwrite'
import { Databases } from 'node-appwrite'

const databases = new Databases(client)

const app = new Elysia()
  .get('/data', () => databases.listCollections('your-database-id'))
  .listen(3000)

console.log(\`Listening on http://localhost:\${app.server?.port}\`)
`,
            language: tsLang,
          },
        ]
      }
      return [
        { label: '.env', code: envCode, language: 'env' },
        {
          label: 'src/index.ts',
          code: `import { Client, Databases } from 'node-appwrite'

${clientInitNode}

const databases = new Databases(client)
// const list = await databases.listCollections('your-database-id')
`,
          language: tsLang,
        },
      ]
    }
    case 'flutter':
      return [
        { label: '.env', code: envCode, language: 'env' },
        {
          label: 'lib/appwrite_client.dart',
          code: `import 'dart:io' show Platform;
import 'package:appwrite/appwrite.dart';

final client = Client()
  ..setEndpoint(Platform.environment['APPWRITE_ENDPOINT']!)
  ..setProject(Platform.environment['APPWRITE_PROJECT_ID']!);
`,
          language: 'dart',
        },
        {
          label: 'lib/main.dart',
          code: `import 'package:flutter/material.dart';
import 'appwrite_client.dart';
import 'package:appwrite/appwrite.dart';

void main() => runApp(const MyApp());

class MyApp extends StatelessWidget {
  const MyApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      home: Scaffold(
        body: Center(
          child: FutureBuilder(
            future: Account(client).get(),
            builder: (context, snapshot) {
              if (snapshot.hasData) {
                return Text('Hello, \${snapshot.data!.name}');
              }
              return const Text('Sign in to get started.');
            },
          ),
        ),
      ),
    );
  }
}
`,
          language: 'dart',
        },
      ]
    case 'react-native':
      return [
        { label: '.env', code: envCode, language: 'env' },
        {
          label: 'lib/appwrite.ts',
          code: `import { Client } from 'react-native-appwrite'

const client = new Client()
  .setEndpoint(process.env.APPWRITE_ENDPOINT!)
  .setProject(process.env.APPWRITE_PROJECT_ID!)

export { client }
`,
          language: tsLang,
        },
        {
          label: 'App.tsx',
          code: `import { useEffect, useState } from 'react'
import { client } from './lib/appwrite'
import { Account } from 'react-native-appwrite'

export default function App() {
  const [user, setUser] = useState<{ name: string } | null>(null)

  useEffect(() => {
    const account = new Account(client)
    account.get().then((u) => setUser({ name: u.name })).catch(() => {})
  }, [])

  return (
    <div>
      {user ? <p>Hello, {user.name}</p> : <p>Sign in to get started.</p>}
    </div>
  )
}
`,
          language: tsLang,
        },
      ]
    case 'apple':
      return [
        {
          label: 'Config (env or xcconfig)',
          code: envCode,
          language: 'env',
        },
        {
          label: 'AppwriteClient.swift',
          code: `import Appwrite

let client = Client()
  .setEndpoint(ProcessInfo.processInfo.environment["APPWRITE_ENDPOINT"]!)
  .setProject(ProcessInfo.processInfo.environment["APPWRITE_PROJECT_ID"]!)
`,
          language: 'swift',
        },
        {
          label: 'ContentView.swift',
          code: `import SwiftUI

struct ContentView: View {
  var body: some View {
    Text("Use AppwriteClient.client for API calls")
  }
}
`,
          language: 'swift',
        },
      ]
    case 'android':
      return [
        { label: 'Build config / env', code: envCode, language: 'env' },
        {
          label: 'AppwriteClient.kt',
          code: `import io.appwrite.Client

object AppwriteClient {
  val client = Client()
    .setEndpoint(System.getenv("APPWRITE_ENDPOINT"))
    .setProject(System.getenv("APPWRITE_PROJECT_ID"))
}
`,
          language: 'kotlin',
        },
        {
          label: 'MainActivity.kt',
          code: `// Use AppwriteClient.client for API calls
// val account = Account(AppwriteClient.client)
`,
          language: 'kotlin',
        },
      ]
    case 'python':
      return [
        { label: '.env', code: envCode, language: 'env' },
        {
          label: 'main.py',
          code: `import os
from appwrite.client import Client
from appwrite.services.account import Account

client = Client()
client.set_endpoint(os.environ.get("APPWRITE_ENDPOINT"))
client.set_project(os.environ.get("APPWRITE_PROJECT_ID"))
client.set_key(os.environ.get("APPWRITE_API_KEY"))

# Example: get current user
account = Account(client)
user = account.get()
print(f"Hello, {user['name']}")
`,
          language: 'python',
        },
      ]
    case 'dart':
      return [
        { label: '.env', code: envCode, language: 'env' },
        {
          label: 'bin/main.dart',
          code: `import 'dart:io' show Platform;
import 'package:appwrite/appwrite.dart';

final client = Client()
  ..setEndpoint(Platform.environment['APPWRITE_ENDPOINT']!)
  ..setProject(Platform.environment['APPWRITE_PROJECT_ID']!)
  ..setKey(Platform.environment['APPWRITE_API_KEY']!);

void main() async {
  final account = Account(client);
  final user = await account.get();
  print('Hello, \${user.name}');
}
`,
          language: 'dart',
        },
      ]
    case 'php':
      return [
        { label: '.env', code: envCode, language: 'env' },
        {
          label: 'index.php',
          code: `<?php
require_once(__DIR__ . '/vendor/autoload.php');

$client = (new \\Appwrite\\Client())
  ->setEndpoint(getenv('APPWRITE_ENDPOINT'))
  ->setProject(getenv('APPWRITE_PROJECT_ID'))
  ->setKey(getenv('APPWRITE_API_KEY'));

$account = new \\Appwrite\\Services\\Account($client);
$user = $account->get();
echo "Hello, " . $user['name'];
`,
          language: 'php',
        },
      ]
    case 'ruby':
      return [
        { label: '.env', code: envCode, language: 'env' },
        {
          label: 'main.rb',
          code: `require 'appwrite'

client = Appwrite::Client.new
  .set_endpoint(ENV['APPWRITE_ENDPOINT'])
  .set_project(ENV['APPWRITE_PROJECT_ID'])
  .set_key(ENV['APPWRITE_API_KEY'])

account = Appwrite::Services::Account.new(client)
user = account.get
puts "Hello, #{user['name']}"
`,
          language: 'ruby',
        },
      ]
    case 'dotnet':
      return [
        {
          label: '.env or launchSettings',
          code: envCode,
          language: 'env',
        },
        {
          label: 'Program.cs',
          code: `using Appwrite;
using Appwrite.Services;

var client = new Client()
  .SetEndpoint(Environment.GetEnvironmentVariable("APPWRITE_ENDPOINT")!)
  .SetProject(Environment.GetEnvironmentVariable("APPWRITE_PROJECT_ID")!)
  .SetKey(Environment.GetEnvironmentVariable("APPWRITE_API_KEY")!);

var account = new Account(client);
var user = await account.GetAsync();
Console.WriteLine($"Hello, {user.Name}");
`,
          language: 'csharp',
        },
      ]
    case 'go':
      return [
        { label: '.env', code: envCode, language: 'env' },
        {
          label: 'main.go',
          code: `package main

import (
  "os"
  "github.com/appwrite/sdk-for-go"
)

func main() {
  client := appwrite.NewClient()
  client.SetEndpoint(os.Getenv("APPWRITE_ENDPOINT"))
  client.SetProject(os.Getenv("APPWRITE_PROJECT_ID"))
  client.SetKey(os.Getenv("APPWRITE_API_KEY"))

  account := appwrite.NewAccount(client)
  user, _ := account.Get()
  println("Hello,", *user.Name)
}
`,
          language: 'go',
        },
      ]
    case 'swift':
      return [
        { label: '.env or xcconfig', code: envCode, language: 'env' },
        {
          label: 'main.swift',
          code: `import Appwrite

let client = Client()
  .setEndpoint(ProcessInfo.processInfo.environment["APPWRITE_ENDPOINT"]!)
  .setProject(ProcessInfo.processInfo.environment["APPWRITE_PROJECT_ID"]!)
  .setKey(ProcessInfo.processInfo.environment["APPWRITE_API_KEY"]!)

let account = Account(client)
let user = try await account.get()
print("Hello, \\(user.name)")
`,
          language: 'swift',
        },
      ]
    case 'kotlin':
      return [
        { label: '.env or env vars', code: envCode, language: 'env' },
        {
          label: 'Main.kt',
          code: `import io.appwrite.Client
import io.appwrite.services.Account

fun main() {
  val client = Client()
    .setEndpoint(System.getenv("APPWRITE_ENDPOINT"))
    .setProject(System.getenv("APPWRITE_PROJECT_ID"))
    .setKey(System.getenv("APPWRITE_API_KEY"))

  val account = Account(client)
  val user = account.get()
  println("Hello, ${'$'}{user.name}")
}
`,
          language: 'kotlin',
        },
      ]
    case 'deno': {
      const denoSdkSpecifier =
        packageManagerId === 'npm' ? 'npm:node-appwrite' : 'jsr:@appwrite/sdk'
      const clientInitDeno = `const client = new Client()
  .setEndpoint(Deno.env.get("APPWRITE_ENDPOINT")!)
  .setProject(Deno.env.get("APPWRITE_PROJECT_ID")!)
  .setKey(Deno.env.get("APPWRITE_API_KEY")!)`
      if (frameworkId === 'fresh') {
        return [
          { label: '.env', code: envCode, language: 'env' },
          {
            label: 'lib/appwrite.ts',
            code: `import { Client } from "${denoSdkSpecifier}"

${clientInitDeno}

export { client }
`,
            language: tsLang,
          },
          {
            label: 'routes/index.tsx',
            code: `import { Handlers, PageProps } from "$fresh/server.ts"
import { Users } from "${denoSdkSpecifier}"
import { client } from "../lib/appwrite.ts"

export const handler: Handlers = {
  async GET(_req, ctx) {
    const users = new Users(client)
    const list = await users.list().catch(() => null)
    return ctx.render({ total: list?.total ?? 0 })
  },
}

export default function Home({ data }: PageProps<{ total: number }>) {
  return <p>Your project has {data.total} users.</p>
}
`,
            language: tsLang,
          },
        ]
      }
      return [
        { label: '.env', code: envCode, language: 'env' },
        {
          label: 'main.ts',
          code: `import { Client, Account } from "${denoSdkSpecifier}"

${clientInitDeno}

const account = new Account(client)
const user = await account.get()
console.log("Hello,", user.name)
`,
          language: tsLang,
        },
      ]
    }
    case 'java':
      return [
        { label: '.env or env vars', code: envCode, language: 'env' },
        {
          label: 'src/main/java/Main.java',
          code: `import io.appwrite.Client;
import io.appwrite.services.Account;

public class Main {
  public static void main(String[] args) {
    Client client = new Client()
      .setEndpoint(System.getenv("APPWRITE_ENDPOINT"))
      .setProject(System.getenv("APPWRITE_PROJECT_ID"))
      .setKey(System.getenv("APPWRITE_API_KEY"));

    Account account = new Account(client);
    // Use the account service (and others) for API calls
  }
}
`,
          language: 'java',
        },
      ]
    case 'rust':
      return [
        { label: '.env', code: envCode, language: 'env' },
        {
          label: 'src/main.rs',
          code: `use appwrite::client::Client;

fn main() {
    let client = Client::new()
        .set_endpoint(&std::env::var("APPWRITE_ENDPOINT").unwrap())
        .set_project(&std::env::var("APPWRITE_PROJECT_ID").unwrap())
        .set_key(&std::env::var("APPWRITE_API_KEY").unwrap());

    // Use the client with Appwrite services, e.g. account or databases
}
`,
          language: 'rust',
        },
      ]
    default:
      return getCodeFiles(
        'web',
        'vanilla',
        'vite',
        'client',
        endpoint,
        projectId,
        'npm',
      )
  }
}

interface InstallOption {
  label: string
  code: string
  language?: CodeBlockLanguage
}

/** Install instructions per SDK; for web/node can filter by package manager. */
function getInstallInstructions(
  sdkId: string,
  packageManagerId: string,
): { title: string; options: InstallOption[] } {
  switch (sdkId) {
    case 'web': {
      const options: InstallOption[] = [
        { label: 'npm', code: 'npm install appwrite', language: 'bash' },
        { label: 'bun', code: 'bun add appwrite', language: 'bash' },
        { label: 'pnpm', code: 'pnpm add appwrite', language: 'bash' },
        { label: 'yarn', code: 'yarn add appwrite', language: 'bash' },
      ]
      const filtered =
        packageManagerId && packageManagerId !== 'any'
          ? options.filter((o) => o.label === packageManagerId)
          : options
      return { title: 'Install the Web SDK', options: filtered }
    }
    case 'node': {
      const options: InstallOption[] = [
        { label: 'npm', code: 'npm install node-appwrite', language: 'bash' },
        { label: 'bun', code: 'bun add node-appwrite', language: 'bash' },
        { label: 'pnpm', code: 'pnpm add node-appwrite', language: 'bash' },
        { label: 'yarn', code: 'yarn add node-appwrite', language: 'bash' },
      ]
      const filtered =
        packageManagerId && packageManagerId !== 'any'
          ? options.filter((o) => o.label === packageManagerId)
          : options
      return { title: 'Install the Node.js SDK', options: filtered }
    }
    case 'bun': {
      const options: InstallOption[] = [
        { label: 'npm', code: 'npm install node-appwrite', language: 'bash' },
        { label: 'bun', code: 'bun add node-appwrite', language: 'bash' },
        { label: 'pnpm', code: 'pnpm add node-appwrite', language: 'bash' },
        { label: 'yarn', code: 'yarn add node-appwrite', language: 'bash' },
      ]
      const filtered =
        packageManagerId && packageManagerId !== 'any'
          ? options.filter((o) => o.label === packageManagerId)
          : options
      return { title: 'Install the Bun SDK', options: filtered }
    }
    case 'deno': {
      const options: InstallOption[] = [
        {
          label: 'jsr',
          code: 'deno add jsr:@appwrite/sdk',
          language: 'bash',
        },
        {
          label: 'npm',
          code: 'deno add npm:node-appwrite',
          language: 'bash',
        },
      ]
      const filtered =
        packageManagerId && packageManagerId !== 'any'
          ? options.filter((o) => o.label === packageManagerId)
          : options
      return { title: 'Install the Deno SDK', options: filtered }
    }
    case 'flutter':
      return {
        title: 'Install the Flutter SDK',
        options: [
          {
            label: '1. Add to pubspec.yaml',
            code: 'dependencies:\n  appwrite: ^13.0.0',
            language: 'plaintext',
          },
          {
            label: '2. Install packages',
            code: 'flutter pub get',
            language: 'bash',
          },
        ],
      }
    case 'apple':
      return {
        title: 'Install the Apple SDK',
        options: [
          {
            label: 'Xcode (Swift Package Manager)',
            code: 'File → Add Package Dependencies\nhttps://github.com/appwrite/sdk-for-apple',
            language: 'plaintext',
          },
          {
            label: 'Package.swift',
            code: 'dependencies: [\n  .package(url: "https://github.com/appwrite/sdk-for-apple", from: "5.0.0")\n]',
            language: 'swift',
          },
        ],
      }
    case 'android':
      return {
        title: 'Install the Android SDK',
        options: [
          {
            label: '1. Add to build.gradle.kts (module)',
            code: 'dependencies {\n  implementation("io.appwrite:sdk-for-android:5.0.0")\n}',
            language: 'kotlin',
          },
          {
            label: '2. Sync project',
            code: 'Sync your Gradle project in Android Studio.',
            language: 'plaintext',
          },
        ],
      }
    case 'react-native': {
      const options: InstallOption[] = [
        {
          label: 'npm',
          code: 'npm install react-native-appwrite',
          language: 'bash',
        },
        {
          label: 'bun',
          code: 'bun add react-native-appwrite',
          language: 'bash',
        },
        {
          label: 'pnpm',
          code: 'pnpm add react-native-appwrite',
          language: 'bash',
        },
        {
          label: 'yarn',
          code: 'yarn add react-native-appwrite',
          language: 'bash',
        },
      ]
      const filtered =
        packageManagerId && packageManagerId !== 'any'
          ? options.filter((o) => o.label === packageManagerId)
          : options
      return { title: 'Install the React Native SDK', options: filtered }
    }
    case 'python':
      return {
        title: 'Install the Python SDK',
        options: [
          { label: 'pip', code: 'pip install appwrite', language: 'bash' },
        ],
      }
    case 'dart':
      return {
        title: 'Install the Dart SDK',
        options: [
          {
            label: 'Add to pubspec.yaml',
            code: 'dependencies:\n  appwrite: ^13.0.0',
            language: 'plaintext',
          },
          { label: 'Install', code: 'dart pub get', language: 'bash' },
        ],
      }
    case 'php':
      return {
        title: 'Install the PHP SDK',
        options: [
          {
            label: 'Composer',
            code: 'composer require appwrite/appwrite',
            language: 'bash',
          },
        ],
      }
    case 'ruby':
      return {
        title: 'Install the Ruby SDK',
        options: [
          { label: 'Gem', code: 'gem install appwrite', language: 'bash' },
        ],
      }
    case 'dotnet':
      return {
        title: 'Install the .NET SDK',
        options: [
          {
            label: 'NuGet',
            code: 'dotnet add package Appwrite',
            language: 'bash',
          },
        ],
      }
    case 'go':
      return {
        title: 'Install the Go SDK',
        options: [
          {
            label: 'go get',
            code: 'go get github.com/appwrite/sdk-for-go',
            language: 'bash',
          },
        ],
      }
    case 'java':
      return {
        title: 'Install the Java SDK',
        options: [
          {
            label: 'Gradle (build.gradle.kts)',
            code: 'implementation("io.appwrite:sdk-for-kotlin:12.0.0")',
            language: 'kotlin',
          },
          {
            label: 'Maven (pom.xml)',
            code: '<dependency>\n  <groupId>io.appwrite</groupId>\n  <artifactId>sdk-for-kotlin</artifactId>\n  <version>12.0.0</version>\n</dependency>',
            language: 'markup',
          },
        ],
      }
    case 'rust':
      return {
        title: 'Install the Rust SDK',
        options: [
          {
            label: 'cargo',
            code: 'cargo add appwrite',
            language: 'bash',
          },
        ],
      }
    case 'swift':
      return {
        title: 'Install the Swift SDK',
        options: [
          {
            label: 'Swift Package Manager',
            code: '.package(url: "https://github.com/appwrite/sdk-for-swift", from: "13.0.0")',
            language: 'plaintext',
          },
        ],
      }
    case 'kotlin':
      return {
        title: 'Install the Kotlin SDK',
        options: [
          {
            label: 'Add to build.gradle.kts',
            code: 'implementation("io.appwrite:sdk-for-kotlin:12.0.0")',
            language: 'kotlin',
          },
        ],
      }
    default:
      return getInstallInstructions('web', 'npm')
  }
}

export type ConnectProjectTab =
  | 'app'
  | 'cli'
  | 'mcp'
  | 'skills'
  | 'terraform'
  | 's3'

const CONNECT_PROJECT_TAB_IDS = [
  'mcp',
  'app',
  'cli',
  'skills',
  'terraform',
  's3',
] as const satisfies readonly ConnectProjectTab[]

export const DEFAULT_CONNECT_PROJECT_TAB: ConnectProjectTab = 'mcp'

interface ConnectProjectProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  /** Optional initial SDK to preselect (e.g. 'web', 'flutter') */
  initialSdk?: string
  /** Which top-level Connect tab to show when the dialog opens */
  initialConnectTab?: ConnectProjectTab
}

export function ConnectProject({
  open,
  onOpenChange,
  projectId,
  initialSdk = 'web',
  initialConnectTab = DEFAULT_CONNECT_PROJECT_TAB,
}: ConnectProjectProps) {
  const t = useT()
  const navigate = useNavigate()
  const [sdkId, setSdkId] = useState(() => {
    const inClient = CLIENT_SDK_OPTIONS.some((o) => o.id === initialSdk)
    const inServer = SERVER_SDK_OPTIONS.some((o) => o.id === initialSdk)
    if (inClient || inServer) return initialSdk
    return CLIENT_SDK_OPTIONS[0].id
  })
  const [frameworkId, setFrameworkId] = useState('vanilla')
  const [usingId, setUsingId] = useState('vite')
  const [packageManagerId, setPackageManagerId] = useState('npm')

  const frameworks = FRAMEWORK_OPTIONS[sdkId] ?? FRAMEWORK_OPTIONS.web
  const usingVariants = USING_OPTIONS[frameworkId]
  const packageManagers = PACKAGE_MANAGER_OPTIONS[sdkId]
  const isServer = SERVER_SDK_OPTIONS.some((o) => o.id === sdkId)
  const runtime = isServer ? ('server' as const) : ('client' as const)

  useEffect(() => {
    if (!open) return
    const inClient = CLIENT_SDK_OPTIONS.some((o) => o.id === initialSdk)
    const inServer = SERVER_SDK_OPTIONS.some((o) => o.id === initialSdk)
    if (inClient || inServer) setSdkId(initialSdk)
  }, [open, initialSdk])

  useEffect(() => {
    const nextFrameworks = FRAMEWORK_OPTIONS[sdkId] ?? FRAMEWORK_OPTIONS.web
    const nextFwId = nextFrameworks[0]?.id ?? 'vanilla'
    setFrameworkId(nextFwId)
    const variants = USING_OPTIONS[nextFwId]
    setUsingId(variants?.[0]?.id ?? 'vite')
    const nextPm = PACKAGE_MANAGER_OPTIONS[sdkId]
    setPackageManagerId(nextPm?.[0]?.id ?? 'npm')
  }, [sdkId])

  useEffect(() => {
    const variants = USING_OPTIONS[frameworkId]
    if (variants?.length && !variants.some((v) => v.id === usingId)) {
      setUsingId(variants[0].id)
    }
  }, [frameworkId, usingId])

  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const noCreatePermission = !canCreateKey(access, features)
  const createMutation = useCreateApiKey(projectId)
  const endpoint = useMemo(
    () => getApiEndpoint(project?.region),
    [project?.region],
  )

  const codeFiles = useMemo(
    () =>
      getCodeFiles(
        sdkId,
        frameworkId,
        usingId,
        runtime,
        endpoint ?? '',
        projectId ?? '',
        packageManagerId,
      ),
    [
      sdkId,
      frameworkId,
      usingId,
      runtime,
      endpoint,
      projectId,
      packageManagerId,
    ],
  )
  const [connectTab, setConnectTab] = useState<ConnectProjectTab>(
    DEFAULT_CONNECT_PROJECT_TAB,
  )
  const { account } = useAuth()
  const { setTab: persistConnectTab } = useConnectProjectTab(account)

  const selectConnectTab = useCallback(
    (tab: ConnectProjectTab) => {
      setConnectTab(tab)
      persistConnectTab(tab)
    },
    [persistConnectTab],
  )

  useEffect(() => {
    if (!open) return
    setConnectTab(initialConnectTab)
  }, [open, initialConnectTab])

  const [selectedFileIndex, setSelectedFileIndex] = useState(0)
  const [copied, setCopied] = useState(false)
  const [copiedSkillsPrompt, setCopiedSkillsPrompt] = useState<string | null>(
    null,
  )
  const [createDrawerOpen, setCreateDrawerOpen] = useState(false)
  const [createdKeySecret, setCreatedKeySecret] = useState<string | null>(null)
  const [copiedField, setCopiedField] = useState<string | null>(null)
  useEffect(() => {
    setSelectedFileIndex(0)
  }, [sdkId, frameworkId, usingId, runtime])
  const selectedFile = codeFiles[selectedFileIndex] ?? codeFiles[0]
  const handleCopyCode = () => {
    if (!selectedFile) return
    navigator.clipboard.writeText(selectedFile.code)
    setCopied(true)
    toast.success(t('Copied to clipboard'))
    setTimeout(() => setCopied(false), 2000)
  }
  const installInstructions = useMemo(
    () => getInstallInstructions(sdkId, packageManagerId),
    [sdkId, packageManagerId],
  )

  const handleViewApiKeys = () => {
    onOpenChange(false)
    navigate({ to: '/projects/$projectId/api-keys', params: { projectId } })
  }

  const handleCopyKey = (text: string, field: string) => {
    navigator.clipboard.writeText(text)
    setCopiedField(field)
    setTimeout(() => setCopiedField(null), 2000)
  }

  const handleCreateApiKey = (data: {
    name: string
    scopes?: string[]
    expire?: string
  }) => {
    createMutation.mutate(data, {
      onSuccess: (createdKey) => {
        toast.success(t('API key created successfully'))
        if (createdKey?.secret) {
          setCreatedKeySecret(createdKey.secret)
        } else {
          setCreateDrawerOpen(false)
        }
      },
      onError: (error: Error) => {
        toast.error(getErrorMessage(error) || t('Failed to create API key'))
      },
    })
  }

  if (!project) return null

  return (
    <>
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-6xl h-[70dvh] max-h-[70dvh] p-0 gap-0 flex flex-col overflow-hidden">
        <DialogHeader className="shrink-0 px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Connect to your project')}</DialogTitle>
        </DialogHeader>
        <Tabs
          value={connectTab}
          onValueChange={(v) => selectConnectTab(v as ConnectProjectTab)}
          className="min-h-0 flex-1 flex flex-col overflow-hidden"
        >
          <div
            className="shrink-0 flex gap-0 overflow-x-auto border-b border-border px-6"
            role="tablist"
          >
            {CONNECT_PROJECT_TAB_IDS.map((tabId) => {
              const isActive = connectTab === tabId
              const label =
                tabId === 'app'
                  ? 'SDK'
                  : tabId === 'cli'
                    ? 'CLI'
                    : tabId === 'mcp'
                      ? 'MCP'
                      : tabId === 'skills'
                        ? 'Skills'
                        : tabId === 'terraform'
                          ? 'Terraform'
                          : 'S3'
              return (
                <button
                  key={tabId}
                  type="button"
                  role="tab"
                  aria-selected={isActive}
                  onClick={() => selectConnectTab(tabId)}
                  className={cn(
                    'relative flex shrink-0 cursor-pointer focus:cursor-pointer focus-visible:cursor-pointer items-center gap-1.5 px-3 py-2.5 text-[13px] font-medium transition-colors rounded-sm',
                    'focus:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset',
                    isActive
                      ? 'text-foreground'
                      : 'text-muted-foreground hover:text-foreground/80',
                  )}
                >
                  {label}
                  {isActive && (
                    <div className="absolute bottom-0 start-0 end-0 h-[2px] bg-foreground" />
                  )}
                </button>
              )
            })}
          </div>
          <TabsContent
            value="app"
            className="min-h-0 flex-1 overflow-y-auto px-6 pb-4 pt-0 data-[state=inactive]:hidden flex flex-col"
          >
            {/* Selectors above grid: SDK (Client + Server), Framework, Package manager */}
            <div className="shrink-0 flex flex-wrap items-end gap-4 pt-4 pb-4 border-b border-border">
              <div className="min-w-[160px]">
                <label className="text-[12px] font-medium text-muted-foreground uppercase tracking-wider block mb-2">
                  {t('SDK / Platform')}
                </label>
                <Select
                  value={
                    [...CLIENT_SDK_OPTIONS, ...SERVER_SDK_OPTIONS].some(
                      (o) => o.id === sdkId,
                    )
                      ? sdkId
                      : 'web'
                  }
                  onValueChange={setSdkId}
                >
                  <SelectTrigger className="w-full h-9 text-[13px]">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectGroup>
                      <SelectLabel className="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">
                        {t('Client')}
                      </SelectLabel>
                      {CLIENT_SDK_OPTIONS.map((opt) => (
                        <SelectItem
                          key={opt.id}
                          value={opt.id}
                          className="text-[13px]"
                        >
                          <span className="flex items-center gap-1.5">
                            <PlatformIcon platform={opt.platform} size="sm" />
                            {opt.label}
                          </span>
                        </SelectItem>
                      ))}
                    </SelectGroup>
                    <SelectGroup>
                      <SelectLabel className="text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">
                        {t('Server')}
                      </SelectLabel>
                      {SERVER_SDK_OPTIONS.map((opt) => (
                        <SelectItem
                          key={opt.id}
                          value={opt.id}
                          className="text-[13px]"
                        >
                          <span className="flex items-center gap-1.5">
                            <FrameworkIcon framework={opt.id} size="sm" />
                            {opt.label}
                          </span>
                        </SelectItem>
                      ))}
                    </SelectGroup>
                  </SelectContent>
                </Select>
              </div>
              {frameworks.length > 0 && (
                <div className="min-w-[120px]">
                  <label className="text-[12px] font-medium text-muted-foreground uppercase tracking-wider block mb-2">
                    {t('Framework')}
                  </label>
                  <Select
                    value={frameworkId}
                    onValueChange={setFrameworkId}
                    disabled={frameworks.length === 1}
                  >
                    <SelectTrigger className="w-full h-9 text-[13px]">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {frameworks.map((fw) => (
                        <SelectItem
                          key={fw.id}
                          value={fw.id}
                          className="text-[13px]"
                        >
                          <span className="flex items-center gap-1.5">
                            <FrameworkIcon framework={fw.id} size="sm" />
                            {fw.label}
                          </span>
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              )}
              {usingVariants && usingVariants.length > 0 && (
                <div className="min-w-[140px]">
                  <label className="text-[12px] font-medium text-muted-foreground uppercase tracking-wider block mb-2">
                    {t('Using')}
                  </label>
                  <Select
                    value={usingId}
                    onValueChange={setUsingId}
                    disabled={usingVariants.length === 1}
                  >
                    <SelectTrigger className="w-full h-9 text-[13px]">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {usingVariants.map((v) => (
                        <SelectItem
                          key={v.id}
                          value={v.id}
                          className="text-[13px]"
                        >
                          {v.label}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              )}
              {packageManagers && packageManagers.length > 0 && (
                <div className="min-w-[100px]">
                  <label className="text-[12px] font-medium text-muted-foreground uppercase tracking-wider block mb-2">
                    {t('Package manager')}
                  </label>
                  <Select
                    value={packageManagerId}
                    onValueChange={setPackageManagerId}
                    disabled={packageManagers.length === 1}
                  >
                    <SelectTrigger className="w-full h-9 text-[13px]">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {packageManagers.map((pm) => (
                        <SelectItem
                          key={pm.id}
                          value={pm.id}
                          className="text-[13px]"
                        >
                          <span className="flex items-center gap-1.5">
                            <PackageManagerIcon
                              packageManager={pm.id}
                              size="sm"
                            />
                            {pm.label}
                          </span>
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              )}
            </div>

            <div className="grid grid-cols-[0.9fr_1.4fr] gap-6 pt-4 min-h-0 flex-1">
              {/* Left: install instructions + API keys when server */}
              <div className="space-y-4 min-w-0 min-h-0 overflow-y-auto">
                <div className="space-y-4">
                  <h4 className="text-[13px] font-semibold text-foreground">
                    {t(installInstructions.title)}
                  </h4>
                  <div className="space-y-4">
                    {installInstructions.options.map((option, i) => (
                      <CodeBlock
                        key={i}
                        code={option.code}
                        language={option.language ?? 'plaintext'}
                        label={t(option.label)}
                        showCopy={true}
                      />
                    ))}
                  </div>
                </div>
                {isServer && (
                  <div className="rounded-xl border border-border bg-muted/30 overflow-hidden">
                    <div className="px-4 py-3 border-b border-border">
                      <h4 className="text-[13px] font-semibold text-foreground">
                        {t('API keys')}
                      </h4>
                    </div>
                    <div className="px-4 py-3 space-y-3">
                      <p className="text-[13px] text-muted-foreground">
                        {t(
                          'Server and backend code need an API key with the right scopes. Create and manage keys in your project.',
                        )}
                      </p>
                      <div className="flex flex-wrap items-center gap-2">
                        <Button
                          type="button"
                          variant="secondary"
                          size="sm"
                          className="h-8 gap-1.5 text-[12px]"
                          onClick={() => setCreateDrawerOpen(true)}
                          disabled={noCreatePermission}
                          title={
                            noCreatePermission
                              ? t(
                                  "You don't have permission to create API keys.",
                                )
                              : undefined
                          }
                          {...analyticsAttrs('create-api-key')}
                        >
                          <Plus className="h-3.5 w-3.5" />
                          {t('Create API key')}
                        </Button>
                        <Button
                          type="button"
                          variant="secondary"
                          size="sm"
                          className="h-8 gap-1.5 text-[12px]"
                          onClick={handleViewApiKeys}
                        >
                          <Key className="h-3.5 w-3.5" />
                          {t('View API keys')}
                        </Button>
                      </div>
                      <DocsRouteLink
                        href={`${APPWRITE_DOCS_URL}/getting-started-for-server`}
                        className="inline-flex items-center gap-1.5 link-neutral text-[13px]"
                      >
                        {t('Server setup guide')}
                        <ExternalLink className="h-3.5 w-3.5" />
                      </DocsRouteLink>
                    </div>
                  </div>
                )}
                <DocsRouteLink
                  href={APPWRITE_DOCS_URL}
                  className="inline-flex items-center gap-1.5 link-neutral text-[13px]"
                >
                  {t('Read the docs')}
                  <ExternalLink className="h-3.5 w-3.5" />
                </DocsRouteLink>
              </div>
              {/* Right: File-based code examples */}
              <div className="min-w-0 min-h-0 flex flex-col gap-2 flex-1">
                <div className="shrink-0 flex flex-wrap items-center justify-between gap-2">
                  {codeFiles.length > 1 ? (
                    <div className="flex flex-wrap gap-1.5">
                      {codeFiles.map((file, i) => (
                        <button
                          key={file.label}
                          type="button"
                          onClick={() => setSelectedFileIndex(i)}
                          className={cn(
                            'cursor-pointer rounded-md px-2.5 py-1 text-[12px] font-medium transition-colors',
                            i === selectedFileIndex
                              ? 'bg-muted text-foreground'
                              : 'text-muted-foreground hover:text-foreground hover:bg-muted/70',
                          )}
                        >
                          {t(file.label)}
                        </button>
                      ))}
                    </div>
                  ) : (
                    <span />
                  )}
                  {selectedFile && (
                    <Button
                      variant="ghost"
                      size="sm"
                      className="h-7 gap-1 text-[12px] text-muted-foreground shrink-0"
                      onClick={handleCopyCode}
                    >
                      {copied ? (
                        <Check className="h-3.5 w-3.5" />
                      ) : (
                        <Copy className="h-3.5 w-3.5" />
                      )}
                      {t('Copy')}
                    </Button>
                  )}
                </div>
                {selectedFile && (
                  <ConnectCodePanel
                    code={selectedFile.code}
                    language={selectedFile.language ?? 'plaintext'}
                    fixedHeight="100%"
                    className="flex-1 min-h-0"
                  />
                )}
              </div>
            </div>
          </TabsContent>
          <TabsContent
            value="cli"
            className="min-h-0 flex-1 overflow-hidden px-6 pb-4 pt-0 data-[state=inactive]:hidden flex flex-col"
          >
            <CLISection
              endpoint={endpoint ?? getBaseEndpoint()}
              projectId={projectId ?? ''}
              onViewApiKeys={handleViewApiKeys}
              onClose={() => onOpenChange(false)}
            />
          </TabsContent>
          <TabsContent
            value="mcp"
            className="min-h-0 flex-1 overflow-y-auto px-6 pb-4 pt-0 data-[state=inactive]:hidden"
          >
            <MCPSection
              compact
              projectId={projectId}
              projectName={project?.name ?? projectId}
            />
          </TabsContent>
          <TabsContent
            value="skills"
            className="min-h-0 flex-1 overflow-hidden px-6 pb-4 pt-0 data-[state=inactive]:hidden flex flex-col"
          >
            <div className="flex flex-col gap-4 pt-4 min-h-0 flex-1">
              <div className="shrink-0 space-y-2">
                <p className="text-[13px] text-muted-foreground">
                  {t(
                    'SDK context for your AI agent: accurate methods, patterns, and best practices. For live project actions like listing users, use MCP.',
                  )}
                </p>
                <p className="text-[13px] text-muted-foreground">
                  {t('Skills are available for')}{' '}
                  {[
                    'CLI',
                    'TypeScript',
                    'Dart',
                    '.NET',
                    'Go',
                    'Kotlin',
                    'PHP',
                    'Python',
                    'Ruby',
                    'Swift',
                  ].map((sdk, i) => (
                    <span key={sdk}>
                      <span className="rounded bg-muted/80 px-1.5 py-0.5 text-[12px] font-medium text-muted-foreground">
                        {sdk}
                      </span>
                      {i < 9 ? ', ' : ''}
                    </span>
                  ))}{' '}
                  - {t('pick what you use during setup.')}
                </p>
                <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
                  <DocsRouteLink
                    href={APPWRITE_SKILLS_DOCS_URL}
                    className="inline-flex items-center gap-1.5 link-neutral text-[13px]"
                  >
                    {t('Docs')}
                    <ExternalLink className="h-3.5 w-3.5" />
                  </DocsRouteLink>
                  <a
                    href={APPWRITE_AGENT_SKILLS_REPO}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="inline-flex items-center gap-1.5 link-neutral text-[13px]"
                  >
                    <GitHubIcon className="h-3.5 w-3.5" />
                    appwrite/skills
                  </a>
                </div>
              </div>

              <div className="grid grid-cols-2 gap-4 min-h-0 flex-1">
                <div className="min-w-0 rounded-xl border border-border bg-card/50 overflow-hidden flex flex-col">
                  <div className="px-4 py-2.5 border-b border-border shrink-0">
                    <h4 className="text-[13px] font-semibold text-foreground">
                      {t('1. Install')}
                    </h4>
                    <p className="text-[12px] text-muted-foreground mt-0.5">
                      {t('Run in project root.')}
                    </p>
                  </div>
                  <div className="px-4 py-3 shrink-0">
                    <CodeBlock
                      code={APPWRITE_AGENT_SKILLS_INSTALL}
                      language="bash"
                      label={t('Terminal')}
                      showCopy={true}
                    />
                  </div>
                  <div className="px-4 py-2.5 border-t border-border">
                    <p className="text-[12px] font-medium text-foreground mb-1">
                      {t('Then the CLI will ask:')}
                    </p>
                    <ul className="text-[12px] text-muted-foreground space-y-0.5">
                      <li>
                        <span className="text-foreground font-medium">
                          {t('Skills')}
                        </span>{' '}
                        - {t('which SDKs to install (e.g. TypeScript, Go).')}
                      </li>
                      <li>
                        <span className="text-foreground font-medium">
                          {t('Tools')}
                        </span>{' '}
                        - {t('which AI tools use them (Cursor, Claude, etc.).')}
                      </li>
                      <li>
                        <span className="text-foreground font-medium">
                          {t('Scope')}
                        </span>{' '}
                        - {t('project (this repo) or global.')}
                      </li>
                      <li>
                        <span className="text-foreground font-medium">
                          {t('Method')}
                        </span>{' '}
                        - {t('prefer symlink so skills stay up to date.')}
                      </li>
                    </ul>
                  </div>
                </div>

                <div className="min-w-0 flex flex-col gap-2.5 min-h-0">
                  <div className="shrink-0">
                    <h4 className="text-[13px] font-semibold text-foreground">
                      {t('2. Try it')}
                    </h4>
                    <p className="text-[12px] text-muted-foreground mt-0.5">
                      {t('Ask your agent to write Appwrite code:')}
                    </p>
                  </div>
                  <ul className="space-y-1.5 min-h-0">
                    {SKILLS_TRY_IT_PROMPTS.map((prompt) => (
                      <li
                        key={prompt}
                        className="flex items-center gap-2 rounded-lg border border-border bg-muted/20 px-3 py-1.5"
                      >
                        <span className="min-w-0 flex-1 text-[12px] font-medium text-foreground leading-snug">
                          {t(prompt)}
                        </span>
                        <Button
                          variant="ghost"
                          size="sm"
                          className="h-7 gap-1 text-[12px] text-muted-foreground shrink-0"
                          onClick={() => {
                            navigator.clipboard.writeText(prompt)
                            setCopiedSkillsPrompt(prompt)
                            toast.success(t('Copied to clipboard'))
                            setTimeout(() => setCopiedSkillsPrompt(null), 2000)
                          }}
                        >
                          {copiedSkillsPrompt === prompt ? (
                            <Check className="h-3.5 w-3.5" />
                          ) : (
                            <Copy className="h-3.5 w-3.5" />
                          )}
                          {t('Copy')}
                        </Button>
                      </li>
                    ))}
                  </ul>
                </div>
              </div>
            </div>
          </TabsContent>
          <TabsContent
            value="terraform"
            className="min-h-0 flex-1 overflow-hidden px-6 pb-4 pt-0 data-[state=inactive]:hidden flex flex-col"
          >
            <TerraformConnectSection
              endpoint={endpoint ?? getBaseEndpoint()}
              projectId={projectId ?? ''}
              onViewApiKeys={handleViewApiKeys}
            />
          </TabsContent>
          <TabsContent
            value="s3"
            className="min-h-0 flex-1 overflow-hidden px-6 pb-4 pt-0 data-[state=inactive]:hidden flex flex-col"
          >
            <S3ConnectSection
              projectId={projectId ?? ''}
              onViewApiKeys={handleViewApiKeys}
            />
          </TabsContent>
        </Tabs>
        <div className="shrink-0 px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            size="sm"
            onClick={() => onOpenChange(false)}
          >
            {t('Close')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>

    <ApiKeyDrawer
      open={createDrawerOpen}
      onOpenChange={(nextOpen) => {
        setCreateDrawerOpen(nextOpen)
        if (!nextOpen) setCreatedKeySecret(null)
      }}
      onSubmit={handleCreateApiKey}
      isLoading={createMutation.isPending}
      createdKeySecret={createdKeySecret}
      onCopy={handleCopyKey}
      copiedField={copiedField}
      initialName={t(SDK_API_KEY_DEFAULT_NAME)}
    />
    </>
  )
}
