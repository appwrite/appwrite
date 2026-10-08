/**
 * React Query hooks for Email Templates
 *
 * Handles email template fetching, updating, and resetting to defaults.
 */

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  ProjectEmailTemplateId,
  ProjectEmailTemplateLocale,
} from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { DEFAULT_STALE_TIME } from './constants'

// ============================================================================
// QUERY FUNCTIONS
// ============================================================================

/**
 * Query function to fetch an email template
 */
export async function fetchEmailTemplate(
  projectId: string,
  type: string,
  locale: string,
) {
  if (!projectId) {
    throw new Error('Project ID is required')
  }

  return await sdk.forProject(projectId).project.getEmailTemplate({
    templateId: type as ProjectEmailTemplateId,
    locale: locale as ProjectEmailTemplateLocale,
  })
}

/**
 * Fetch the built-in default email template (ignores project overrides).
 */
export async function fetchDefaultEmailTemplate(
  type: string,
  locale: string,
) {
  return await sdk.forConsole.console.getEmailTemplate({
    templateId: type as ProjectEmailTemplateId,
    locale: locale as ProjectEmailTemplateLocale,
  })
}

/**
 * Reset a project email template to the Appwrite default by saving default content.
 */
export async function resetProjectEmailTemplate(
  projectId: string,
  type: string,
  locale: string,
) {
  const defaultTemplate = await fetchDefaultEmailTemplate(type, locale)

  return await sdk.forProject(projectId).project.updateEmailTemplate({
    templateId: type as ProjectEmailTemplateId,
    locale: locale as ProjectEmailTemplateLocale,
    subject: defaultTemplate.subject ?? '',
    message: defaultTemplate.message ?? '',
    senderName: defaultTemplate.senderName ?? '',
    senderEmail: defaultTemplate.senderEmail ?? '',
    replyToEmail: defaultTemplate.replyToEmail ?? '',
    replyToName: defaultTemplate.replyToName ?? '',
  })
}

// ============================================================================
// HOOKS
// ============================================================================

export function useEmailTemplate(
  projectId: string | null | undefined,
  type: string | null | undefined,
  locale: string | null | undefined,
) {
  useQueryClient()

  return useQuery({
    queryKey: ['emailTemplate', projectId, type, locale],
    queryFn: () => fetchEmailTemplate(projectId!, type!, locale!),
    enabled: !!projectId && !!type && !!locale,
    staleTime: DEFAULT_STALE_TIME,
    refetchOnMount: false,
  })
}

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
      replyToEmail,
      replyToName,
    }: {
      type: string
      locale: string
      subject: string
      message: string
      senderName?: string
      senderEmail?: string
      replyToEmail?: string
      replyToName?: string
    }) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }

      return await sdk.forProject(projectId).project.updateEmailTemplate({
        templateId: type as ProjectEmailTemplateId,
        locale: locale as ProjectEmailTemplateLocale,
        subject,
        message,
        senderName,
        senderEmail,
        replyToEmail,
        replyToName,
      })
    },
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({
        queryKey: [
          'emailTemplate',
          projectId,
          variables.type,
          variables.locale,
        ],
      })
    },
  })
}

export function useResetEmailTemplate(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: async ({ type, locale }: { type: string; locale: string }) => {
      if (!projectId) {
        throw new Error('Project ID is required')
      }

      return await resetProjectEmailTemplate(projectId, type, locale)
    },
    onSuccess: (_, variables) => {
      queryClient.invalidateQueries({
        queryKey: [
          'emailTemplate',
          projectId,
          variables.type,
          variables.locale,
        ],
      })
    },
  })
}

/** @deprecated Use useResetEmailTemplate */
export const useDeleteEmailTemplate = useResetEmailTemplate
