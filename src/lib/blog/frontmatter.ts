import yaml from 'js-yaml'

export function parseBlogFrontmatter(raw: string): {
  frontmatter: Record<string, unknown>
  body: string
} {
  const match = raw.match(/^\s*---\r?\n([\s\S]*?)\r?\n---\s*\r?\n?/)
  if (!match) {
    return { frontmatter: {}, body: raw }
  }

  const parsed = yaml.load(match[1])
  const frontmatter =
    parsed && typeof parsed === 'object' && !Array.isArray(parsed)
      ? (parsed as Record<string, unknown>)
      : {}

  return {
    frontmatter,
    body: raw.slice(match[0].length),
  }
}

export function getFrontmatterString(
  frontmatter: Record<string, unknown>,
  key: string,
): string | undefined {
  const value = frontmatter[key]
  if (typeof value === 'string') return value
  if (typeof value === 'number' || typeof value === 'boolean') {
    return String(value)
  }
  return undefined
}

export function getFrontmatterDate(
  frontmatter: Record<string, unknown>,
  key: string,
): string {
  const value = frontmatter[key]
  if (value instanceof Date && !Number.isNaN(value.getTime())) {
    return value.toISOString().slice(0, 10)
  }
  if (typeof value === 'string' && value.trim()) {
    return value.trim()
  }
  if (typeof value === 'number' && Number.isFinite(value)) {
    const date = new Date(value < 1e12 ? value * 1000 : value)
    if (!Number.isNaN(date.getTime())) {
      return date.toISOString().slice(0, 10)
    }
  }
  return ''
}

export function getFrontmatterStringArray(
  frontmatter: Record<string, unknown>,
  key: string,
): string[] | undefined {
  const value = frontmatter[key]
  if (typeof value === 'string') return [value]
  if (!Array.isArray(value)) return undefined
  return value.filter((item): item is string => typeof item === 'string')
}

export function getFrontmatterAuthor(
  frontmatter: Record<string, unknown>,
): string | string[] {
  const value = frontmatter.author
  if (typeof value === 'string') return value
  if (Array.isArray(value)) {
    return value.filter((item): item is string => typeof item === 'string')
  }
  return ''
}

export function getFrontmatterFaqs(
  frontmatter: Record<string, unknown>,
): { question: string; answer: string }[] | undefined {
  const value = frontmatter.faqs
  if (!Array.isArray(value)) return undefined

  const faqs = value
    .map((item) => {
      if (!item || typeof item !== 'object') return null
      const record = item as Record<string, unknown>
      const question =
        typeof record.question === 'string' ? record.question : undefined
      const answer = typeof record.answer === 'string' ? record.answer : undefined
      if (!question || !answer) return null
      return { question, answer }
    })
    .filter((item): item is { question: string; answer: string } => item !== null)

  return faqs.length > 0 ? faqs : undefined
}
