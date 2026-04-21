/**
 * Shared types for CommandCenter that need to be importable without
 * pulling in the full component file.
 */

export type CommandCenterContext = 'project' | 'org' | 'account'

export type CreateResourceType =
  | 'database'
  | 'bucket'
  | 'user'
  | 'team'
  | 'function'
  | 'site'
