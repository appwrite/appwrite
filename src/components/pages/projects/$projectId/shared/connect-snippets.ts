/**
 * Code samples and install instructions for the Connect dialog.
 *
 * The snippet code lives as real files under ./connect-snippets/, laid out the
 * way each framework's project would be (so files get proper editor syntax
 * highlighting and are easy to review), and is imported as raw text via Vite.
 * Which files a sample shows - and their tab order - is derived from the
 * directory contents, so adding or removing a snippet file is all it takes.
 * Only the generated `.env` entry is built in code, since it embeds the
 * project's endpoint and ID. The snippets directory is excluded from tsc,
 * ESLint, and Prettier - snippet files are content, not app code. Snippet
 * lines are capped at 80 chars (the modal's code panel fits ~86 monospace
 * chars at full width before scrolling horizontally); `bun run lint:snippets`
 * enforces this in CI.
 */
import type { CodeBlockLanguage } from '@/components/global/shared/CodeBlock'

export interface CodeFile {
  label: string
  code: string
  language?: CodeBlockLanguage
}

export interface InstallOption {
  label: string
  code: string
  language?: CodeBlockLanguage
}

const SNIPPET_SOURCES = import.meta.glob('./connect-snippets/**/*', {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>

const EXTENSION_LANGUAGES: Record<string, CodeBlockLanguage> = {
  ts: 'typescript',
  tsx: 'typescript',
  js: 'javascript',
  vue: 'markup',
  svelte: 'markup',
  dart: 'dart',
  py: 'python',
  php: 'php',
  rb: 'ruby',
  cs: 'csharp',
  go: 'go',
  swift: 'swift',
  kt: 'kotlin',
  java: 'java',
  rs: 'rust',
}

function languageForFile(path: string): CodeBlockLanguage | undefined {
  return EXTENSION_LANGUAGES[path.split('.').pop() ?? '']
}

/** Reads a snippet file, substituting `{{NAME}}` template variables. */
function getSnippet(path: string, vars: Record<string, string>): string {
  const code = SNIPPET_SOURCES[`./connect-snippets/${path}`]
  if (code === undefined) {
    throw new Error(`Missing connect snippet file: ${path}`)
  }
  return Object.entries(vars).reduce(
    (acc, [name, value]) => acc.replaceAll(`{{${name}}}`, value),
    code,
  )
}

interface SampleOverride {
  /** Snippet directory under ./connect-snippets/; defaults to the lookup key. */
  dir?: string
  /** Label for the generated env/config entry; defaults to '.env'. */
  envLabel?: string
  /** Highlight language for the generated env/config entry; defaults to 'env'. */
  envLanguage?: CodeBlockLanguage
}

/**
 * Samples resolve by `sdk/framework/using`, then `sdk/framework`, then `sdk` -
 * the first candidate whose snippet directory exists wins, and its file list
 * (and tab order) comes straight from the files on disk. This map only holds
 * the exceptions: directory aliases and custom env-entry labels/languages. An alias is
 * REQUIRED for any key whose directory only contains variant subdirectories
 * (e.g. web/react holds vite/ and cra/) - without one, resolving that key
 * would merge every variant's files into one sample.
 */
const SAMPLE_OVERRIDES: Record<string, SampleOverride> = {
  'web/next': { dir: 'web/next/pages' },
  'web/react': { dir: 'web/react/vite' },
  // Angular CLI builds have no import.meta.env; config lives in environment files.
  'web/angular': {
    envLabel: 'src/environments/environment.ts',
    envLanguage: 'typescript',
  },
  web: { dir: 'web/vanilla' },
  node: { dir: 'node/vanilla' },
  deno: { dir: 'deno/vanilla' },
  bun: { dir: 'bun/vanilla' },
  apple: { envLabel: 'Config (env or xcconfig)' },
  android: { envLabel: 'Build config / env' },
  swift: { envLabel: '.env or xcconfig' },
  dotnet: { envLabel: '.env or launchSettings' },
  kotlin: { envLabel: '.env or env vars' },
  java: { envLabel: '.env or env vars' },
}

/** Script sources tab-order before same-named markup (+page.ts before +page.svelte). */
const EXTENSION_PRIORITY = ['ts', 'tsx', 'js', 'jsx']

/** Splits `path` into [path-without-extension, extension]. */
function splitExtension(path: string): [string, string] {
  const dot = path.lastIndexOf('.')
  return dot > path.lastIndexOf('/') + 1
    ? [path.slice(0, dot), path.slice(dot + 1)]
    : [path, '']
}

/** Appwrite client setup file first, then other appwrite-named files, then the rest. */
function snippetRank(path: string): number {
  const [stem] = splitExtension(path.split('/').pop()?.toLowerCase() ?? '')
  if (stem === 'appwrite') return 0
  return stem.includes('appwrite') ? 1 : 2
}

function extensionWeight(extension: string): number {
  const index = EXTENSION_PRIORITY.indexOf(extension)
  return index === -1 ? EXTENSION_PRIORITY.length : index
}

/** Tab order: setup files first (snippetRank), then alphabetical; script before same-named markup. */
function compareSnippetFiles(a: string, b: string): number {
  const rank = snippetRank(a) - snippetRank(b)
  if (rank !== 0) return rank
  const [aStem, aExtension] = splitExtension(a)
  const [bStem, bExtension] = splitExtension(b)
  if (aStem !== bStem) return aStem < bStem ? -1 : 1
  return (
    extensionWeight(aExtension) - extensionWeight(bExtension) ||
    aExtension.localeCompare(bExtension)
  )
}

/** Ordered snippet files (paths relative to `dir`) found under ./connect-snippets/. */
function snippetFilesIn(dir: string): string[] {
  const prefix = `./connect-snippets/${dir}/`
  return Object.keys(SNIPPET_SOURCES)
    .filter((path) => path.startsWith(prefix))
    .map((path) => path.slice(prefix.length))
    .sort(compareSnippetFiles)
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
    if (frameworkId === 'sveltekit') {
      return `PUBLIC_APPWRITE_ENDPOINT=${endpoint}\nPUBLIC_APPWRITE_PROJECT_ID=${projectId}`
    }
    if (frameworkId === 'angular') {
      return `export const environment = {\n  appwriteEndpoint: '${endpoint}',\n  appwriteProjectId: '${projectId}',\n}`
    }
    return `VITE_APPWRITE_ENDPOINT=${endpoint}\nVITE_APPWRITE_PROJECT_ID=${projectId}`
  }
  return `APPWRITE_ENDPOINT=${endpoint}\nAPPWRITE_PROJECT_ID=${projectId}\nAPPWRITE_API_KEY=your-api-key`
}

/** Returns file-based code snippets tailored to the selected SDK, framework, variant (using), and package manager. */
export function getCodeFiles(
  sdkId: string,
  frameworkId: string,
  usingId: string,
  runtime: 'client' | 'server',
  endpoint: string,
  projectId: string,
  packageManagerId: string,
): CodeFile[] {
  const candidates = [
    `${sdkId}/${frameworkId}/${usingId}`,
    `${sdkId}/${frameworkId}`,
    sdkId,
  ]
  for (const key of candidates) {
    const override = SAMPLE_OVERRIDES[key]
    const dir = override?.dir ?? key
    const files = snippetFilesIn(dir)
    if (files.length === 0) continue
    const vars = {
      DENO_SDK_SPECIFIER:
        packageManagerId === 'npm' ? 'npm:node-appwrite' : 'jsr:@appwrite/sdk',
    }
    return [
      {
        label: override?.envLabel ?? '.env',
        code: getEnvExample(
          sdkId,
          runtime,
          frameworkId,
          usingId,
          endpoint,
          projectId,
        ),
        language: override?.envLanguage ?? 'env',
      },
      ...files.map((file) => ({
        label: file,
        code: getSnippet(`${dir}/${file}`, vars),
        language: languageForFile(file),
      })),
    ]
  }
  // Unknown SDK - fall back to the vanilla web quick start.
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

/**
 * Install instructions per SDK; for web/node can filter by package manager.
 * These render in the Connect modal's narrow left column, which fits only
 * ~53 monospace chars at full modal width - keep every code line at 52 or
 * fewer chars or it scrolls horizontally.
 */
export function getInstallInstructions(
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
            code: '.package(\n  url: "https://github.com/appwrite/sdk-for-apple",\n  from: "5.0.0"\n)',
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
            code: 'implementation("io.appwrite:sdk-for-android:5.0.0")',
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
            label: 'Package.swift',
            code: '.package(\n  url: "https://github.com/appwrite/sdk-for-swift",\n  from: "13.0.0"\n)',
            language: 'swift',
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
