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
      await updateMutation.mutateAsync({
        appId: app.$id,
        name: app.name,
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
