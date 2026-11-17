import { toast } from 'sonner'
import { Switch } from '@/components/ui/switch'
import type { ReviewInstallation } from '@/lib/appwrite/reviews'
import { useUpdateReviewInstallation } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'

type NewRepositoriesProps = {
  projectId: string
  installation: ReviewInstallation
  canWrite: boolean
}

/**
 * Turns code review on for repositories added to a GitHub installation later,
 * whether picked in the app's settings or created in an account the app has
 * in full. They start at normal strength, changed per repository like any
 * other. Repositories already in the installation keep their own switch.
 */
export function NewRepositories({
  projectId,
  installation,
  canWrite,
}: NewRepositoriesProps) {
  const t = useT()
  const mutation = useUpdateReviewInstallation(projectId)

  // Show where the setting is going while the request runs.
  const enabled = mutation.isPending
    ? mutation.variables.newRepositories
    : (installation.reviewNewRepositories ?? false)

  const update = (newRepositories: boolean) => {
    mutation.mutate(
      {
        installationId: installation.$id,
        newRepositories,
      },
      {
        onSuccess: () => {
          toast.success(
            newRepositories
              ? t('New repositories will be reviewed')
              : t('New repositories will not be reviewed'),
            { description: installation.organization },
          )
        },
        onError: (error) => {
          toast.error(
            getErrorMessage(error, t('Failed to update new repositories')),
          )
        },
      },
    )
  }

  return (
    <div className="flex flex-col gap-3 rounded-lg border border-border bg-card px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
      <div className="min-w-0">
        <p className="text-[13px] font-medium text-foreground">
          {t('Review new repositories')}
        </p>
        <p className="text-[12px] text-muted-foreground">
          {t(
            'Turn on code review for repositories added to {organization} on GitHub later.',
          ).replace('{organization}', installation.organization)}
        </p>
      </div>
      <Switch
        className="shrink-0"
        checked={enabled}
        disabled={!canWrite || mutation.isPending}
        aria-label={t('Review new repositories')}
        onCheckedChange={update}
      />
    </div>
  )
}
