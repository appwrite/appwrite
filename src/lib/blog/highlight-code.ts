/**
 * Build-time and server-side syntax highlighting for blog code fences.
 *
 * Blog posts render to an HTML string in the route loader, so fences are
 * tokenised with Prism here, once per render, and shipped as static
 * `token` spans. The palette lives in styles.css (`.blog-code .token`) and
 * reads the same `--code-*` variables CodeBlock uses, so blog and docs
 * code look the same in both themes without any highlighter running in the
 * reader's browser.
 *
 * Import this module lazily (see renderBlogPostBodies) so the grammars stay
 * out of the initial client bundle.
 */

import Prism from 'prismjs'
import 'prismjs/components/prism-markup'
import 'prismjs/components/prism-markup-templating'
import 'prismjs/components/prism-bash'
import 'prismjs/components/prism-json'
import 'prismjs/components/prism-dart'
import 'prismjs/components/prism-swift'
import 'prismjs/components/prism-kotlin'
import 'prismjs/components/prism-java'
import 'prismjs/components/prism-php'
import 'prismjs/components/prism-python'
import 'prismjs/components/prism-ruby'
import 'prismjs/components/prism-go'
import 'prismjs/components/prism-csharp'
import 'prismjs/components/prism-rust'
import 'prismjs/components/prism-graphql'
import 'prismjs/components/prism-sql'
import 'prismjs/components/prism-http'
import 'prismjs/components/prism-yaml'
import 'prismjs/components/prism-toml'
import 'prismjs/components/prism-docker'
import 'prismjs/components/prism-diff'
import 'prismjs/components/prism-powershell'
import 'prismjs/components/prism-hcl'
import 'prismjs/components/prism-css'
import { resolveFenceCodeLanguage } from '@/lib/code-language'

export type HighlightedCode = {
  html: string
  /** Prism grammar id, exposed as `language-*` on the code element. */
  language: string
}

/** Appwrite runtime keys and platform fences that map onto a Prism grammar. */
const RUNTIME_TO_PRISM: Record<string, string> = {
  node: 'javascript',
  nodejs: 'javascript',
  deno: 'javascript',
  bun: 'javascript',
  web: 'javascript',
  dotnet: 'csharp',
}

function resolvePrismLanguage(fenceLanguage: string): string | null {
  const normalized = fenceLanguage.toLowerCase().trim()
  // Platform fences such as `client-flutter` or `server-nodejs` are aliased
  // in full, so resolve the whole name before falling back to the bare
  // runtime key (`nodejs`, `web`) for fences that omit the prefix.
  const aliased = resolveFenceCodeLanguage(normalized)
  const platform = normalized.replace(/^(server|client)-/, '')
  const candidate =
    aliased !== 'plaintext'
      ? aliased
      : (RUNTIME_TO_PRISM[platform] ?? resolveFenceCodeLanguage(platform))
  const language = RUNTIME_TO_PRISM[candidate] ?? candidate
  if (language === 'plaintext' || language === 'env') return null
  return Prism.languages[language] ? language : null
}

/**
 * Returns Prism token markup for a fence, or null when the language has no
 * grammar so the caller can fall back to escaped plain text.
 */
export function highlightCode(
  code: string,
  fenceLanguage: string,
): HighlightedCode | null {
  const language = resolvePrismLanguage(fenceLanguage)
  if (!language) return null
  return {
    html: Prism.highlight(code, Prism.languages[language], language),
    language,
  }
}
