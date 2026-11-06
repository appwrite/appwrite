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
export {
  COMMAND_CENTER_MAX_RESOURCE_HITS,
  COMMAND_CENTER_RESOURCE_LIMIT,
  PROJECT_RESOURCE_KIND_LABELS,
  getMessageSearchLabel,
  searchCommandsWithScores,
  type ProjectResourceHit,
  type ProjectResourceKind,
  type ProjectResourceSection,
} from './resource-search'
export {
  RECENT_RESOURCES_MAX_SHOWN,
  filterRecentResources,
  getRecentResourceDatabaseIconHints,
  getRecentResourceSiteFramework,
  getRecentResourceBreadcrumbs,
  parseRecentResourceRef,
  type RecentResource,
} from './recent-resources'

// Eager registration of built-in entries.
import './entries'
