/**
 * Centralized role-based access checks for the console.
 * Use these instead of reading access.isOwner, access.canWrite*, etc. directly in UI or routes.
 * When orgRoles is false, all project checks return true (full access); org checks use the same logic.
 */

import type { ConsoleAccess } from '@/lib/console-roles'
import type { ConsoleProfileFeatures } from '@/lib/console-profiles'
import { isCloudProfile } from '@/lib/console-profiles'

/** Minimal features needed for most checks; pass full features from useConsoleProfile() where available. */
export type AccessCheckFeatures = Pick<ConsoleProfileFeatures, 'orgRoles'> &
  Partial<ConsoleProfileFeatures>

/**
 * Organization switcher and transfer targets. Multi-tenant profiles always
 * expose them. Single-tenant profiles only block creating a second
 * organization; an account that already belongs to several (a self-hosted
 * 1.x instance upgraded to 2.0) must still be able to reach every one of them.
 */
export function canSwitchOrganizations(
  features: Pick<ConsoleProfileFeatures, 'multiTenancy'>,
  organizationCount: number,
): boolean {
  return features.multiTenancy || organizationCount > 1
}

function whenOrgRoles(
  _access: ConsoleAccess,
  features: AccessCheckFeatures,
  hasAccess: boolean,
): boolean {
  return !features.orgRoles || hasAccess
}

export function canShowProjectSettings(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return whenOrgRoles(access, features, access.canWriteProjects)
}

/** Connect section (Apps, API Keys) and Get started section: owners and developers only. */
export function canShowConnectSection(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return whenOrgRoles(access, features, access.isOwner || access.isDeveloper)
}

/** Built-in project CLI terminal: owners and developers only. */
export function canShowProjectTerminal(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return whenOrgRoles(access, features, access.isOwner || access.isDeveloper)
}

/** Alias for same rule as Connect (owners and developers). */
export function canShowGetStartedSection(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return canShowConnectSection(access, features)
}

/** Team-level saved filters: owners and developers only. */
export function canSaveTeamFilters(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return whenOrgRoles(access, features, access.isOwner || access.isDeveloper)
}

/** Pin/unpin projects in organization: owners and developers only. */
export function canPinProjects(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return whenOrgRoles(access, features, access.isOwner || access.isDeveloper)
}

export function canCreateProject(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return whenOrgRoles(access, features, access.canWriteProjects)
}

export function canCreateDatabase(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return whenOrgRoles(access, features, access.canWriteDatabases)
}

export function canCreateRow(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return whenOrgRoles(access, features, access.canWriteRows)
}

export function canCreateBucket(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return whenOrgRoles(access, features, access.canWriteBuckets)
}

export function canCreateFunction(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return whenOrgRoles(access, features, access.canWriteFunctions)
}

/**
 * Analytics properties are project-level configuration and the API does not
 * expose a dedicated analytics scope yet, so they follow `projects.write`.
 */
export function canCreateAnalyticsProperty(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return whenOrgRoles(access, features, access.canWriteProjects)
}

export function canCreateSite(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return whenOrgRoles(access, features, access.canWriteSites)
}

export function canCreateUser(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return whenOrgRoles(access, features, access.canWriteUsers)
}

export function canCreateTeam(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return whenOrgRoles(access, features, access.canWriteTeams)
}

export function canCreateKey(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return whenOrgRoles(access, features, access.canWriteKeys)
}

export function canCreatePlatform(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return whenOrgRoles(access, features, access.canWritePlatforms)
}

/** Auth/end-user `domains.write`. Not granted to console owners. Use `canWriteProjectDomains` for Settings → Custom domains. */
export function canWriteDomains(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return whenOrgRoles(access, features, access.canWriteDomains)
}

/**
 * Project custom domains (API / Functions / Sites proxy rules).
 * Console owner and developer roles include `rules.write`, not `domains.write`.
 * `domains.write` is an Auth/end-user scope and is not granted to org owners.
 */
export function canWriteProjectDomains(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return whenOrgRoles(access, features, access.canWriteRules)
}

export function canWriteWebhooks(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return whenOrgRoles(access, features, access.canWriteWebhooks)
}

export function canCreateMigration(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return whenOrgRoles(access, features, access.canWriteMigrations)
}

export function canWriteRules(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return whenOrgRoles(access, features, access.canWriteRules)
}

export function canShowDatabaseSecuritySettings(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return whenOrgRoles(access, features, access.canWriteDatabases)
}

export function canShowTableSecuritySettings(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return whenOrgRoles(access, features, access.canWriteTables)
}

export function canShowBucketSecuritySettings(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return whenOrgRoles(access, features, access.canWriteBuckets)
}

export function canShowFunctionSecuritySettings(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return whenOrgRoles(access, features, access.canWriteFunctions)
}

export function canShowSiteSettingsTab(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return whenOrgRoles(access, features, access.canWriteSites)
}

export function canShowAuthSecuritySettings(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return whenOrgRoles(
    access,
    features,
    access.canWriteUsers || access.canWriteTeams,
  )
}

export function canShowProjectOAuth2Server(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return (
    isCloudProfile() && canShowAuthSecuritySettings(access, features)
  )
}

export function canShowTopicSettingsTab(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return whenOrgRoles(access, features, access.canWriteTopics)
}

export function canSeeProjectNavItem(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
  itemId: string,
): boolean {
  if (!features.orgRoles) return true
  switch (itemId) {
    case 'apps':
    case 'api-keys':
    case 'explorer':
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
    case 'realtime':
    case 'analytics':
      return access.canSeeProjects
    case 'activity':
      // Activity API requires `events.read` (not just `projects.read`).
      return access.canSeeEvents
    case 'firewall':
      return access.canSeeProjects
    default:
      return true
  }
}

export function canSeeUsageNav(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return !features.orgRoles || access.canSeeProjects
}

/** Project Activity tab: requires `events.read` (activities.listEvents). */
export function canSeeActivityNav(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return !features.orgRoles || access.canSeeEvents
}

export function canSeeProjects(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return whenOrgRoles(access, features, access.canSeeProjects)
}

export function canShowOrgDomainsTab(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return canAccessOrgDomains(access, features)
}

/** Buy domain and transfer-in (registrar commerce). */
export function canBuyOrTransferOrgDomain(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return canAccessOrgDomains(access, features)
}

/** Organization marketplace tab (cloud profile; browse integrations). */
export function canShowOrgMarketplaceTab(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return isCloudProfile() && canSeeProjects(access, features)
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
export function canAccessOrgSettingsOAuthOrApiKeys(
  access: ConsoleAccess,
): boolean {
  return access.isOwner || access.isDeveloper
}

/** Organization settings → OAuth apps (cloud profile + owners/developers). */
export function canShowOrgOAuthAppsSettings(
  access: ConsoleAccess,
  _features: AccessCheckFeatures,
): boolean {
  return isCloudProfile() && canAccessOrgSettingsOAuthOrApiKeys(access)
}

/** Organization settings → Org API keys (console profile + owners/developers). */
export function canShowOrgApiKeysSettings(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return !!features.orgApiKeys && canAccessOrgSettingsOAuthOrApiKeys(access)
}

/** Create/update/delete organization API keys. Same audience as the settings tab. */
export function canCreateOrgApiKey(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return canShowOrgApiKeysSettings(access, features)
}

/**
 * Organization Domains (route access). The `/v1/domains` API is cloud-only, so
 * the whole surface is hidden on self-hosted; with org roles enabled it is
 * further limited to owners and developers.
 */
export function canAccessOrgDomains(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  if (!isCloudProfile()) return false
  return whenOrgRoles(access, features, access.isOwner || access.isDeveloper)
}

export function canShowOrgBillingNav(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return !!(features.billing && (!features.orgRoles || access.canSeeBilling))
}

export function canShowOrgComplianceNav(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return !!(features.compliance && (!features.orgRoles || access.isOwner))
}

export function canInviteOrgMember(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return whenOrgRoles(access, features, access.isOwner)
}

export function canWriteMessages(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return whenOrgRoles(access, features, access.canWriteMessages)
}

export function canWriteTopics(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return whenOrgRoles(access, features, access.canWriteTopics)
}

export function canWriteProviders(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): boolean {
  return whenOrgRoles(access, features, access.canWriteProviders)
}

/** Returns the first org settings sub-path the current role can access (menu order). */
export function getFirstAllowedOrgSettingsPath(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
  basePath: string,
): string {
  if (canAccessOrgSettingsOverview(access)) return basePath
  if (canAccessOrgSettingsMembers(access)) return `${basePath}/members`
  if (canAccessOrgSettingsBilling(access)) return `${basePath}/billing`
  if (canAccessOrgSettingsCompliance(access) && features.compliance)
    return `${basePath}/compliance`
  if (canShowOrgOAuthAppsSettings(access, features))
    return `${basePath}/oauth-apps`
  if (canShowOrgApiKeysSettings(access, features)) return `${basePath}/partners`
  return basePath
}

/**
 * First org overview route when opening an organization: Projects → Domains → Settings
 * (settings resolves to the first allowed settings sub-path, same order as the tab bar).
 */
export function getFirstAllowedOrgOverviewPath(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
): string {
  if (canSeeProjects(access, features)) {
    return '/organizations/$orgId'
  }
  if (canShowOrgMarketplaceTab(access, features)) {
    return '/organizations/$orgId/marketplace/'
  }
  if (canShowOrgDomainsTab(access, features)) {
    return '/organizations/$orgId/domains/'
  }
  if (canShowOrgSettingsTab(access)) {
    return getFirstAllowedOrgSettingsPath(
      access,
      features,
      '/organizations/$orgId/settings',
    )
  }
  return '/organizations/$orgId'
}

/** Whether the user may use the current org overview top-level tab (projects / domains / settings). */
export function canAccessOrgOverviewTab(
  access: ConsoleAccess,
  features: AccessCheckFeatures,
  tab: 'projects' | 'marketplace' | 'domains' | 'settings',
): boolean {
  if (tab === 'projects') return canSeeProjects(access, features)
  if (tab === 'marketplace') return canShowOrgMarketplaceTab(access, features)
  if (tab === 'domains') return canShowOrgDomainsTab(access, features)
  return canShowOrgSettingsTab(access)
}
