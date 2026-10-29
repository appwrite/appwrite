import { stripFrontmatter } from '@/lib/docs/frontmatter'

export function parseChangelogFrontmatter(raw: string): {
  frontmatter: Record<string, string>
  body: string
} {
  const match = raw.match(/^\s*---\r?\n([\s\S]*?)\r?\n---\s*\r?\n?/)
  if (!match) {
    return { frontmatter: {}, body: stripFrontmatter(raw) }
  }

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

  return {
    frontmatter,
    body: raw.slice(match[0].length),
  }
}
