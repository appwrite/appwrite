/**
 * React Query Hooks - Organized by Domain
 *
 * This index file re-exports all hooks from domain-specific modules.
 * The hooks are organized by resource type for better maintainability.
 *
 * All hooks have been migrated from the monolithic hooks.ts file into
 * domain-specific modules. This barrel file provides a single import point
 * for all hooks while maintaining clear organization.
 */

// Re-export constants
export * from './constants'

// Re-export dependencies
export * from './dependencies'

// Re-export from organized modules
export * from './organizations'
export * from './addons'
export * from './teams'
export * from './projects'
export * from './onboarding'
export * from './users'
export * from './databases'
export * from './postgres-databases'
export * from './dedicated-database-backups'
export * from './postgres-database-backups'
export * from './postgres-database-replication'
export * from './dedicated-database-replication'
export * from './mysql-databases'
export * from './mysql-database-backups'
export * from './mysql-database-replication'
export * from './postgres-extensions'
export * from './postgres-metrics'
export * from './mysql-metrics'
export * from './storage'
export * from './functions'
export * from './sites'
export * from './distribution'
export * from './auth'
export * from './community-support-prompt'
export * from './account-applications'
export * from './affiliates'
export * from './oauth2-providers'
export * from './apps'
export * from './project-oauth2-apps'
export * from './console-project-scopes'
export * from './email'
export * from './webhooks'
export * from './waf'
export * from './migrations'
export * from './smtp'
export * from './domains'
export * from './project-domains'
export * from './backups'
export * from './locale'
export * from './vcs'
export * from './messaging'
export * from './realtime'
export * from './regions'
export * from './console-variables'
export * from './status'
export * from './assistant'
export * from './agent-usage'
export * from './api-explorer'
export * from './activities'
export * from './usage-events'
export * from './dedicated-databases-usage'
export * from './manager'
export * from './notifications'
