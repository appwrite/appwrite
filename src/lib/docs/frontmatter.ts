/**
 * Browser-safe frontmatter stripping. gray-matter uses Node Buffer and cannot run in the client.
 */
export function stripFrontmatter(raw: string): string {
  const match = raw.match(/^\s*---\r?\n[\s\S]*?\r?\n---\s*\r?\n?/)
  if (!match) return raw
  return raw.slice(match[0].length)
}
