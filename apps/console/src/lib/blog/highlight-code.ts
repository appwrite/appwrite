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
 * The loader also runs in the browser on client-side navigation, so grammars
 * load through the shared registry in `@/lib/prism-languages`; call
 * `loadFenceGrammars` before `highlightCode`.
 */

import { resolveFenceCodeLanguage } from '@/lib/code-language'
import { loadPrismLanguage, Prism } from '@/lib/prism-languages'

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
  // The shared resolver folds these into javascript/typescript for labels;
  // Prism has dedicated grammars that also tokenise the JSX.
  jsx: 'jsx',
  tsx: 'tsx',
}

function resolvePrismLanguageId(fenceLanguage: string): string | null {
  const normalized = fenceLanguage.toLowerCase().trim()
  // Platform fences such as `client-flutter` or `server-nodejs` are aliased
  // in full, so resolve the whole name before falling back to the bare
  // runtime key (`nodejs`, `web`) for fences that omit the prefix.
  const aliased = resolveFenceCodeLanguage(normalized)
  const platform = normalized.replace(/^(server|client)-/, '')
  const candidate =
    RUNTIME_TO_PRISM[normalized] ??
    (aliased !== 'plaintext'
      ? aliased
      : (RUNTIME_TO_PRISM[platform] ?? resolveFenceCodeLanguage(platform)))
  const language = RUNTIME_TO_PRISM[candidate] ?? candidate
  if (language === 'plaintext' || language === 'env') return null
  return language
}

/** Loads the Prism grammars for the given fence languages. */
export async function loadFenceGrammars(
  fenceLanguages: Iterable<string>,
): Promise<void> {
  const ids = new Set<string>()
  for (const fence of fenceLanguages) {
    const id = resolvePrismLanguageId(fence)
    if (id) ids.add(id)
  }
  await Promise.all([...ids].map((id) => loadPrismLanguage(id)))
}

/**
 * Returns Prism token markup for a fence, or null when the language has no
 * grammar so the caller can fall back to escaped plain text.
 */
export function highlightCode(
  code: string,
  fenceLanguage: string,
): HighlightedCode | null {
  const language = resolvePrismLanguageId(fenceLanguage)
  if (!language || !Prism.languages[language]) return null
  return {
    html: Prism.highlight(code, Prism.languages[language], language),
    language,
  }
}
