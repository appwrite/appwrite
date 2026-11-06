import { toast } from 'sonner'
import { Switch } from '@/components/ui/switch'
import type { ReviewRepository } from '@/lib/appwrite/reviews'
import {
  useCreateReviewRepository,
  useDeleteReviewRepository,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'

type ReviewToggleProps = {
  projectId: string
  installationId: string
  providerRepositoryId: string
  /** The project's row for the repository, when review is on. */
  repository: ReviewRepository | undefined
  /** Shown in toasts, e.g. `appwrite/appwrite`. */
  name: string
  disabled?: boolean
}

/**
 * Turns code review on or off for one repository. Turning it off keeps past
 * reviews, so it needs no confirmation: the toast offers to undo it instead.
 */
export function ReviewToggle({
  projectId,
  installationId,
  providerRepositoryId,
  repository,
  name,
  disabled,
}: ReviewToggleProps) {
  const t = useT()
  const createMutation = useCreateReviewRepository(projectId)
  const deleteMutation = useDeleteReviewRepository(projectId)
  const pending = createMutation.isPending || deleteMutation.isPending

  // Show where the switch is going while the request runs.
  const checked = createMutation.isPending
    ? true
    : deleteMutation.isPending
      ? false
      : repository !== undefined

  const turnOn = () => {
    createMutation.mutate(
      { installationId, providerRepositoryId },
      {
        onSuccess: () => {
          toast.success(t('Code review turned on'), { description: name })
        },
        onError: (error) => {
          toast.error(
            getErrorMessage(error, t('Failed to turn on code review')),
          )
        },
      },
    )
  }

  const turnOff = (target: ReviewRepository) => {
    deleteMutation.mutate(target.$id, {
      onSuccess: () => {
        toast.success(t('Code review turned off'), {
          description: name,
          action: { label: t('Undo'), onClick: turnOn },
        })
      },
      onError: (error) => {
        toast.error(getErrorMessage(error, t('Failed to turn off code review')))
      },
    })
  }

  return (
    <Switch
      checked={checked}
      disabled={disabled || pending}
      aria-label={`${t('Review pull requests in')} ${name}`}
      onCheckedChange={(next) => {
        if (next) {
          turnOn()
        } else if (repository) {
          turnOff(repository)
        }
      }}
    />
  )
}
