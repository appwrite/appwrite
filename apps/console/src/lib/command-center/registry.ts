/**
 * Central in-memory registry of Command Center entries.
 *
 * Entry files in `./entries/` call `registerCommands(...)` at module load.
 * The Command Center component imports `./entries/index.ts` which triggers
 * registration as a side effect.
 */

import type { CommandContext, CommandEntry, CommandScope } from './types'

const entries: CommandEntry[] = []
const seen = new Set<string>()

/** Register a batch of entries. Duplicate ids are ignored (HMR-safe). */
export function registerCommands(items: CommandEntry[]): void {
  for (const item of items) {
    if (seen.has(item.id)) continue
    seen.add(item.id)
    entries.push(item)
  }
}

/** Internal helper - exposed for tests / debugging. */
export function getAllCommands(): CommandEntry[] {
  return entries
}

/**
 * Filter the registry for the given context: keep entries whose `scopes`
 * include the active scope and whose `available()` (if any) returns true.
 *
 * Entries are returned in insertion order; the search layer applies the
 * final ordering based on the user's query.
 */
export function getCommandsForContext(ctx: CommandContext): CommandEntry[] {
  return entries.filter((entry) => {
    if (!entry.scopes.includes(ctx.scope)) return false
    if (entry.available && !entry.available(ctx)) return false
    return true
  })
}

/** Filter all entries by scope without requiring a full context (for static lookups). */
export function getCommandsForScope(scope: CommandScope): CommandEntry[] {
  return entries.filter((entry) => entry.scopes.includes(scope))
}
