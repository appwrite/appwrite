/**
 * Centralized role-based access checks for the console.
 * Use these instead of reading access.isOwner, access.canWrite*, etc. directly in UI or routes.
 * When orgRoles is false, all project checks return true (full access); org checks use the same logic.
 */

import type { ConsoleAccess } from '@/lib/console-roles'
import type { ConsoleProfileFeatures } from '@/lib/console-profiles'

/** Minimal features needed for most checks; pass full features from useConsoleProfile() where available. */
export type AccessCheckFeatures = Pick<ConsoleProfileFeatures, 'orgRoles'> &
  Partial<ConsoleProfileFeatures>

function whenOrgRoles(_access: ConsoleAccess, features: AccessCheckFeatures, hasAccess: boolean): boolean {
  return !features.orgRoles || hasAccess
}

// ─── Project: settings, connect, get started ───────────────────────────────────

export function canShowProjectSettings(access: ConsoleAccess, features: AccessCheckFeatures): boolean {
  return whenOrgRoles(access, features, access.canWriteProjects)
}

/** Connect section (Apps, API Keys) and Get started section: owners and developers only. */
export function canShowConnectSection(access: ConsoleAccess, features: AccessCheckFeatures): boolean {
  return whenOrgRoles(access, features, access.isOwner || access.isDeveloper)
}

/** Alias for same rule as Connect (owners and developers). */
export function canShowGetStartedSection(access: ConsoleAccess, features: AccessCheckFeatures): boolean {
  return canShowConnectSection(access, features)
}

// ─── Project: create permissions ──────────────────────────────────────────────

export function canCreateProject(access: ConsoleAccess, features: AccessCheckFeatures): boolean {
  return whenOrgRoles(access, features, access.canWriteProjects)
}

export function canCreateDatabase(access: ConsoleAccess, features: AccessCheckFeatures): boolean {
  return whenOrgRoles(access, features, access.canWriteDatabases)
}

export function canCreateRow(access: ConsoleAccess, features: AccessCheckFeatures): boolean {
  return whenOrgRoles(access, features, access.canWriteRows)
}

export function canCreateBucket(access: ConsoleAccess, features: AccessCheckFeatures): boolean {
  return whenOrgRoles(access, features, access.canWriteBuckets)
}

export function canCreateFunction(access: ConsoleAccess, features: AccessCheckFeatures): boolean {
  return whenOrgRoles(access, features, access.canWriteFunctions)
}

export function canCreateSite(access: ConsoleAccess, features: AccessCheckFeatures): boolean {
  return whenOrgRoles(access, features, access.canWriteSites)
}

export function canCreateUser(access: ConsoleAccess, features: AccessCheckFeatures): boolean {
  return whenOrgRoles(access, features, access.canWriteUsers)
}

export function canCreateTeam(access: ConsoleAccess, features: AccessCheckFeatures): boolean {
  return whenOrgRoles(access, features, access.canWriteTeams)
}

export function canCreateKey(access: ConsoleAccess, features: AccessCheckFeatures): boolean {
  return whenOrgRoles(access, features, access.canWriteKeys)
}

export function canCreatePlatform(access: ConsoleAccess, features: AccessCheckFeatures): boolean {
  return whenOrgRoles(access, features, access.canWritePlatforms)
}

export function canWriteDomains(access: ConsoleAccess, features: AccessCheckFeatures): boolean {
  return whenOrgRoles(access, features, access.canWriteDomains)
}

export function canWriteWebhooks(access: ConsoleAccess, features: AccessCheckFeatures): boolean {
  return whenOrgRoles(access, features, access.canWriteWebhooks)
}

export function canWriteRules(access: ConsoleAccess, features: AccessCheckFeatures): boolean {
  return whenOrgRoles(access, features, access.canWriteRules)
}

// ─── Project: service security/settings tabs ────────────────────────────────

export function canShowDatabaseSecuritySettings(access: ConsoleAccess, features: AccessCheckFeatures): boolean {
  return whenOrgRoles(access, features, access.canWriteDatabases)
}

export function canShowTableSecuritySettings(access: ConsoleAccess, features: AccessCheckFeatures): boolean {
  return whenOrgRoles(access, features, access.canWriteTables)
}

export function canShowBucketSecuritySettings(access: ConsoleAccess, features: AccessCheckFeatures): boolean {
  return whenOrgRoles(access, features, access.canWriteBuckets)
}

export function canShowFunctionSecuritySettings(access: ConsoleAccess, features: AccessCheckFeatures): boolean {
  return whenOrgRoles(access, features, access.canWriteFunctions)
}

export function canShowSiteSettingsTab(access: ConsoleAccess, features: AccessCheckFeatures): boolean {
  return whenOrgRoles(access, features, access.canWriteSites)
}

export function canShowAuthSecuritySettings(access: ConsoleAccess, features: AccessCheckFeatures): boolean {
  return whenOrgRoles(access, features, access.canWriteUsers || access.canWriteTeams)
}

export function canShowTopicSettingsTab(access: ConsoleAccess, features: AccessCheckFeatures): boolean {
  return whenOrgRoles(access, features, access.canWriteTopics)
}

// ─── Project: sidebar nav item visibility ───────────────────────────────────

export function canSeeProjectNavItem(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
  itemId: string,
): boolean {
  if (!features.orgRoles) return true
  switch (itemId) {
    case 'apps':
    case 'api-keys':
      return (access.isOwner || access.isDeveloper) && access.canSeeProjects
    case 'databases':
      return access.canSeeDatabases
    case 'storage':
      return access.canSeeBuckets
    case 'functions':
      return access.canSeeFunctions
    case 'messaging':
      return access.canSeeMessages
    case 'sites':
      return access.canWriteSites
    case 'usage':
    case 'activity':
    case 'realtime':
    case 'analytics':
      return access.canSeeProjects
    default:
      return true
  }
}

export function canSeeUsageNav(access: ConsoleAccess, features: AccessCheckFeatures): boolean {
  return !features.orgRoles || access.canSeeProjects
}

export function canSeeActivityNav(access: ConsoleAccess, features: AccessCheckFeatures): boolean {
  return !features.orgRoles || access.canSeeProjects
}

export function canSeeProjects(access: ConsoleAccess, features: AccessCheckFeatures): boolean {
  return whenOrgRoles(access, features, access.canSeeProjects)
}

// ─── Org: tabs and settings ──────────────────────────────────────────────────

export function canShowOrgDomainsTab(access: ConsoleAccess, features: AccessCheckFeatures): boolean {
  return !!(features.domains && (!features.orgRoles || access.isOwner || access.isDeveloper))
}

export function canShowOrgSettingsTab(access: ConsoleAccess): boolean {
  return access.isOwner || access.canSeeBilling || access.canSeeTeams
}

export function canAccessOrgSettingsOverview(access: ConsoleAccess): boolean {
  return access.isOwner
}

export function canAccessOrgSettingsMembers(access: ConsoleAccess): boolean {
  return access.canSeeTeams
}

export function canAccessOrgSettingsBilling(access: ConsoleAccess): boolean {
  return access.canSeeBilling
}

export function canAccessOrgSettingsCompliance(access: ConsoleAccess): boolean {
  return access.isOwner
}

/** OAuth apps and API keys in org settings: owners and developers. */
export function canAccessOrgSettingsOAuthOrApiKeys(access: ConsoleAccess): boolean {
  return access.isOwner || access.isDeveloper
}

/** Organization Domains (route access): owners and developers when orgRoles enabled. */
export function canAccessOrgDomains(access: ConsoleAccess, features: AccessCheckFeatures): boolean {
  return whenOrgRoles(access, features, access.isOwner || access.isDeveloper)
}

export function canShowOrgBillingNav(access: ConsoleAccess, features: AccessCheckFeatures): boolean {
  return !!(features.billing && (!features.orgRoles || access.canSeeBilling))
}

export function canShowOrgComplianceNav(access: ConsoleAccess, features: AccessCheckFeatures): boolean {
  return !!(features.compliance && (!features.orgRoles || access.isOwner))
}

export function canInviteOrgMember(access: ConsoleAccess, features: AccessCheckFeatures): boolean {
  return whenOrgRoles(access, features, access.isOwner)
}

// ─── Messaging: write per tab ───────────────────────────────────────────────

export function canWriteMessages(access: ConsoleAccess, features: AccessCheckFeatures): boolean {
  return whenOrgRoles(access, features, access.canWriteMessages)
}

export function canWriteTopics(access: ConsoleAccess, features: AccessCheckFeatures): boolean {
  return whenOrgRoles(access, features, access.canWriteTopics)
}

export function canWriteProviders(access: ConsoleAccess, features: AccessCheckFeatures): boolean {
  return whenOrgRoles(access, features, access.canWriteProviders)
}

// ─── Org settings: first allowed sub-tab (menu order) ─────────────────────────

/** Returns the first org settings sub-path the current role can access (menu order). */
export function getFirstAllowedOrgSettingsPath(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
  basePath: string,
): string {
  if (canAccessOrgSettingsOverview(access)) return basePath
  if (canAccessOrgSettingsMembers(access)) return `${basePath}/members`
  if (canAccessOrgSettingsBilling(access)) return `${basePath}/billing`
  if (canAccessOrgSettingsCompliance(access) && features.compliance) return `${basePath}/compliance`
  if (canAccessOrgSettingsOAuthOrApiKeys(access) && features.oauthApps) return `${basePath}/oauth-apps`
  if (canAccessOrgSettingsOAuthOrApiKeys(access) && features.orgApiKeys) return `${basePath}/api-keys`
  return basePath
}
