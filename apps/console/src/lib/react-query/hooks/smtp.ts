/**
 * React Query hooks for SMTP
 *
 * Handles SMTP settings updates and reading custom SMTP status via project-scoped APIs.
 */

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { queryOptions } from '@tanstack/react-query'
import { ProjectSMTPSecure, type Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { fetchProjectById } from '@/lib/project-settings'
import { Dependencies } from './dependencies'
import { LONG_STALE_TIME } from './constants'

export function isCustomSmtpEnabled(
  project: Models.Project | null | undefined,
): boolean {
  return Boolean(project?.smtpEnabled)
}

/**
 * Fetch custom SMTP status via project-scoped `project.get()`.
 */
export async function fetchProjectSmtpStatus(projectId: string): Promise<boolean> {
  const project = await fetchProjectById(projectId)
  return isCustomSmtpEnabled(project)
}

/**
 * Query options for custom SMTP enabled state (project-scoped `project.get()`).
 */
export function projectSmtpStatusQueryOptions(
  projectId: string | null | undefined,
) {
  return queryOptions({
    queryKey: ['project', projectId, 'smtp-status'],
    queryFn: () => fetchProjectSmtpStatus(projectId!),
    enabled: !!projectId,
    staleTime: LONG_STALE_TIME,
    retry: false,
    refetchOnMount: true,
    refetchOnWindowFocus: false,
    refetchOnReconnect: false,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function useProjectSmtpEnabled(projectId: string | undefined) {
  const { data: isSmtpEnabled = false, isLoading } = useQuery(
    projectSmtpStatusQueryOptions(projectId),
  )

  return { isSmtpEnabled, isLoading }
}

export type SmtpUpdateData = {
  enabled: boolean
  senderName?: string
  senderEmail?: string
  replyTo?: string
  host?: string
  port?: number
  username?: string
  password?: string
  secure?: 'tls' | 'ssl' | ''
}

export async function updateProjectSmtp(
  projectId: string,
  data: SmtpUpdateData,
) {
  return await sdk.forProject(projectId).project.updateSMTP({
    enabled: data.enabled,
    senderName: data.enabled ? data.senderName : undefined,
    senderEmail: data.enabled ? data.senderEmail : undefined,
    replyToEmail: data.enabled ? data.replyTo : undefined,
    host: data.enabled ? data.host : undefined,
    port: data.enabled ? data.port : undefined,
    username: data.enabled ? data.username : undefined,
    password: data.enabled ? data.password : undefined,
    secure: data.enabled
      ? data.secure === 'tls'
        ? ProjectSMTPSecure.Tls
        : data.secure === 'ssl'
          ? ProjectSMTPSecure.Ssl
          : undefined
      : undefined,
  })
}

export async function sendProjectSMTPTest(
  projectId: string,
  emails: string[],
  smtp: SmtpUpdateData,
) {
  await updateProjectSmtp(projectId, smtp)
  return await sdk.forProject(projectId).project.createSMTPTest({ emails })
}

function invalidateProjectSmtpQueries(
  queryClient: ReturnType<typeof useQueryClient>,
  projectId: string | null | undefined,
) {
  queryClient.invalidateQueries({
    queryKey: ['project', projectId],
  })
  queryClient.invalidateQueries({
    queryKey: ['project', projectId, 'smtp-status'],
  })
  queryClient.invalidateQueries({
    queryKey: Dependencies.PROJECT,
  })
}

// ============================================================================
// HOOKS
// ============================================================================

/**
 * Hook to update SMTP settings
 *
 * @param projectId - The project ID
 */
export function useUpdateSMTP(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async (data: SmtpUpdateData) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      return await updateProjectSmtp(projectId, data)
    },
    onSuccess: () => {
      invalidateProjectSmtpQueries(queryClient, projectId)
    },
  })
}

/**
 * Hook to send a test email using the provided SMTP settings (saved before send).
 *
 * @param projectId - The project ID
 */
export function useTestSMTP(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({
      emails,
      smtp,
    }: {
      emails: string[]
      smtp: SmtpUpdateData
    }) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
      return await sendProjectSMTPTest(projectId, emails, smtp)
    },
    onSuccess: () => {
      invalidateProjectSmtpQueries(queryClient, projectId)
    },
  })
}
