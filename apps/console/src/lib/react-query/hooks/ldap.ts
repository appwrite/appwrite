/**
 * React Query hooks for LDAP
 *
 * Handles the project LDAP configuration and connection tests via project-scoped APIs.
 */

import { useMutation, useQueryClient } from '@tanstack/react-query'
import {
  ProjectAuthMethodId,
  ProjectLDAPEncryption,
  type Models,
} from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'

export type LdapEncryption = 'none' | 'ldaps' | 'starttls'

export const LDAP_HOSTS_MAX = 10
export const LDAP_PORT_MIN = 1
export const LDAP_PORT_MAX = 65535
export const LDAP_TIMEOUT_MIN = 1
export const LDAP_TIMEOUT_MAX = 30

/** The port a sign-in uses when none is chosen: 636 for LDAPS, 389 otherwise. */
export function ldapDefaultPort(encryption: LdapEncryption): number {
  return encryption === 'ldaps' ? 636 : 389
}

export function ldapEncryptionFromProject(
  encryption: string | undefined,
): LdapEncryption {
  if (encryption === 'none' || encryption === 'starttls') return encryption
  return 'ldaps'
}

/** LDAP sign-in is an auth method; the rest of the configuration lives on the project. */
export function isLdapEnabled(
  project: Models.Project | null | undefined,
): boolean {
  return Boolean(
    project?.authMethods?.some(
      (method) => method.$id === ProjectAuthMethodId.Ldap && method.enabled,
    ),
  )
}

/** Fields of `project.updateLDAP`. A field left out keeps its stored value. */
export type LdapUpdateData = {
  hosts?: string[]
  port?: number
  encryption?: LdapEncryption
  tls?: boolean
  caCertificate?: string
  timeout?: number
  baseDn?: string
  bindDn?: string
  /** Write-only: sent only when the user typed a new one. */
  bindPassword?: string
  usernameAttribute?: string
  userFilter?: string
  idAttribute?: string
  emailAttribute?: string
  nameAttribute?: string
  phoneAttribute?: string
  requiredGroupDn?: string
  enabled?: boolean
}

export type LdapTestData = {
  username?: string
  password?: string
}

const LDAP_ENCRYPTIONS: Record<LdapEncryption, ProjectLDAPEncryption> = {
  none: ProjectLDAPEncryption.None,
  ldaps: ProjectLDAPEncryption.Ldaps,
  starttls: ProjectLDAPEncryption.Starttls,
}

export async function updateProjectLdap(
  projectId: string,
  data: LdapUpdateData,
): Promise<Models.Project> {
  return await sdk.forProject(projectId).project.updateLDAP({
    ...data,
    encryption: data.encryption ? LDAP_ENCRYPTIONS[data.encryption] : undefined,
  })
}

/**
 * Test the saved LDAP configuration. Resolves on success; the error message
 * names the step that failed.
 */
export async function testProjectLdap(projectId: string, data: LdapTestData) {
  return await sdk.forProject(projectId).project.createLDAPTest({
    username: data.username || undefined,
    password: data.password || undefined,
  })
}

// ============================================================================
// HOOKS
// ============================================================================

/**
 * Hook to update the LDAP configuration
 *
 * @param projectId - The project ID
 */
export function useUpdateLDAP(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (data: LdapUpdateData) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      return await updateProjectLdap(projectId, data)
    },
    onSuccess: async () => {
      // The settings form reads the saved values from the project query.
      await queryClient.invalidateQueries({
        queryKey: ['project', projectId],
      })
    },
  })
}

/**
 * Hook to test the saved LDAP configuration against the directory
 *
 * @param projectId - The project ID
 */
export function useTestLDAP(projectId: string | null | undefined) {
  return useMutation({
    mutationFn: async (data: LdapTestData) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      return await testProjectLdap(projectId, data)
    },
  })
}
