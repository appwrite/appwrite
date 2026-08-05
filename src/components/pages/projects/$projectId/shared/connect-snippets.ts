/**
 * Code samples and install instructions for the Connect dialog.
 *
 * The snippet code lives as real files under ./connect-snippets/, laid out the
 * way each framework's project would be (so files get proper editor syntax
 * highlighting and are easy to review), and is imported as raw text via Vite.
 * Only the generated `.env` entry is built in code, since it embeds the
 * project's endpoint and ID. The snippets directory is excluded from tsc,
 * ESLint, and Prettier - snippet files are content, not app code.
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

interface CodeSample {
  /** Snippet directory under ./connect-snippets/; defaults to the lookup key. */
  dir?: string
  /** Label for the generated env/config entry; defaults to '.env'. */
  envLabel?: string
  /** Ordered snippet files (relative to dir), also used as tab labels. */
  files: string[]
}

const REACT_VITE: CodeSample = {
  dir: 'web/react/vite',
  files: [
    'src/lib/appwrite.ts',
    'src/App.tsx',
    'src/pages/SignIn.tsx',
    'src/pages/SignUp.tsx',
  ],
}

const NEXT_PAGES: CodeSample = {
  dir: 'web/next/pages',
  files: [
    'lib/appwrite.ts',
    'pages/index.tsx',
    'pages/sign-in.tsx',
    'pages/sign-up.tsx',
  ],
}

const WEB_VANILLA: CodeSample = { dir: 'web/vanilla', files: ['main.js'] }
const NODE_VANILLA: CodeSample = {
  dir: 'node/vanilla',
  files: ['src/index.ts'],
}
const BUN_VANILLA: CodeSample = { dir: 'bun/vanilla', files: ['src/index.ts'] }
const DENO_VANILLA: CodeSample = { dir: 'deno/vanilla', files: ['main.ts'] }

/**
 * Code sample per SDK/framework/variant, resolved by `sdk/framework/using`,
 * then `sdk/framework`, then `sdk` (most specific wins). The `sdk/framework`
 * entries for React and Next.js double as the default when the "using" variant
 * is (transiently) unknown; the bare `sdk` entries cover unknown frameworks.
 */
const CODE_SAMPLES: Record<string, CodeSample> = {
  'web/next/app': {
    files: [
      'lib/appwrite.ts',
      'app/page.tsx',
      'app/sign-in/page.tsx',
      'app/sign-up/page.tsx',
    ],
  },
  'web/next/pages': NEXT_PAGES,
  'web/next': NEXT_PAGES,
  'web/tanstack': { files: ['src/lib/appwrite.ts', 'src/routes/index.tsx'] },
  'web/react/vite': REACT_VITE,
  'web/react/cra': {
    files: [
      'src/lib/appwrite.js',
      'src/App.js',
      'src/pages/SignIn.js',
      'src/pages/SignUp.js',
    ],
  },
  'web/react': REACT_VITE,
  'web/sveltekit': {
    files: [
      'src/lib/appwrite.ts',
      'src/routes/+page.ts',
      'src/routes/+page.svelte',
      'src/routes/sign-in/+page.ts',
      'src/routes/sign-in/+page.svelte',
      'src/routes/sign-up/+page.ts',
      'src/routes/sign-up/+page.svelte',
    ],
  },
  'web/svelte': { files: ['src/lib/appwrite.ts', 'src/App.svelte'] },
  'web/nuxt': { files: ['utils/appwrite.ts', 'app.vue'] },
  'web/vue': {
    files: [
      'src/lib/appwrite.ts',
      'src/App.vue',
      'src/pages/SignIn.vue',
      'src/pages/SignUp.vue',
    ],
  },
  'web/analog': {
    files: ['src/lib/appwrite.ts', 'src/app/pages/index.page.ts'],
  },
  'web/angular': {
    files: [
      'src/lib/appwrite.ts',
      'src/app/appwrite.service.ts',
      'src/app/app.component.ts',
    ],
  },
  'web/solidstart': { files: ['src/lib/appwrite.ts', 'src/routes/index.tsx'] },
  'web/solid': { files: ['src/lib/appwrite.ts', 'src/App.tsx'] },
  'web/vanilla': WEB_VANILLA,
  web: WEB_VANILLA,
  flutter: { files: ['lib/appwrite_client.dart', 'lib/main.dart'] },
  'react-native': { files: ['lib/appwrite.ts', 'App.tsx'] },
  apple: {
    envLabel: 'Config (env or xcconfig)',
    files: ['AppwriteClient.swift', 'ContentView.swift'],
  },
  android: {
    envLabel: 'Build config / env',
    files: ['AppwriteClient.kt', 'MainActivity.kt'],
  },
  'node/express': { files: ['lib/appwrite.ts', 'src/index.ts'] },
  'node/koa': { files: ['lib/appwrite.ts', 'src/index.ts'] },
  'node/vanilla': NODE_VANILLA,
  node: NODE_VANILLA,
  'deno/fresh': { files: ['lib/appwrite.ts', 'routes/index.tsx'] },
  'deno/vanilla': DENO_VANILLA,
  deno: DENO_VANILLA,
  'bun/hono': { files: ['src/lib/appwrite.ts', 'src/index.ts'] },
  'bun/elysia': { files: ['src/lib/appwrite.ts', 'src/index.ts'] },
  'bun/vanilla': BUN_VANILLA,
  bun: BUN_VANILLA,
  go: { files: ['main.go'] },
  python: { files: ['main.py'] },
  php: { files: ['index.php'] },
  ruby: { files: ['main.rb'] },
  dart: { files: ['bin/main.dart'] },
  swift: { envLabel: '.env or xcconfig', files: ['main.swift'] },
  dotnet: { envLabel: '.env or launchSettings', files: ['Program.cs'] },
  kotlin: { envLabel: '.env or env vars', files: ['Main.kt'] },
  java: { envLabel: '.env or env vars', files: ['src/main/java/Main.java'] },
  rust: { files: ['src/main.rs'] },
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
  const key = [
    `${sdkId}/${frameworkId}/${usingId}`,
    `${sdkId}/${frameworkId}`,
    sdkId,
  ].find((candidate) => candidate in CODE_SAMPLES)
  if (!key) {
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
  const sample = CODE_SAMPLES[key]
  const dir = sample.dir ?? key
  const vars = {
    DENO_SDK_SPECIFIER:
      packageManagerId === 'npm' ? 'npm:node-appwrite' : 'jsr:@appwrite/sdk',
  }
  return [
    {
      label: sample.envLabel ?? '.env',
      code: getEnvExample(
        sdkId,
        runtime,
        frameworkId,
        usingId,
        endpoint,
        projectId,
      ),
      language: 'env',
    },
    ...sample.files.map((file) => ({
      label: file,
      code: getSnippet(`${dir}/${file}`, vars),
      language: languageForFile(file),
    })),
  ]
}

/** Install instructions per SDK; for web/node can filter by package manager. */
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
