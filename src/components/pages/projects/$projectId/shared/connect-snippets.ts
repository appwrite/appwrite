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
  astro: 'markup',
  yaml: 'yaml',
  json: 'json',
  dart: 'dart',
  py: 'python',
  php: 'php',
  rb: 'ruby',
  cs: 'csharp',
  go: 'go',
  swift: 'swift',
  kt: 'kotlin',
  kts: 'kotlin',
  java: 'java',
  rs: 'rust',
  xcconfig: 'plaintext',
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
  apple: { envLabel: 'Appwrite.xcconfig' },
  // android/ only holds language variants (kotlin/, java/), so the bare key
  // needs an alias per the rule above.
  android: { dir: 'android/kotlin', envLabel: 'gradle.properties' },
  'android/kotlin': { envLabel: 'gradle.properties' },
  'android/java': { envLabel: 'gradle.properties' },
  flutter: { envLabel: 'env.json', envLanguage: 'json' },
  'python/python': { dir: 'python/vanilla' },
  python: { dir: 'python/vanilla' },
  'php/php': { dir: 'php/vanilla' },
  php: { dir: 'php/vanilla' },
  'ruby/ruby': { dir: 'ruby/vanilla' },
  ruby: { dir: 'ruby/vanilla' },
  'go/go': { dir: 'go/vanilla' },
  go: { dir: 'go/vanilla' },
  'rust/rust': { dir: 'rust/vanilla' },
  rust: { dir: 'rust/vanilla' },
  'dart/dart': { dir: 'dart/vanilla' },
  dart: { dir: 'dart/vanilla' },
  'swift/swift': { dir: 'swift/vanilla', envLabel: '.env or xcconfig' },
  swift: { dir: 'swift/vanilla', envLabel: '.env or xcconfig' },
  'dotnet/dotnet': {
    dir: 'dotnet/vanilla',
    envLabel: '.env or launchSettings',
  },
  dotnet: { dir: 'dotnet/vanilla', envLabel: '.env or launchSettings' },
  'kotlin/kotlin': { dir: 'kotlin/vanilla', envLabel: '.env or env vars' },
  kotlin: { dir: 'kotlin/vanilla', envLabel: '.env or env vars' },
  'java/java': { dir: 'java/vanilla', envLabel: '.env or env vars' },
  java: { dir: 'java/vanilla', envLabel: '.env or env vars' },
  'java/spring': { envLabel: '.env or env vars' },
  'java/quarkus': { envLabel: '.env or env vars' },
  'kotlin/spring': { envLabel: '.env or env vars' },
  'kotlin/ktor': { envLabel: '.env or env vars' },
  'swift/vapor': { envLabel: '.env or env vars' },
  'dotnet/minimal': { envLabel: '.env or launchSettings' },
  'dotnet/controllers': { envLabel: '.env or launchSettings' },
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

/**
 * Project config files first (like the generated env entry, they're one-time
 * setup), then the Appwrite client setup file, then other appwrite-named
 * files, then the rest.
 */
function snippetRank(path: string): number {
  const [stem, extension] = splitExtension(
    path.split('/').pop()?.toLowerCase() ?? '',
  )
  if (stem === 'config' || stem.endsWith('.config')) return 0
  // Build-system config (android/build.gradle.kts) is one-time setup too, but
  // its stem is 'build.gradle', so match on the extension instead.
  if (extension === 'kts' || extension === 'gradle') return 0
  if (stem === 'appwrite') return 1
  return stem.includes('appwrite') ? 2 : 3
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
  // Client-side mobile SDKs: each platform has its own config channel, and
  // none of them can read a plain process environment at runtime.
  if (sdkId === 'react-native') {
    // Metro only inlines EXPO_PUBLIC_-prefixed variables.
    return `EXPO_PUBLIC_APPWRITE_ENDPOINT=${endpoint}\nEXPO_PUBLIC_APPWRITE_PROJECT_ID=${projectId}`
  }
  if (sdkId === 'flutter') {
    // Consumed by `flutter run --dart-define-from-file=env.json`.
    return `{\n  "APPWRITE_ENDPOINT": "${endpoint}",\n  "APPWRITE_PROJECT_ID": "${projectId}"\n}`
  }
  if (sdkId === 'android') {
    // Read by build.gradle.kts and baked into BuildConfig.
    return `APPWRITE_ENDPOINT=${endpoint}\nAPPWRITE_PROJECT_ID=${projectId}`
  }
  if (sdkId === 'apple') {
    // xcconfig treats // as the start of a comment, which would truncate the
    // endpoint to "https:". $() is an empty variable placed BETWEEN the two
    // slashes so the raw text never contains //; it expands to nothing, so
    // the build setting still reads https://host/v1.
    return [
      '// Add to the target config, then point both Info.plist keys at',
      '// $(APPWRITE_ENDPOINT) and $(APPWRITE_PROJECT_ID).',
      `APPWRITE_ENDPOINT = ${endpoint.replace('//', '/$()/')}`,
      `APPWRITE_PROJECT_ID = ${projectId}`,
    ].join('\n')
  }
  if (sdkId === 'web' && !isServer) {
    if (frameworkId === 'next') {
      return `NEXT_PUBLIC_APPWRITE_ENDPOINT=${endpoint}\nNEXT_PUBLIC_APPWRITE_PROJECT_ID=${projectId}`
    }
    if (frameworkId === 'react' && usingId === 'cra') {
      return `REACT_APP_APPWRITE_ENDPOINT=${endpoint}\nREACT_APP_APPWRITE_PROJECT_ID=${projectId}`
    }
    if (frameworkId === 'sveltekit' || frameworkId === 'astro') {
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
      // Used where a snippet needs the id outside the SDK client, e.g. the
      // capacitor config's appwrite-callback-<id> WebView scheme.
      PROJECT_ID: projectId,
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
            code: 'dependencies:\n  appwrite: ^25.4.0',
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
            code: '.package(\n  url: "https://github.com/appwrite/sdk-for-apple",\n  from: "18.3.0"\n)',
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
            code: 'implementation("io.appwrite:sdk-for-android:26.0.0")',
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
            code: 'dependencies:\n  appwrite: ^25.4.0',
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
            code: 'implementation("io.appwrite:sdk-for-kotlin:19.1.0")\nimplementation("com.google.code.gson:gson:2.14.0")',
            language: 'kotlin',
          },
          {
            label: 'Maven (pom.xml)',
            code: '<dependency>\n  <groupId>io.appwrite</groupId>\n  <artifactId>sdk-for-kotlin</artifactId>\n  <version>19.1.0</version>\n</dependency>\n<dependency>\n  <groupId>com.google.code.gson</groupId>\n  <artifactId>gson</artifactId>\n  <version>2.14.0</version>\n</dependency>',
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
            code: '.package(\n  url: "https://github.com/appwrite/sdk-for-swift",\n  from: "20.0.0"\n)',
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
            code: 'implementation("io.appwrite:sdk-for-kotlin:19.1.0")\nimplementation("com.google.code.gson:gson:2.14.0")',
            language: 'kotlin',
          },
        ],
      }
    default:
      return getInstallInstructions('web', 'npm')
  }
}

function markdownFenceLanguage(
  language: CodeBlockLanguage | undefined,
  fileLabel: string,
): string {
  if (!language || language === 'plaintext') {
    const ext = fileLabel.split('.').pop()?.toLowerCase()
    if (ext && ext !== fileLabel.toLowerCase()) return ext
    return ''
  }
  if (language === 'markup') {
    const ext = fileLabel.split('.').pop()?.toLowerCase()
    if (ext === 'svelte' || ext === 'vue' || ext === 'html' || ext === 'xml') {
      return ext
    }
    return 'html'
  }
  if (language === 'node' || language === 'deno' || language === 'bun') {
    return 'javascript'
  }
  if (language === 'dotnet') return 'csharp'
  return language
}

export type ConnectSdkPromptInput = {
  projectId: string
  projectName?: string
  endpoint: string
  sdkLabel: string
  runtime: 'client' | 'server'
  frameworkLabel?: string
  usingLabel?: string
  packageManagerLabel?: string
  installTitle: string
  installOptions: InstallOption[]
  codeFiles: CodeFile[]
}

/** Markdown handoff for coding agents from the Connect SDK tab. */
export function buildConnectSdkPrompt(input: ConnectSdkPromptInput): string {
  const lines: string[] = [
    'Connect this app to Appwrite using the SDK setup below.',
    'Apply the install step and create each file with the exact contents shown.',
    '',
    '## Project',
    '',
    `- Project ID: \`${input.projectId}\``,
  ]

  if (input.projectName?.trim()) {
    lines.push(`- Project name: ${input.projectName.trim()}`)
  }

  lines.push(
    `- Endpoint: \`${input.endpoint}\``,
    `- SDK / Platform: ${input.sdkLabel} (${input.runtime})`,
  )

  if (input.frameworkLabel) {
    lines.push(`- Framework: ${input.frameworkLabel}`)
  }
  if (input.usingLabel) {
    lines.push(`- Using: ${input.usingLabel}`)
  }
  if (input.packageManagerLabel) {
    lines.push(`- Package manager: ${input.packageManagerLabel}`)
  }

  lines.push('', `## ${input.installTitle}`, '')

  for (const option of input.installOptions) {
    const fence = markdownFenceLanguage(option.language, option.label)
    if (input.installOptions.length > 1) {
      lines.push(`### ${option.label}`, '')
    }
    lines.push(`\`\`\`${fence}`, option.code.trimEnd(), '```', '')
  }

  if (input.codeFiles.length > 0) {
    lines.push('## Project files', '')
    for (const file of input.codeFiles) {
      const fence = markdownFenceLanguage(file.language, file.label)
      lines.push(`### \`${file.label}\``, '', `\`\`\`${fence}`, file.code.trimEnd(), '```', '')
    }
  }

  lines.push('## Notes', '')
  if (input.runtime === 'server') {
    lines.push(
      '- Server and backend code need an Appwrite API key with the right scopes. Create one in the project console and keep it secret (do not commit it).',
    )
  }
  lines.push(
    '- Prefer the latest Appwrite SDK release when installing packages.',
    '- Docs: https://appwrite.io/docs',
  )

  return lines.join('\n').trimEnd() + '\n'
}
