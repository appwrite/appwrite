import { toast } from 'sonner'
import { Switch } from '@/components/ui/switch'
import type { ReviewInstallation, ReviewStrength } from '@/lib/appwrite/reviews'
import { useUpdateReviewInstallation } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { StrengthPicker } from './StrengthSelect'

type NewRepositoriesProps = {
  projectId: string
  installation: ReviewInstallation
  canWrite: boolean
}

/**
 * Turns code review on for repositories added to a GitHub installation later,
 * whether picked in the app's settings or created in an account the app has
 * in full. Repositories already in the installation keep their own switch.
 */
export function NewRepositories({
  projectId,
  installation,
  canWrite,
}: NewRepositoriesProps) {
  const t = useT()
  const mutation = useUpdateReviewInstallation(projectId)

  // Show where the settings are going while the request runs.
  const enabled = mutation.isPending
    ? mutation.variables.newRepositories
    : (installation.reviewNewRepositories ?? false)
  const strength: ReviewStrength = mutation.isPending
    ? (mutation.variables.strength ?? 'normal')
    : (installation.reviewStrength ?? 'normal')

  const update = (newRepositories: boolean, next: ReviewStrength) => {
    mutation.mutate(
      {
        installationId: installation.$id,
        newRepositories,
        strength: next,
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
      <div className="flex shrink-0 items-center gap-3">
        {enabled && (
          <StrengthPicker
            value={strength}
            disabled={!canWrite || mutation.isPending}
            label={t('Review strength for new repositories')}
            onChange={(next) => update(true, next)}
          />
        )}
        <Switch
          checked={enabled}
          disabled={!canWrite || mutation.isPending}
          aria-label={t('Review new repositories')}
          onCheckedChange={(next) => update(next, strength)}
        />
      </div>
    </div>
  )
}
