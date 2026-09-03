import type { Models } from '@appwrite.io/console'
import { toast } from 'sonner'
import {
  useUpdateOrganizationApp,
  type UpdateOrganizationAppInput,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'

export function trimOrEmpty(value: string): string {
  return value.trim()
}

export function nonEmptyList(values: string[]): string[] {
  return values.map((v) => v.trim()).filter(Boolean)
}

export function listsEqual(a: string[], b: string[]): boolean {
  return a.length === b.length && a.every((value, index) => value === b[index])
}

export function useOrgAppUpdate(
  organizationId: string,
  app: Models.App,
) {
  const updateMutation = useUpdateOrganizationApp(organizationId)

  const submit = async (
    fields: Partial<UpdateOrganizationAppInput>,
    options?: { successMessage?: string },
  ) => {
    try {
      // The update endpoint replaces the whole document, so always send the
      // app's current state as the base — a partial submit (e.g. toggling
      // enabled) must not wipe the other fields.
      await updateMutation.mutateAsync({
        appId: app.$id,
        name: app.name,
        enabled: app.enabled,
        description: app.description ?? '',
        tagline: app.tagline ?? '',
        tags: app.tags ?? [],
        clientUri: app.clientUri ?? '',
        logoUri: app.logoUri ?? '',
        privacyPolicyUrl: app.privacyPolicyUrl ?? '',
        termsUrl: app.termsUrl ?? '',
        contacts: app.contacts ?? [],
        images: app.images ?? [],
        supportUrl: app.supportUrl ?? '',
        dataDeletionUrl: app.dataDeletionUrl ?? '',
        redirectUris: app.redirectUris ?? [],
        postLogoutRedirectUris: app.postLogoutRedirectUris ?? [],
        type: app.type || 'confidential',
        deviceFlow: app.deviceFlow ?? false,
        ...fields,
      })
      toast.success(options?.successMessage ?? 'App updated')
    } catch (error) {
      toast.error(getErrorMessage(error, 'Failed to update app'))
      throw error
    }
  }

  return {
    submit,
    isUpdating: updateMutation.isPending,
  }
}
