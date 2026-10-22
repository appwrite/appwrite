/// <reference path="../prismjs-components.d.ts" />

/**
 * The one Prism instance shared by CodeBlock and blog fence highlighting.
 *
 * prismjs language components register onto `globalThis.Prism`, so every
 * grammar must load through `loadPrismLanguage` against this instance. A
 * second static `import 'prismjs/components/…'` elsewhere is not enough: the
 * client bundler gives each component its own lazy chunk (from the loaders
 * below) and drops side-effect-only imports of it from other chunks.
 */

import { Prism } from 'prism-react-renderer'

type PrismGlobal = { Prism: typeof Prism }

function exposePrism() {
  ;(globalThis as unknown as PrismGlobal).Prism = Prism
}

exposePrism()

// Register .env / dotenv syntax (KEY=value, # comments, quoted values)
if (!Prism.languages.env) {
  Prism.languages.env = {
    comment: /#.*/,
    'attr-name': /^[A-Za-z_][A-Za-z0-9_]*/m,
    operator: /=/,
    string: [
      { pattern: /"(?:[^"\\]|\\.)*"/, greedy: true },
      { pattern: /'(?:[^'\\]|\\.)*'/, greedy: true },
    ],
  }
}

const PRISM_LOADERS: Record<string, () => Promise<unknown>> = {
  json: () => import('prismjs/components/prism-json'),
  dart: () => import('prismjs/components/prism-dart'),
  swift: () => import('prismjs/components/prism-swift'),
  kotlin: () => import('prismjs/components/prism-kotlin'),
  java: () => import('prismjs/components/prism-java'),
  bash: () => import('prismjs/components/prism-bash'),
  powershell: () => import('prismjs/components/prism-powershell'),
  markup: () => import('prismjs/components/prism-markup'),
  'markup-templating': () =>
    loadPrismLanguage('markup').then(
      () => import('prismjs/components/prism-markup-templating'),
    ),
  php: () =>
    loadPrismLanguage('markup-templating').then(
      () => import('prismjs/components/prism-php'),
    ),
  python: () => import('prismjs/components/prism-python'),
  ruby: () => import('prismjs/components/prism-ruby'),
  go: () => import('prismjs/components/prism-go'),
  csharp: () => import('prismjs/components/prism-csharp'),
  hcl: () => import('prismjs/components/prism-hcl'),
  rust: () => import('prismjs/components/prism-rust'),
  graphql: () => import('prismjs/components/prism-graphql'),
  sql: () => import('prismjs/components/prism-sql'),
  http: () => import('prismjs/components/prism-http'),
  groovy: () => import('prismjs/components/prism-groovy'),
  docker: () => import('prismjs/components/prism-docker'),
  css: () => import('prismjs/components/prism-css'),
  yaml: () => import('prismjs/components/prism-yaml'),
  toml: () => import('prismjs/components/prism-toml'),
  c: () => import('prismjs/components/prism-c'),
  cpp: () =>
    loadPrismLanguage('c').then(() => import('prismjs/components/prism-cpp')),
  markdown: () =>
    loadPrismLanguage('markup').then(
      () => import('prismjs/components/prism-markdown'),
    ),
  diff: () => import('prismjs/components/prism-diff'),
}

const loadedLanguages = new Set<string>()

export function loadPrismLanguage(lang: string): Promise<void> {
  if (loadedLanguages.has(lang)) return Promise.resolve()
  const loader = PRISM_LOADERS[lang]
  if (!loader) return Promise.resolve()
  // Other Prism setups (e.g. the cover generator) may have replaced the global.
  exposePrism()
  return loader().then(() => {
    loadedLanguages.add(lang)
  })
}

/** True when `lang` can be tokenised now, without waiting for a grammar chunk. */
export function isPrismLanguageReady(lang: string): boolean {
  return (
    !PRISM_LOADERS[lang] ||
    loadedLanguages.has(lang) ||
    Boolean(Prism.languages[lang])
  )
}

export { Prism }
