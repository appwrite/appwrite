/**
 * Console roles and scopes for RBAC.
 * When the profile does not support roles (orgRoles: false), treat all users as owners with full scopes.
 * API: organizations.getScopes({ organizationId }) → { roles: string[], scopes: string[] }
 */

/** Default roles when API is unavailable (e.g. self-hosted or error) */
export const DEFAULT_ROLES = ['owner'] as const

/** Full scope list for default/fallback when API is unavailable */
export const DEFAULT_SCOPES = [
  'projects.read',
  'projects.write',
  'databases.read',
  'databases.write',
  'tables.write',
  'collections.write',
  'rows.write',
  'documents.write',
  'functions.read',
  'functions.write',
  'buckets.read',
  'buckets.write',
  'keys.write',
  'platforms.write',
  'webhooks.write',
  'users.write',
  'teams.read',
  'teams.write',
  'messages.read',
  'messages.write',
  'topics.write',
  'providers.write',
  'subscribers.write',
  'sites.read',
  'sites.write',
  'domains.write',
  'executions.write',
  'migrations.write',
  'vcs.write',
  'rules.write',
  'billing.read',
] as const

export type OrganizationRolesScopes = {
  roles: string[]
  scopes: string[]
}

export type ConsoleAccess = {
  // Role-based (organization level)
  isOwner: boolean
  isDeveloper: boolean
  isBilling: boolean
  // Read scopes (project level)
  canSeeProjects: boolean
  canSeeDatabases: boolean
  canSeeFunctions: boolean
  canSeeBuckets: boolean
  canSeeMessages: boolean
  canSeeTeams: boolean
  canSeeSites: boolean
  canSeeBilling: boolean
  // Write scopes (project level)
  canWriteProjects: boolean
  canWriteDatabases: boolean
  canWriteTables: boolean
  canWriteRows: boolean
  canWriteFunctions: boolean
  canWriteBuckets: boolean
  canWriteKeys: boolean
  canWritePlatforms: boolean
  canWriteWebhooks: boolean
  canWriteUsers: boolean
  canWriteTeams: boolean
  canWriteMessages: boolean
  canWriteTopics: boolean
  canWriteProviders: boolean
  canWriteSites: boolean
  canWriteDomains: boolean
  canWriteExecutions: boolean
  canWriteMigrations: boolean
  canWriteVcs: boolean
  canWriteRules: boolean
  canWriteSubscribers: boolean
}

/** Derive access booleans from roles and scopes (per guide). */
export function deriveAccessFromRolesScopes(
  roles: string[],
  scopes: string[],
): ConsoleAccess {
  const roleSet = new Set(roles)
  const scopeSet = new Set(scopes)
  const has = (s: string) => scopeSet.has(s)
  const hasTableOrCollections = has('tables.write') || has('collections.write')
  const hasRowsOrDocuments = has('rows.write') || has('documents.write')

  return {
    isOwner: roleSet.has('owner'),
    isDeveloper: roleSet.has('developer'),
    isBilling: roleSet.has('billing'),
    canSeeProjects: has('projects.read'),
    canSeeDatabases: has('databases.read'),
    canSeeFunctions: has('functions.read'),
    canSeeBuckets: has('buckets.read'),
    canSeeMessages: has('messages.read'),
    canSeeTeams: has('teams.read'),
    canSeeSites: has('sites.read'),
    canSeeBilling: has('billing.read'),
    canWriteProjects: has('projects.write'),
    canWriteDatabases: has('databases.write'),
    canWriteTables: hasTableOrCollections,
    canWriteRows: hasRowsOrDocuments,
    canWriteFunctions: has('functions.write'),
    canWriteBuckets: has('buckets.write'),
    canWriteKeys: has('keys.write'),
    canWritePlatforms: has('platforms.write'),
    canWriteWebhooks: has('webhooks.write'),
    canWriteUsers: has('users.write'),
    canWriteTeams: has('teams.write'),
    canWriteMessages: has('messages.write'),
    canWriteTopics: has('topics.write'),
    canWriteProviders: has('providers.write'),
    canWriteSites: has('sites.write'),
    canWriteDomains: has('domains.write'),
    canWriteExecutions: has('executions.write'),
    canWriteMigrations: has('migrations.write'),
    canWriteVcs: has('vcs.write'),
    canWriteRules: has('rules.write'),
    canWriteSubscribers: has('subscribers.write'),
  }
}

/** Full access (owner + all scopes) for when profile does not support roles. */
export const FULL_ACCESS = deriveAccessFromRolesScopes(
  [...DEFAULT_ROLES],
  [...DEFAULT_SCOPES],
)
