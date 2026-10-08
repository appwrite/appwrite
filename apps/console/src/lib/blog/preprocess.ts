const LINK_ONLY_LIST_ITEM_RE =
  /^(\s*(?:[-*+]|(?:\d+\.)))\s+\[([^\]]+)\]\(([^)\s]+(?:\([^)]*\)[^)\s]*)*)\)\s*$/

export function preprocessBlogMarkdocContent(content: string): string {
  const lines = content.split('\n')
  const result: string[] = []
  let index = 0

  while (index < lines.length) {
    const linkMatch = lines[index].match(LINK_ONLY_LIST_ITEM_RE)

    if (!linkMatch) {
      result.push(lines[index])
      index += 1
      continue
    }

    while (index < lines.length) {
      const match = lines[index].match(LINK_ONLY_LIST_ITEM_RE)
      if (!match) break

      const label = match[2].trim()
      const href = match[3].trim()
      result.push(`{% arrow_link href="${href}" %}`)
      result.push(label)
      result.push('{% /arrow_link %}')
      index += 1
    }
  }

  return result.join('\n')
}
