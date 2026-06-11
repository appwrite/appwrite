import type { CodeBlockLanguage } from '@/components/global/shared/CodeBlock'
import { getCodeLanguageLabel } from '@/components/global/shared/CodeBlock'

/**
 * Maps markdown fence language tags (docs + connect modal conventions) to
 * CodeBlockLanguage values used by prism-react-renderer in CodeBlock.
 *
 * Aligned with Appwrite docs platform identifiers and the legacy website
 * highlight.js alias table in website/src/lib/utils/code.ts.
 */
const FENCE_LANGUAGE_ALIASES: Record<string, CodeBlockLanguage> = {
  // Standard language tags
  js: 'javascript',
  javascript: 'javascript',
  ts: 'typescript',
  typescript: 'typescript',
  jsx: 'javascript',
  tsx: 'typescript',
  py: 'python',
  python: 'python',
  rb: 'ruby',
  ruby: 'ruby',
  cs: 'csharp',
  csharp: 'csharp',
  sh: 'bash',
  shell: 'bash',
  zsh: 'bash',
  bash: 'bash',
  cmd: 'powershell',
  dos: 'powershell',
  powershell: 'powershell',
  ps: 'powershell',
  ps1: 'powershell',
  json: 'json',
  dart: 'dart',
  swift: 'swift',
  kotlin: 'kotlin',
  java: 'java',
  php: 'php',
  go: 'go',
  rust: 'rust',
  graphql: 'graphql',
  http: 'http',
  groovy: 'groovy',
  html: 'markup',
  xml: 'markup',
  yaml: 'yaml',
  yml: 'yaml',
  vue: 'markup',
  svelte: 'markup',
  css: 'css',
  md: 'markdown',
  markdown: 'markdown',
  text: 'plaintext',
  txt: 'plaintext',
  diff: 'diff',
  cpp: 'cpp',
  'c++': 'cpp',
  toml: 'toml',
  env: 'env',
  dotenv: 'env',
  ini: 'env',
  docker: 'docker',
  dockerfile: 'docker',
  hcl: 'hcl',
  terraform: 'hcl',
  tf: 'hcl',
  deno: 'deno',
  node: 'node',
  bun: 'bun',
  dotnet: 'dotnet',

  // Appwrite SDK platform identifiers
  'client-web': 'javascript',
  'client-flutter': 'dart',
  'client-apple': 'swift',
  'client-android-java': 'java',
  'client-android-kotlin': 'kotlin',
  'client-react-native': 'javascript',
  'client-graphql': 'graphql',
  'client-rest': 'http',
  'server-dart': 'dart',
  'server-deno': 'deno',
  'server-dotnet': 'dotnet',
  'server-nodejs': 'node',
  'server-php': 'php',
  'server-python': 'python',
  'server-ruby': 'ruby',
  'server-swift': 'swift',
  'server-java': 'java',
  'server-kotlin': 'kotlin',
  'server-graphql': 'graphql',
  'server-rest': 'http',
  'server-go': 'go',
  'server-rust': 'rust',
}

const FENCE_PLATFORM_LABELS: Record<string, string> = {
  'client-web': 'Web',
  'client-flutter': 'Flutter',
  'client-react-native': 'React Native',
  'client-apple': 'Apple',
  'client-android-kotlin': 'Android (Kotlin)',
  'client-android-java': 'Android (Java)',
  'client-graphql': 'GraphQL',
  'client-rest': 'REST',
  'server-nodejs': 'Node.js',
  'server-python': 'Python',
  'server-dart': 'Dart',
  'server-php': 'PHP',
  'server-ruby': 'Ruby',
  'server-dotnet': '.NET',
  'server-deno': 'Deno',
  'server-go': 'Go',
  'server-swift': 'Swift',
  'server-kotlin': 'Kotlin',
  'server-rust': 'Rust',
  'server-java': 'Java',
  'server-graphql': 'GraphQL',
  'server-rest': 'REST',
  sh: 'Shell',
  js: 'JavaScript',
  ts: 'TypeScript',
  jsx: 'React',
  tsx: 'React',
  typescript: 'TypeScript',
  javascript: 'JavaScript',
  dart: 'Dart',
  java: 'Java',
  kotlin: 'Kotlin',
  cs: 'C#',
  py: 'Python',
  rb: 'Ruby',
  php: 'PHP',
  swift: 'Swift',
  xml: 'XML',
  html: 'HTML',
  md: 'Markdown',
  json: 'JSON',
  diff: 'Diff',
  http: 'HTTP',
  css: 'CSS',
  graphql: 'GraphQL',
  deno: 'Deno',
  python: 'Python',
  ruby: 'Ruby',
  csharp: 'C#',
  cpp: 'C++',
  'c++': 'C++',
  toml: 'TOML',
  bash: 'Bash',
  powershell: 'PowerShell',
  cmd: 'CMD',
  yaml: 'YAML',
  text: 'Text',
  vue: 'Vue',
  svelte: 'Svelte',
  groovy: 'Groovy',
  go: 'Go',
  rust: 'Rust',
  dockerfile: 'Dockerfile',
  docker: 'Dockerfile',
  ini: 'INI',
  env: '.env',
  dotenv: '.env',
  hcl: 'Terraform',
  terraform: 'Terraform',
  tf: 'Terraform',
  toml: 'TOML',
  txt: 'Text',
  node: 'Node.js',
  bun: 'Bun',
  dotnet: '.NET',
}

export function resolveFenceCodeLanguage(language?: string): CodeBlockLanguage {
  if (!language) return 'plaintext'
  const normalized = language.toLowerCase().trim()
  return FENCE_LANGUAGE_ALIASES[normalized] ?? 'plaintext'
}

export function resolveFenceCodeLabel(language?: string): string {
  if (!language) return getCodeLanguageLabel('plaintext')
  const normalized = language.toLowerCase().trim()
  if (FENCE_PLATFORM_LABELS[normalized]) {
    return FENCE_PLATFORM_LABELS[normalized]
  }
  const resolved = resolveFenceCodeLanguage(normalized)
  return getCodeLanguageLabel(resolved)
}

/** @deprecated Use resolveFenceCodeLanguage */
export const resolveCodeLanguage = resolveFenceCodeLanguage

/** @deprecated Use resolveFenceCodeLabel */
export const resolvePlatformLabel = resolveFenceCodeLabel

/** @deprecated Use FENCE_PLATFORM_LABELS via resolveFenceCodeLabel */
export const PLATFORM_LABELS = FENCE_PLATFORM_LABELS
