/**
 * RBAC helpers for route loaders.
 * Used to block analysts (and other read-only roles) from Security/Settings pages
 * before any content is rendered. Delegates to console-access-checks for consistent rules.
 */

import type { QueryClient } from '@tanstack/react-query'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import {
  canShowDatabaseSecuritySettings,
  canShowTableSecuritySettings,
  canShowProjectSettings,
  canShowAuthSecuritySettings,
  canShowProjectOAuth2Server,
  canShowBucketSecuritySettings,
  canShowFunctionSecuritySettings,
  canShowSiteSettingsTab,
  canShowTopicSettingsTab,
  canAccessOrgSettingsOverview,
  canAccessOrgDomains,
  canShowGetStartedSection,
  canCreateDatabase,
} from '@/lib/console-access-checks'
import {
  deriveAccessFromRolesScopes,
  type ConsoleAccess,
} from '@/lib/console-roles'
import { projectQueryOptions } from '@/lib/react-query/hooks/projects'
import { organizationScopesQueryOptions } from '@/lib/react-query/hooks/organizations'

type ProjectData = { teamId?: string }

async function getProjectAccess(
  queryClient: QueryClient,
  projectId: string,
): Promise<ConsoleAccess | null> {
  const features = getActiveProfileFeatures()
  if (!features.orgRoles) return null

  const project = await queryClient.ensureQueryData(
    projectQueryOptions(projectId),
  )
  const teamId = (project as ProjectData)?.teamId
  if (!teamId) return null

  try {
    // Scoped to this project so project-specific roles resolve to their real
    // role; without the id the backend reports them as read-only analyst.
    const scopes = await queryClient.ensureQueryData(
      organizationScopesQueryOptions(teamId, projectId),
    )
    if (!scopes) return null
    return deriveAccessFromRolesScopes(scopes.roles, scopes.scopes)
  } catch {
    return null
  }
}

async function getOrganizationAccess(
  queryClient: QueryClient,
  organizationId: string,
): Promise<ConsoleAccess | null> {
  const features = getActiveProfileFeatures()
  if (!features.orgRoles) return null

  try {
    const scopes = await queryClient.ensureQueryData(
      organizationScopesQueryOptions(organizationId),
    )
    if (!scopes) return null
    return deriveAccessFromRolesScopes(scopes.roles, scopes.scopes)
  } catch {
    return null
  }
}

/** Database-level Security/Settings. */
export async function canAccessDatabaseSecuritySettings(
  queryClient: QueryClient,
  projectId: string,
): Promise<boolean> {
  const access = await getProjectAccess(queryClient, projectId)
  if (!access) return true
  const features = getActiveProfileFeatures()
  return canShowDatabaseSecuritySettings(access, features)
}

/** Native Postgres database Settings tab. */
export async function canAccessPostgresDatabaseSettings(
  queryClient: QueryClient,
  projectId: string,
): Promise<boolean> {
  const access = await getProjectAccess(queryClient, projectId)
  if (!access) return true
  const features = getActiveProfileFeatures()
  return canCreateDatabase(access, features)
}

/** Native MySQL database Settings tab. */
export async function canAccessMysqlDatabaseSettings(
  queryClient: QueryClient,
  projectId: string,
): Promise<boolean> {
  const access = await getProjectAccess(queryClient, projectId)
  if (!access) return true
  const features = getActiveProfileFeatures()
  return canCreateDatabase(access, features)
}

/** Table-level Security/Settings. */
export async function canAccessTableSecuritySettings(
  queryClient: QueryClient,
  projectId: string,
): Promise<boolean> {
  const access = await getProjectAccess(queryClient, projectId)
  if (!access) return true
  const features = getActiveProfileFeatures()
  return canShowTableSecuritySettings(access, features)
}

/** Project-level Settings (overview, webhooks, domains, migrations, smtp). */
export async function canAccessProjectSettings(
  queryClient: QueryClient,
  projectId: string,
): Promise<boolean> {
  const access = await getProjectAccess(queryClient, projectId)
  if (!access) return true
  const features = getActiveProfileFeatures()
  return canShowProjectSettings(access, features)
}

/** Auth Security/Settings/Templates. */
export async function canAccessAuthSecuritySettings(
  queryClient: QueryClient,
  projectId: string,
): Promise<boolean> {
  const access = await getProjectAccess(queryClient, projectId)
  if (!access) return true
  const features = getActiveProfileFeatures()
  return canShowAuthSecuritySettings(access, features)
}

export async function canAccessProjectOAuth2Server(
  queryClient: QueryClient,
  projectId: string,
): Promise<boolean> {
  const access = await getProjectAccess(queryClient, projectId)
  const features = getActiveProfileFeatures()
  if (!features.oauth2Server) return false
  if (!access) return true
  return canShowProjectOAuth2Server(access, features)
}

/** Storage bucket Security/Settings and file Security. */
export async function canAccessBucketSecuritySettings(
  queryClient: QueryClient,
  projectId: string,
): Promise<boolean> {
  const access = await getProjectAccess(queryClient, projectId)
  if (!access) return true
  const features = getActiveProfileFeatures()
  return canShowBucketSecuritySettings(access, features)
}

/** Function Security/Settings/Variables. */
export async function canAccessFunctionSecuritySettings(
  queryClient: QueryClient,
  projectId: string,
): Promise<boolean> {
  const access = await getProjectAccess(queryClient, projectId)
  if (!access) return true
  const features = getActiveProfileFeatures()
  return canShowFunctionSecuritySettings(access, features)
}

/** Site Settings. */
export async function canAccessSiteSettings(
  queryClient: QueryClient,
  projectId: string,
): Promise<boolean> {
  const access = await getProjectAccess(queryClient, projectId)
  if (!access) return true
  const features = getActiveProfileFeatures()
  return canShowSiteSettingsTab(access, features)
}

/** Messaging topic Settings. */
export async function canAccessTopicSettings(
  queryClient: QueryClient,
  projectId: string,
): Promise<boolean> {
  const access = await getProjectAccess(queryClient, projectId)
  if (!access) return true
  const features = getActiveProfileFeatures()
  return canShowTopicSettingsTab(access, features)
}

/** Organization Settings overview (owner-only). */
export async function canAccessOrganizationSettings(
  queryClient: QueryClient,
  organizationId: string,
): Promise<boolean> {
  const access = await getOrganizationAccess(queryClient, organizationId)
  if (!access) return true
  return canAccessOrgSettingsOverview(access)
}

/** Organization Domains (view and manage). */
export async function canAccessOrganizationDomains(
  queryClient: QueryClient,
  organizationId: string,
): Promise<boolean> {
  const access = await getOrganizationAccess(queryClient, organizationId)
  if (!access) return true
  const features = getActiveProfileFeatures()
  return canAccessOrgDomains(access, features)
}

/** Onboarding checklist (same audience as sidebar Get started). */
export async function canAccessProjectOnboarding(
  queryClient: QueryClient,
  projectId: string,
): Promise<boolean> {
  const access = await getProjectAccess(queryClient, projectId)
  if (!access) return true
  const features = getActiveProfileFeatures()
  return canShowGetStartedSection(access, features)
}
