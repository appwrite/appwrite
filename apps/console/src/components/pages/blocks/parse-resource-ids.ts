const ID_SEPARATOR = /[\s,;]+/

/**
 * Split a pasted list of resource IDs into a unique, ordered list.
 * Accepts newlines, commas, semicolons, and other whitespace.
 */
export function parseBlockResourceIds(input: string): {
  ids: string[]
  duplicateCount: number
} {
  const seen = new Set<string>()
  const ids: string[] = []
  let duplicateCount = 0

  for (const raw of input.split(ID_SEPARATOR)) {
    let id = raw.trim()
    if (
      id.length >= 2 &&
      ((id.startsWith('"') && id.endsWith('"')) ||
        (id.startsWith("'") && id.endsWith("'")))
    ) {
      id = id.slice(1, -1).trim()
    }
    if (!id) continue
    if (seen.has(id)) {
      duplicateCount += 1
      continue
    }
    seen.add(id)
    ids.push(id)
  }

  return { ids, duplicateCount }
}
