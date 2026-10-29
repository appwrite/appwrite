/**
 * Smart, dependency-free search over `CommandEntry` objects.
 *
 * Scoring (multi-token AND match):
 *   - Each search token must match SOMEWHERE in label / description /
 *     keywords / group, otherwise the entry is rejected.
 *   - Per-token score uses the strongest signal across fields:
 *
 *       label === query                              1000
 *       label starts with token                       500
 *       label word starts with token                  350
 *       label contains token                          200
 *       keyword === token                             220
 *       keyword starts with token                     160
 *       keyword contains token                        100
 *       description word starts with token            120
 *       description contains token                     60
 *       group contains token                           30
 *
 *   - The final score is the sum across tokens, with a small bonus for
 *     a perfect label match on the full query.
 */

import { DEFAULT_GROUP_LABELS, type CommandEntry } from './types'

export interface ScoredCommand {
  entry: CommandEntry
  score: number
}

const TOKEN_RE = /\s+/

function normalize(s: string | undefined | null): string {
  return (s ?? '').toLowerCase().trim()
}

function wordStartsWith(haystack: string, token: string): boolean {
  if (!token) return false
  if (haystack.startsWith(token)) return true
  // Match any whole-word boundary (space, dash, slash, dot).
  return new RegExp(`(^|[\\s\\-/\\._:])${escapeRegExp(token)}`).test(haystack)
}

function escapeRegExp(s: string): string {
  return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

function scoreToken(entry: CommandEntry, token: string): number {
  if (!token) return 0
  const label = normalize(entry.label)
  const description = normalize(entry.description)
  const group = normalize(entry.group ?? DEFAULT_GROUP_LABELS[entry.kind])
  const keywords = (entry.keywords ?? []).map(normalize)

  let best = 0

  if (label === token) best = Math.max(best, 1000)
  if (label.startsWith(token)) best = Math.max(best, 500)
  if (wordStartsWith(label, token)) best = Math.max(best, 350)
  if (label.includes(token)) best = Math.max(best, 200)

  for (const k of keywords) {
    if (k === token) {
      best = Math.max(best, 220)
    } else if (k.startsWith(token)) {
      best = Math.max(best, 160)
    } else if (k.includes(token)) {
      best = Math.max(best, 100)
    }
  }

  if (description) {
    if (wordStartsWith(description, token)) best = Math.max(best, 120)
    else if (description.includes(token)) best = Math.max(best, 60)
  }

  if (group.includes(token)) best = Math.max(best, 30)

  return best
}

/**
 * Search and rank entries against a query string.
 * Returns entries that match every token (AND semantics), highest score first.
 */
export function searchCommands(
  query: string,
  entries: CommandEntry[],
): ScoredCommand[] {
  const q = normalize(query)
  if (!q) {
    return entries.map((entry) => ({ entry, score: 0 }))
  }
  const tokens = q.split(TOKEN_RE).filter(Boolean)
  const results: ScoredCommand[] = []

  for (const entry of entries) {
    let total = 0
    let allMatched = true
    for (const token of tokens) {
      const s = scoreToken(entry, token)
      if (s <= 0) {
        allMatched = false
        break
      }
      total += s
    }
    if (!allMatched) continue
    // Small bonus when the entire query matches the label exactly.
    if (normalize(entry.label) === q) total += 500
    results.push({ entry, score: total })
  }

  results.sort((a, b) => {
    if (b.score !== a.score) return b.score - a.score
    return a.entry.label.localeCompare(b.entry.label)
  })
  return results
}
