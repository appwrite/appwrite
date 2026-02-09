/**
 * Realtime channels and events used by the Console.
 * Keep this list aligned with the backend and documentation so realtime
 * behavior stays consistent and auditable.
 */

/** Channels for console-level subscriptions (project = 'console') */
export const CONSOLE_CHANNELS = ['console'] as const

/**
 * Channels for project-level subscriptions (console client, project=console).
 * Use only 'console'; the 'project' channel causes server errors when used with console.
 */
export const PROJECT_CHANNELS = ['console'] as const

/** Event name patterns we listen to (for branching in the handler) */
export const REALTIME_EVENTS = {
  // Sites
  SITES_ANY: 'sites.*',
  SITES_DEPLOYMENTS_ANY: 'sites.*.deployments.*',
  SITES_DEPLOYMENT_CREATE: 'sites.*.deployments.*.create',
  SITES_DEPLOYMENT_UPDATE: 'sites.*.deployments.*.update',
  SITES_DEPLOYMENT_DELETE: 'sites.*.deployments.*.delete',
  SITES_EXECUTIONS_ANY: 'sites.*.executions.*',

  // Functions
  FUNCTIONS_DEPLOYMENTS_ANY: 'functions.*.deployments.*',
  FUNCTIONS_DEPLOYMENT_CREATE: 'functions.*.deployments.*.create',
  FUNCTIONS_DEPLOYMENT_UPDATE: 'functions.*.deployments.*.update',
  FUNCTIONS_DEPLOYMENT_DELETE: 'functions.*.deployments.*.delete',
  FUNCTIONS_EXECUTIONS_ANY: 'functions.*.executions.*',

  // Databases / Tables (TablesDB)
  DATABASES_TABLES_COLUMNS_ANY: 'databases.*.tables.*.columns.*',
  DATABASES_TABLES_COLUMNS_CREATE: 'databases.*.tables.*.columns.*.create',
  DATABASES_TABLES_COLUMNS_UPDATE: 'databases.*.tables.*.columns.*.update',
  DATABASES_TABLES_COLUMNS_DELETE: 'databases.*.tables.*.columns.*.delete',
  DATABASES_TABLES_INDEXES_ANY: 'databases.*.tables.*.indexes.*',

  // Backups & restorations
  ARCHIVES_ANY: 'archives.*',
  RESTORATIONS_ANY: 'restorations.*',
  POLICIES_ANY: 'policies.*',

  // Migrations
  MIGRATIONS_ANY: 'migrations.*',

  // Project & platform
  PROJECT_PING: 'projects.', // prefix: projects.${projectId}.ping
  STATS_CONNECTIONS: 'stats.connections',

  // Domains / rules (proxy)
  RULES_UPDATE: 'rules.*.update',
} as const
