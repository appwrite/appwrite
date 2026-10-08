import { existsSync } from 'node:fs'
import { createRequire } from 'node:module'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import type { CoverCodeSnippetLanguage } from '@/lib/cover-generator/code-snippet/constants'

type PrismNamespace = typeof import('prismjs')

const PRISM_COMPONENT_PATHS = [
  'prismjs/components/prism-clike',
  'prismjs/components/prism-markup',
  'prismjs/components/prism-markup-templating',
  'prismjs/components/prism-javascript',
  'prismjs/components/prism-typescript',
  'prismjs/components/prism-python',
  'prismjs/components/prism-php',
  'prismjs/components/prism-ruby',
  'prismjs/components/prism-go',
  'prismjs/components/prism-java',
  'prismjs/components/prism-kotlin',
  'prismjs/components/prism-swift',
  'prismjs/components/prism-dart',
  'prismjs/components/prism-csharp',
  'prismjs/components/prism-rust',
  'prismjs/components/prism-bash',
  'prismjs/components/prism-json',
  'prismjs/components/prism-yaml',
  'prismjs/components/prism-graphql',
  'prismjs/components/prism-css',
] as const

const PRISM_LANGUAGE_IDS: Record<CoverCodeSnippetLanguage, string> = {
  javascript: 'javascript',
  typescript: 'typescript',
  python: 'python',
  php: 'php',
  ruby: 'ruby',
  go: 'go',
  java: 'java',
  kotlin: 'kotlin',
  swift: 'swift',
  dart: 'dart',
  csharp: 'csharp',
  rust: 'rust',
  bash: 'bash',
  json: 'json',
  yaml: 'yaml',
  graphql: 'graphql',
  css: 'css',
  markup: 'markup',
  plaintext: 'plain',
}

const globalScope = globalThis as typeof globalThis & { Prism?: PrismNamespace }

let prismInstance: PrismNamespace | null = null
let prismLoadAttempted = false

function findPackageJsonWithPrism(): string | null {
  const candidates: string[] = []

  if (typeof import.meta.url === 'string') {
    let dir = dirname(fileURLToPath(import.meta.url))
    while (dir !== dirname(dir)) {
      candidates.push(join(dir, 'package.json'))
      dir = dirname(dir)
    }
  }

  candidates.push(join(process.cwd(), 'package.json'))

  for (const packageJsonPath of candidates) {
    if (!existsSync(packageJsonPath)) continue
    try {
      const require = createRequire(packageJsonPath)
      require.resolve('prismjs/package.json')
      return packageJsonPath
    } catch {
      continue
    }
  }

  return null
}

function tryLoadPrism(): PrismNamespace | null {
  if (prismInstance) return prismInstance
  if (prismLoadAttempted) return null
  prismLoadAttempted = true

  const packageJsonPath = findPackageJsonWithPrism()
  if (!packageJsonPath) return null

  try {
    const nodeRequire = createRequire(packageJsonPath)
    const prism = nodeRequire('prismjs') as PrismNamespace
    globalScope.Prism = prism

    for (const componentPath of PRISM_COMPONENT_PATHS) {
      try {
        nodeRequire(nodeRequire.resolve(componentPath))
      } catch (error) {
        console.warn(
          `[cover-generator] Failed to load Prism component "${componentPath}"`,
          error,
        )
      }
    }

    prismInstance = prism
    globalScope.Prism = prism
    return prism
  } catch (error) {
    console.warn('[cover-generator] Failed to initialize Prism', error)
    return null
  }
}

export function getCoverPrism(): PrismNamespace {
  const prism = tryLoadPrism()
  if (!prism) {
    throw new Error('Prism is not available for cover syntax highlighting')
  }
  return prism
}

export function ensureCoverCodeSnippetPrismGrammars(): void {
  tryLoadPrism()
}

export function getCoverCodeSnippetPrismLanguageId(
  language: CoverCodeSnippetLanguage,
): string | null {
  const grammarId = PRISM_LANGUAGE_IDS[language]
  return grammarId === 'plain' ? null : grammarId
}

export function getCoverCodeSnippetPrismGrammar(
  language: CoverCodeSnippetLanguage,
): import('prismjs').Grammar | null {
  const prism = tryLoadPrism()
  if (!prism) return null
  const grammarId = getCoverCodeSnippetPrismLanguageId(language)
  if (!grammarId) return null
  return prism.languages[grammarId] ?? null
}
