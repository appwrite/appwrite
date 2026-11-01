/** YAML-like frontmatter at the top of a changelog `.md` file. */
export function parseFrontmatter(raw: string): { frontmatter: Record<string, string>; body: string } {
  const match = raw.match(/^\s*---\r?\n([\s\S]*?)\r?\n---\s*\r?\n?/)
  if (!match) return { frontmatter: {}, body: raw.trim() }

  const frontmatter: Record<string, string> = {}
  for (const line of match[1].split('\n')) {
    const keyValue = line.match(/^([\w-]+):\s*(.*)$/)
    if (!keyValue) continue

    let value = keyValue[2].trim()
    if (
      (value.startsWith('"') && value.endsWith('"')) ||
      (value.startsWith("'") && value.endsWith("'"))
    ) {
      value = value.slice(1, -1)
    }

    frontmatter[keyValue[1]] = value
  }

  return { frontmatter, body: raw.slice(match[0].length).trim() }
}

/** Comma-separated library slugs and site scopes (`appwrite`, `docs`, …). */
export function parsePackages(value?: string): string[] {
  if (!value?.trim()) return []
  return value
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean)
}
