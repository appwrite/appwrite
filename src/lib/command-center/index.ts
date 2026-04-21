/** Public surface for the Command Center registry. */

export type {
  CommandContext,
  CommandEntry,
  CommandKind,
  CommandScope,
} from './types'
export { DEFAULT_GROUP_LABELS } from './types'
export {
  getAllCommands,
  getCommandsForContext,
  getCommandsForScope,
  registerCommands,
} from './registry'
export { searchCommands, type ScoredCommand } from './search'

// Eager registration of built-in entries.
import './entries'
