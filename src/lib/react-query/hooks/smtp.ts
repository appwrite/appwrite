/**
 * React Query hooks for SMTP
 *
 * Handles SMTP settings updates.
 */

import { useMutation, useQueryClient } from '@tanstack/react-query'
import { ProjectSMTPSecure } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { Dependencies } from './dependencies'

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
    mutationFn: async (data: {
      enabled: boolean
      senderName?: string
      senderEmail?: string
      replyTo?: string
      host?: string
      port?: number
      username?: string
      password?: string
      secure?: 'tls' | 'ssl' | ''
    }) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }
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
    },
    onSuccess: () => {
      queryClient.invalidateQueries({
        queryKey: ['project', projectId],
      })
      queryClient.invalidateQueries({
        queryKey: Dependencies.PROJECT,
      })
    },
  })
}
