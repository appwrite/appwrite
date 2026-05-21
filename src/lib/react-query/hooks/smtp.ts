/**
 * React Query hooks for SMTP
 *
 * Handles SMTP settings updates.
 */

import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ProjectSMTPSecure } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { Dependencies } from './dependencies'

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
