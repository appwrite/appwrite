/**
 * Browser-safe frontmatter helpers. gray-matter uses Node Buffer and cannot run in the client.
 */
export function stripFrontmatter(raw: string): string {
  const match = raw.match(/^\s*---\r?\n[\s\S]*?\r?\n---\s*\r?\n?/)
  if (!match) return raw
  return raw.slice(match[0].length)
}

export function parseFrontmatterString(raw: string, key: string): string | undefined {
  const match = raw.match(/^\s*---\r?\n([\s\S]*?)\r?\n---/)
  if (!match) return undefined
  const fieldMatch = match[1].match(new RegExp(`^${key}:\\s*(.+)$`, 'm'))
  const value = fieldMatch?.[1]?.trim()
  return value || undefined
}
