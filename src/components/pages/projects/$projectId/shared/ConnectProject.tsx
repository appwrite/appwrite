/**
 * Connect to your project – simplified modal for project credentials and SDK setup.
 * Adapted from Supabase-style connect flow; tailored to Appwrite (endpoint, project ID, API keys).
 */

import { useState, useMemo, useEffect } from 'react'
import { Check, Copy, ExternalLink, Key } from 'lucide-react'
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
import { useProject } from '@/lib/react-query/hooks'
import { toast } from 'sonner'
import { cn } from '@/lib/utils'
import { getApiEndpoint, getBaseEndpoint } from '@/lib/appwrite/sdk'
import { PlatformIcon } from '@/components/global/shared/Icon'
import { FrameworkIcon } from '@/components/global/shared/FrameworkIcon'
import { PackageManagerIcon } from '@/components/global/shared/PackageManagerIcon'
import { DocsRouteLink } from '@/components/pages/docs/DocsRouteLink'
import { MCPSection } from '@/components/pages/projects/$projectId/shared/MCPSection'
import { TerraformConnectSection } from '@/components/pages/projects/$projectId/shared/TerraformConnectSection'
import { ConnectCodePanel } from '@/components/global/shared/ConnectCodeExample'
import {
  CodeBlock,
  type CodeBlockLanguage,
} from '@/components/global/shared/CodeBlock'
import { Tabs, TabsContent } from '@/components/ui/tabs'

const APPWRITE_DOCS_URL = '/docs'
const APPWRITE_CLI_INSTALL_URL =
  '/docs/tooling/command-line/installation'
const APPWRITE_CLI_DOCS_URL =
  '/docs/tooling/command-line/commands'
const APPWRITE_SKILLS_DOCS_URL = '/docs/tooling/skills'
const APPWRITE_AGENT_SKILLS_REPO = 'https://github.com/appwrite/agent-skills'

function GitHubIcon({ className }: { className?: string }) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="currentColor"
      className={className}
      xmlns="http://www.w3.org/2000/svg"
    >
      <path d="M12 0c-6.626 0-12 5.373-12 12 0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23.957-.266 1.983-.399 3.003-.404 1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576 4.765-1.589 8.199-6.086 8.199-11.386 0-6.627-5.373-12-12-12z" />
    </svg>
  )
}

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
  { id: 'python', platform: 'web', label: 'Python' },
  { id: 'dart', platform: 'web', label: 'Dart' },
  { id: 'php', platform: 'web', label: 'PHP' },
  { id: 'ruby', platform: 'web', label: 'Ruby' },
  { id: 'dotnet', platform: 'web', label: '.NET' },
  { id: 'go', platform: 'web', label: 'Go' },
  { id: 'swift', platform: 'apple', label: 'Swift' },
  { id: 'kotlin', platform: 'android', label: 'Kotlin' },
]

/** Framework options per SDK (id + label). Aligned with https://appwrite.io/docs/quick-starts */
const FRAMEWORK_OPTIONS: Record<string, { id: string; label: string }[]> = {
  web: [
    { id: 'next', label: 'Next.js' },
    { id: 'react', label: 'React' },
    { id: 'vue', label: 'Vue.js' },
    { id: 'sveltekit', label: 'SvelteKit' },
    { id: 'angular', label: 'Angular' },
    { id: 'nuxt', label: 'Nuxt' },
    { id: 'refine', label: 'Refine' },
    { id: 'solid', label: 'Solid' },
    { id: 'tanstack', label: 'TanStack Start' },
    { id: 'vanilla', label: 'Vanilla' },
  ],
  node: [
    { id: 'vanilla', label: 'Node' },
    { id: 'express', label: 'Express' },
  ],
  flutter: [{ id: 'flutter', label: 'Flutter' }],
  'react-native': [{ id: 'react-native', label: 'React Native' }],
  apple: [{ id: 'swift', label: 'Swift' }],
  android: [{ id: 'kotlin', label: 'Kotlin' }],
  python: [{ id: 'python', label: 'Python' }],
  dart: [{ id: 'dart', label: 'Dart' }],
  php: [{ id: 'php', label: 'PHP' }],
  ruby: [{ id: 'ruby', label: 'Ruby' }],
  dotnet: [{ id: 'dotnet', label: '.NET' }],
  go: [{ id: 'go', label: 'Go' }],
  swift: [{ id: 'swift', label: 'Swift' }],
  kotlin: [{ id: 'kotlin', label: 'Kotlin' }],
  deno: [{ id: 'deno', label: 'Deno' }],
}

/** "Using" variants per framework (e.g. React: Vite vs CRA; Next: App Router vs Pages Router). */
const USING_OPTIONS: Record<string, { id: string; label: string }[]> = {
  react: [
    { id: 'vite', label: 'Vite' },
    { id: 'cra', label: 'Create React App' },
  ],
  next: [
    { id: 'app', label: 'App Router' },
    { id: 'pages', label: 'Pages Router' },
  ],
}

/** Package manager options for web/node; null = not applicable (use all in install). */
const PACKAGE_MANAGER_OPTIONS: Record<
  string,
  { id: string; label: string }[] | null
> = {
  web: [
    { id: 'npm', label: 'npm' },
    { id: 'bun', label: 'bun' },
    { id: 'pnpm', label: 'pnpm' },
  ],
  node: [
    { id: 'npm', label: 'npm' },
    { id: 'bun', label: 'bun' },
    { id: 'pnpm', label: 'pnpm' },
  ],
  flutter: null,
  apple: null,
  android: null,
  deno: null,
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

/** Returns file-based code snippets tailored to the selected SDK, framework, and variant (using). */
function getCodeFiles(
  sdkId: string,
  frameworkId: string,
  usingId: string,
  runtime: 'client' | 'server',
  endpoint: string,
  projectId: string,
): CodeFile[] {
  const envCode = getEnvExample(
    sdkId,
    runtime,
    frameworkId,
    usingId,
    endpoint,
    projectId,
  )
  const envLabel = frameworkId === 'next' ? '.env.local' : '.env'
  const tsLang = 'typescript' as CodeBlockLanguage

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
        return [
          { label: envLabel, code: envCode, language: 'env' },
          {
            label: 'lib/appwrite.ts',
            code: `import { Client } from 'appwrite'

${clientInitWeb}

export { client }
`,
            language: tsLang,
          },
          {
            label: 'src/App.tsx',
            code: `import { useEffect, useState } from 'react'
import { client } from '../lib/appwrite'
import { Account } from 'appwrite'

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
                  code: `import { client } from '@/lib/appwrite'
import { Account } from 'appwrite'

export default async function HomePage() {
  try {
    const account = new Account(client)
    const user = await account.get()
    return <p>Hello, {user.name}</p>
  } catch {
    return <p>Sign in to get started.</p>
  }
}
`,
                  language: tsLang,
                },
              ]
            : [
                {
                  label: 'pages/index.tsx',
                  code: `import { useEffect, useState } from 'react'
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
      {user ? <p>Hello, {user.name}</p> : <p>Sign in to get started.</p>}
    </div>
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
      if (frameworkId === 'refine') {
        return [
          { label: envLabel, code: envCode, language: 'env' },
          {
            label: 'src/lib/appwrite.ts',
            code: `import { Client } from 'appwrite'\n\n${clientInitWeb}\n\nexport { client }\n`,
            language: tsLang,
          },
          {
            label: 'src/App.tsx',
            code: `import { client } from './lib/appwrite'\nimport { Account } from 'appwrite'\nconst account = new Account(client)\naccount.get().then((u) => console.log('Hello,', u.name)).catch(console.error)\n`,
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
    case 'deno':
      return [
        { label: '.env', code: envCode, language: 'env' },
        {
          label: 'main.ts',
          code: `import { Client, Account } from "jsr:/@appwrite/sdk"

const client = new Client()
  .setEndpoint(Deno.env.get("APPWRITE_ENDPOINT")!)
  .setProject(Deno.env.get("APPWRITE_PROJECT_ID")!)
  .setKey(Deno.env.get("APPWRITE_API_KEY")!)

const account = new Account(client)
const user = await account.get()
console.log("Hello,", user.name)
`,
          language: tsLang,
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
      ]
      const filtered =
        packageManagerId && packageManagerId !== 'any'
          ? options.filter((o) => o.label === packageManagerId)
          : options
      return { title: 'Install the Node.js SDK', options: filtered }
    }
    case 'deno':
      return {
        title: 'Install the Deno SDK',
        options: [
          {
            label: 'Import from JSR',
            code: 'import { Client } from "jsr:/@appwrite/sdk"',
            language: 'typescript',
          },
        ],
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
    case 'react-native':
      return {
        title: 'Install the React Native SDK',
        options: [
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
        ],
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
  'app',
  'cli',
  'mcp',
  'skills',
  'terraform',
  's3',
] as const satisfies readonly ConnectProjectTab[]

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
  initialConnectTab = 'app',
}: ConnectProjectProps) {
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
      ),
    [sdkId, frameworkId, usingId, runtime, endpoint, projectId],
  )
  const [connectTab, setConnectTab] = useState<ConnectProjectTab>('app')
  useEffect(() => {
    if (!open) return
    setConnectTab(initialConnectTab)
  }, [open, initialConnectTab])

  const [cliInstallOs, setCliInstallOs] = useState<
    'macos' | 'windows' | 'linux'
  >(() => {
    if (typeof navigator === 'undefined') return 'macos'
    const ua = navigator.userAgent.toLowerCase()
    const platform = navigator.platform?.toLowerCase() ?? ''
    if (
      /platform|win32|win64|wow64|windows/.test(platform) ||
      /windows|win32|wow64/.test(ua)
    )
      return 'windows'
    if (/mac|darwin|iphone|ipad/.test(platform) || /macintosh|mac os/.test(ua))
      return 'macos'
    return 'linux'
  })
  const [selectedFileIndex, setSelectedFileIndex] = useState(0)
  const [copied, setCopied] = useState(false)
  useEffect(() => {
    setSelectedFileIndex(0)
  }, [sdkId, frameworkId, usingId, runtime])
  const selectedFile = codeFiles[selectedFileIndex] ?? codeFiles[0]
  const handleCopyCode = () => {
    if (!selectedFile) return
    navigator.clipboard.writeText(selectedFile.code)
    setCopied(true)
    toast.success('Copied to clipboard')
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

  const { account } = useAuth()
  const userEmail = (account as { email?: string } | null)?.email ?? ''
  const cliLoginCommand = `appwrite login --email ${userEmail || 'your@email.com'} --password yourpassword`
  const [cliLoginCopied, setCliLoginCopied] = useState(false)

  const handleCopyCliLogin = () => {
    navigator.clipboard.writeText(cliLoginCommand)
    setCliLoginCopied(true)
    toast.success('Copied to clipboard')
    setTimeout(() => setCliLoginCopied(false), 2000)
  }

  if (!project) return null

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-6xl h-[70dvh] max-h-[70dvh] p-0 gap-0 flex flex-col overflow-hidden">
        <DialogHeader className="shrink-0 px-6 pt-6 pb-4 text-left">
          <DialogTitle>Connect to your project</DialogTitle>
        </DialogHeader>
        <Tabs
          value={connectTab}
          onValueChange={(v) => setConnectTab(v as ConnectProjectTab)}
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
                  onClick={() => setConnectTab(tabId)}
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
                    <div className="absolute bottom-0 left-0 right-0 h-[2px] bg-foreground" />
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
                  SDK / Platform
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
                        Client
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
                        Server
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
              {frameworks.length > 1 && (
                <div className="min-w-[120px]">
                  <label className="text-[12px] font-medium text-muted-foreground uppercase tracking-wider block mb-2">
                    Framework
                  </label>
                  <Select value={frameworkId} onValueChange={setFrameworkId}>
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
              {usingVariants && usingVariants.length > 1 && (
                <div className="min-w-[140px]">
                  <label className="text-[12px] font-medium text-muted-foreground uppercase tracking-wider block mb-2">
                    Using
                  </label>
                  <Select value={usingId} onValueChange={setUsingId}>
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
              {packageManagers && packageManagers.length > 1 && (
                <div className="min-w-[100px]">
                  <label className="text-[12px] font-medium text-muted-foreground uppercase tracking-wider block mb-2">
                    Package manager
                  </label>
                  <Select
                    value={packageManagerId}
                    onValueChange={setPackageManagerId}
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
                    {installInstructions.title}
                  </h4>
                  <div className="space-y-4">
                    {installInstructions.options.map((option, i) => (
                      <CodeBlock
                        key={i}
                        code={option.code}
                        language={option.language ?? 'plaintext'}
                        label={option.label}
                        showCopy={true}
                      />
                    ))}
                  </div>
                </div>
                {isServer && (
                  <div className="rounded-xl border border-border bg-muted/30 overflow-hidden">
                    <div className="px-4 py-3 border-b border-border">
                      <h4 className="text-[13px] font-semibold text-foreground">
                        API keys
                      </h4>
                    </div>
                    <div className="px-4 py-3 space-y-3">
                      <p className="text-[13px] text-muted-foreground">
                        Server and backend code need an API key with the right
                        scopes. Create and manage keys in your project.
                      </p>
                      <div className="flex flex-wrap items-center gap-2">
                        <Button
                          variant="secondary"
                          size="sm"
                          className="h-9 text-[13px] gap-1.5"
                          onClick={handleViewApiKeys}
                        >
                          <Key className="h-4 w-4" />
                          View API keys
                        </Button>
                        <DocsRouteLink
                          href={`${APPWRITE_DOCS_URL}/getting-started-for-server`}
                          className="inline-flex items-center gap-1.5 link-neutral text-[13px]"
                        >
                          Server setup guide
                          <ExternalLink className="h-3.5 w-3.5" />
                        </DocsRouteLink>
                      </div>
                    </div>
                  </div>
                )}
                <DocsRouteLink
                  href={APPWRITE_DOCS_URL}
                  className="inline-flex items-center gap-1.5 link-neutral text-[13px]"
                >
                  Read the docs
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
                          {file.label}
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
                      Copy
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
            className="min-h-0 flex-1 overflow-y-auto px-6 pb-4 pt-0 data-[state=inactive]:hidden"
          >
            <div className="space-y-4 pt-4">
              <p className="text-[13px] text-muted-foreground">
                Use the Appwrite CLI to manage your project from the terminal.
                Install the CLI, log in, then point it at this project.
              </p>
              <div className="space-y-3">
                <h4 className="text-[13px] font-semibold text-foreground">
                  1. Install the CLI
                </h4>
                <div className="flex flex-wrap gap-1 rounded-lg border border-border bg-muted/30 p-1 w-fit mb-4">
                  {(['macos', 'windows', 'linux'] as const).map((os) => (
                    <button
                      key={os}
                      type="button"
                      onClick={() => setCliInstallOs(os)}
                      className={cn(
                        'cursor-pointer rounded-md px-3 py-1.5 text-[12px] font-medium transition-colors',
                        cliInstallOs === os
                          ? 'bg-background text-foreground shadow-sm'
                          : 'text-muted-foreground hover:text-foreground',
                      )}
                    >
                      {os === 'macos'
                        ? 'macOS'
                        : os === 'windows'
                          ? 'Windows'
                          : 'Linux'}
                    </button>
                  ))}
                </div>
                <div className="space-y-4">
                  {cliInstallOs === 'macos' && (
                    <>
                      <CodeBlock
                        code="npm install -g appwrite-cli"
                        language="bash"
                        label="npm"
                        showCopy
                      />
                      <CodeBlock
                        code="brew install appwrite"
                        language="bash"
                        label="Homebrew"
                        showCopy
                      />
                      <CodeBlock
                        code="curl -sL https://appwrite.io/cli/install.sh | bash"
                        language="bash"
                        label="Install script"
                        showCopy
                      />
                    </>
                  )}
                  {cliInstallOs === 'windows' && (
                    <>
                      <CodeBlock
                        code="npm install -g appwrite-cli"
                        language="bash"
                        label="npm"
                        showCopy
                      />
                      <CodeBlock
                        code="iwr -useb https://appwrite.io/cli/install.ps1 | iex"
                        language="bash"
                        label="PowerShell"
                        showCopy
                      />
                      <CodeBlock
                        code="scoop install https://raw.githubusercontent.com/appwrite/sdk-for-cli/master/scoop/appwrite.config.json"
                        language="bash"
                        label="Scoop"
                        showCopy
                      />
                    </>
                  )}
                  {cliInstallOs === 'linux' && (
                    <>
                      <CodeBlock
                        code="npm install -g appwrite-cli"
                        language="bash"
                        label="npm"
                        showCopy
                      />
                      <CodeBlock
                        code="curl -sL https://appwrite.io/cli/install.sh | bash"
                        language="bash"
                        label="Install script"
                        showCopy
                      />
                    </>
                  )}
                </div>
                <DocsRouteLink
                  href={APPWRITE_CLI_INSTALL_URL}
                  className="inline-flex items-center gap-1.5 link-neutral text-[13px]"
                >
                  Full installation guide
                  <ExternalLink className="h-3.5 w-3.5" />
                </DocsRouteLink>
              </div>
              <div className="space-y-3">
                <h4 className="text-[13px] font-semibold text-foreground">
                  2. Log in
                </h4>
                <div className="space-y-1.5">
                  <div className="flex items-center justify-between">
                    <span className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                      Terminal
                    </span>
                    <Button
                      variant="ghost"
                      size="sm"
                      className="h-7 gap-1 text-[12px] text-muted-foreground hover:text-foreground"
                      onClick={handleCopyCliLogin}
                    >
                      {cliLoginCopied ? (
                        <Check className="h-3.5 w-3.5" />
                      ) : (
                        <Copy className="h-3.5 w-3.5" />
                      )}
                      Copy
                    </Button>
                  </div>
                  <div className="relative rounded-xl border border-border overflow-hidden bg-background">
                    <pre className="overflow-x-auto p-4 text-[12px] font-mono text-left m-0 bg-background">
                      <code>
                        appwrite login --email{' '}
                        <span
                          className="blur-[5px] select-none"
                          title="Your email (blurred)"
                        >
                          {userEmail || 'your@email.com'}
                        </span>{' '}
                        --password yourpassword
                      </code>
                    </pre>
                  </div>
                </div>
              </div>
              <div className="space-y-3">
                <h4 className="text-[13px] font-semibold text-foreground">
                  3. Connect to this project
                </h4>
                <CodeBlock
                  code={`appwrite client --endpoint ${endpoint ?? getBaseEndpoint()} --project-id ${projectId ?? 'YOUR_PROJECT_ID'}`}
                  language="bash"
                  label="Terminal"
                  showCopy
                />
                <p className="text-[13px] text-muted-foreground">
                  For non-interactive use (CI/CD), add{' '}
                  <code className="rounded bg-muted px-1 py-0.5 text-[12px]">
                    --key YOUR_API_KEY
                  </code>
                  . Create API keys in your project settings.
                </p>
              </div>
              <DocsRouteLink
                href={APPWRITE_CLI_DOCS_URL}
                className="inline-flex items-center gap-1.5 link-neutral text-[13px]"
              >
                CLI commands
                <ExternalLink className="h-3.5 w-3.5" />
              </DocsRouteLink>
            </div>
          </TabsContent>
          <TabsContent
            value="mcp"
            className="min-h-0 flex-1 overflow-y-auto px-6 pb-4 pt-0 data-[state=inactive]:hidden"
          >
            <MCPSection compact />
          </TabsContent>
          <TabsContent
            value="skills"
            className="min-h-0 flex-1 overflow-y-auto px-6 pb-4 pt-0 data-[state=inactive]:hidden flex flex-col"
          >
            <div className="grid grid-cols-[0.9fr_1.4fr] gap-6 pt-4 min-h-0 flex-1">
              {/* Left: description + supported SDKs as flowing text */}
              <div className="min-w-0 min-h-0 overflow-y-auto">
                <p className="text-[13px] text-muted-foreground">
                  Give your AI agent accurate Appwrite SDK context-method
                  signatures, patterns, and best practices for your language.
                  Install once per project or globally; works in Cursor, Claude
                  Code, and other compatible tools.
                </p>
                <p className="text-[13px] text-muted-foreground mt-3">
                  Skills are available for{' '}
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
                  - pick what you use during setup.
                </p>
                <div className="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2">
                  <DocsRouteLink
                    href={APPWRITE_SKILLS_DOCS_URL}
                    className="inline-flex items-center gap-1.5 link-neutral text-[13px]"
                  >
                    Docs
                    <ExternalLink className="h-3.5 w-3.5" />
                  </DocsRouteLink>
                </div>
              </div>
              {/* Right (main): install command */}
              <div className="min-w-0 space-y-0">
                <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
                  <div className="px-4 py-3 border-b border-border">
                    <h4 className="text-[13px] font-semibold text-foreground">
                      Install command
                    </h4>
                    <p className="text-[12px] text-muted-foreground mt-1">
                      Run in project root. You’ll pick SDKs, tools, and scope.
                    </p>
                  </div>
                  <div className="px-4 py-3">
                    <CodeBlock
                      code="npx skills add appwrite/agent-skills"
                      language="bash"
                      label="Terminal"
                      showCopy={true}
                    />
                  </div>
                  <div className="px-4 py-2.5 border-t border-border">
                    <p className="text-[12px] font-medium text-foreground mb-1.5">
                      Then the CLI will ask:
                    </p>
                    <ul className="text-[12px] text-muted-foreground space-y-1">
                      <li>
                        <span className="text-foreground font-medium">
                          Skills
                        </span>{' '}
                        - which SDKs to install (e.g. TypeScript, Go).
                      </li>
                      <li>
                        <span className="text-foreground font-medium">
                          Tools
                        </span>{' '}
                        - which AI tools use them (Cursor, Claude, etc.).
                      </li>
                      <li>
                        <span className="text-foreground font-medium">
                          Scope
                        </span>{' '}
                        - project (this repo) or global.
                      </li>
                      <li>
                        <span className="text-foreground font-medium">
                          Method
                        </span>{' '}
                        - prefer symlink so skills stay up to date.
                      </li>
                    </ul>
                  </div>
                  <div className="px-4 py-3 border-t border-border bg-muted/30 flex flex-wrap items-center gap-x-4 gap-y-2">
                    <a
                      href={APPWRITE_AGENT_SKILLS_REPO}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="inline-flex items-center gap-1.5 link-neutral text-[13px]"
                    >
                      <GitHubIcon className="h-4 w-4" />
                      appwrite/agent-skills
                    </a>
                  </div>
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
            className="min-h-0 flex-1 overflow-y-auto px-6 pb-4 pt-0 data-[state=inactive]:hidden"
          >
            <div className="space-y-3 pt-4">
              <p className="text-[13px] leading-relaxed text-muted-foreground">
                Use a project-scoped HTTPS endpoint with SigV4-compatible
                signing to attach Storage to rclone, IaC, or custom pipelines.
                Copyable endpoint, access key, and secret will appear here when
                the integration is ready.
              </p>
              <p className="text-[13px] leading-relaxed text-muted-foreground">
                Work in progress - nothing to copy yet.
              </p>
            </div>
          </TabsContent>
        </Tabs>
        <div className="shrink-0 px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            size="sm"
            onClick={() => onOpenChange(false)}
          >
            Close
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
