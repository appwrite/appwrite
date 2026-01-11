/**
 * React Query hooks for Email Templates
 * 
 * Handles email template fetching, updating, and deletion.
 */

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { sdk } from '@/lib/appwrite/sdk'
import { DEFAULT_STALE_TIME } from './constants'

// ============================================================================
// QUERY FUNCTIONS
// ============================================================================

/**
 * Query function to fetch an email template
 * 
 * @param projectId - The project ID
 * @param type - The email template type
 * @param locale - The email template locale
 * @returns Email template from the API
 */
export async function fetchEmailTemplate(
  projectId: string,
  type: string,
  locale: string,
) {
  if (!projectId) {
    throw new Error('Project ID is required')
  }

  const response = await sdk.forConsole.projects.getEmailTemplate({
    projectId,
    type: type as any,
    locale: locale as any,
  })

  return response
}

// ============================================================================
// HOOKS
// ============================================================================

/**
 * Hook to fetch an email template
 * 
 * @param projectId - The project ID
 * @param type - The email template type
 * @param locale - The email template locale
 */
export function useEmailTemplate(
  projectId: string | null | undefined,
  type: string | null | undefined,
  locale: string | null | undefined,
) {
  const queryClient = useQueryClient()
  
  return useQuery({
    queryKey: ['emailTemplate', projectId, type, locale],
    queryFn: () => fetchEmailTemplate(projectId!, type!, locale!),
    enabled: !!projectId && !!type && !!locale,
    staleTime: DEFAULT_STALE_TIME,
    // Keep previous data visible when switching languages/templates
    // This prevents showing a loader and keeps the form filled with previous data
    placeholderData: (previousData: Models.EmailTemplate | undefined) => {
      // First, try to get cached data for the current query key
      const cachedData = queryClient.getQueryData<Models.EmailTemplate>(['emailTemplate', projectId, type, locale])
      if (cachedData) return cachedData
      
      // If no cached data, keep the previous data visible (from previous locale/template)
      // This allows smooth transitions when switching languages
      return previousData
    },
    // Don't refetch on mount if data is fresh (within staleTime)
    refetchOnMount: false,
  })
}

/**
 * Hook to update an email template
 * 
 * @param projectId - The project ID
 */
export function useUpdateEmailTemplate(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({
      type,
      locale,
      subject,
      message,
      senderName,
      senderEmail,
      replyTo,
    }: {
      type: string
      locale: string
      subject: string
      message: string
      senderName?: string
      senderEmail?: string
      replyTo?: string
    }) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }

      return await sdk.forConsole.projects.updateEmailTemplate({
        projectId,
        type: type as any,
        locale: locale as any,
        subject,
        message,
        senderName,
        senderEmail,
        replyTo,
      })
    },
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({
        queryKey: ['emailTemplate', projectId, variables.type, variables.locale],
      })
    },
  })
}

/**
 * Hook to delete (reset) an email template
 * 
 * @param projectId - The project ID
 */
export function useDeleteEmailTemplate(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({
      type,
      locale,
    }: {
      type: string
      locale: string
    }) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }

      return await sdk.forConsole.projects.deleteEmailTemplate({
        projectId,
        type: type as any,
        locale: locale as any,
      })
    },
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({
        queryKey: ['emailTemplate', projectId, variables.type, variables.locale],
      })
    },
  })
}

