const promptFiles = import.meta.glob('/src/content/docs/**/prompt.md', {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>

function slugFromPromptPath(fullPath: string): string {
  return fullPath.replace(/^\/src\/content\/docs\//, '').replace(/\/prompt\.md$/, '')
}

const slugToPrompt: Record<string, string> = Object.entries(promptFiles).reduce(
  (acc, [absPath, contents]) => {
    acc[slugFromPromptPath(absPath)] = contents
    return acc
  },
  {} as Record<string, string>,
)

/** Normalize `/docs/quick-starts/react` or `quick-starts/react` to a docs slug. */
export function normalizeDocsPromptPath(path: string): string {
  const trimmed = path.trim().replace(/\/+$/, '')
  if (trimmed.startsWith('/docs/')) {
    return trimmed.slice('/docs/'.length)
  }
  return trimmed.replace(/^\/+/, '')
}

export function getDocsRoutePrompt(slugOrPath?: string | null): string | null {
  if (!slugOrPath) return null
  const slug = normalizeDocsPromptPath(slugOrPath)
  if (!slug) return null
  return slugToPrompt[slug] ?? null
}

export function hasDocsRoutePrompt(slugOrPath?: string | null): boolean {
  return getDocsRoutePrompt(slugOrPath) !== null
}

export function listDocsRoutePromptSlugs(): string[] {
  return Object.keys(slugToPrompt).sort()
}

export function resolveDocsPagePrompt(
  pageSlug: string,
  promptPath?: string | null,
): string | null {
  return getDocsRoutePrompt(pageSlug) ?? getDocsRoutePrompt(promptPath)
}

export function pageHasDocsPrompt(
  pageSlug: string,
  promptPath?: string | null,
): boolean {
  return resolveDocsPagePrompt(pageSlug, promptPath) !== null
}
