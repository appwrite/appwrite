import { useState } from 'react'
import { AppwriteException } from '@appwrite.io/console'
import { toast } from 'sonner'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  useCreateReviewRepository,
  useInstallation,
  useReviewedRepository,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { isReviewVcsProvider } from '@/lib/vcs/providers'
import { useReviewsAccess } from './useReviewsAccess'

/**
 * The "Review pull requests" choice of the function and site creation
 * wizards. It is on by default, and offered only once a repository is
 * connected and the viewer may turn code review on.
 */
export function useCreateReview(
  projectId: string | null | undefined,
  installationId: string | null | undefined,
  providerRepositoryId: string | null | undefined,
) {
  const t = useT()
  const { canWrite } = useReviewsAccess(projectId)
  const { data: installation } = useInstallation(
    canWrite ? projectId : null,
    installationId,
  )
  // Code review runs on some Git providers only.
  const reviewable = isReviewVcsProvider(installation?.provider)
  const { repository, isLoading } = useReviewedRepository(
    reviewable ? projectId : null,
    providerRepositoryId,
  )
  const createMutation = useCreateReviewRepository(projectId)
  const [checked, setChecked] = useState(true)

  const shown = canWrite && reviewable && !!providerRepositoryId
  // The project may review the repository already, for another resource.
  const alreadyOn = repository !== undefined

  /**
   * Turns code review on for the repository when chosen. Runs once the
   * resource exists, so a failure warns instead of throwing.
   */
  const apply = async () => {
    if (!shown || !checked || alreadyOn) return
    try {
      await createMutation.mutateAsync({
        installationId: installationId!,
        providerRepositoryId: providerRepositoryId!,
      })
    } catch (error) {
      if (error instanceof AppwriteException && error.code === 409) return
      toast.warning(t('Code review was not turned on'), {
        description: t('Turn it on later from Settings > Git.'),
      })
    }
  }

  return {
    shown,
    checked: checked || alreadyOn,
    alreadyOn,
    loading: isLoading,
    setChecked,
    apply,
  }
}

export type CreateReview = ReturnType<typeof useCreateReview>

/**
 * The wizard row for `useCreateReview`. Renders nothing while code review is
 * not offered for the connected repository.
 */
export function CreateReviewSwitch({ review }: { review: CreateReview }) {
  const t = useT()
  const { isCloud } = useConsoleProfile()

  if (!review.shown) return null

  return (
    <div className="flex items-center justify-between gap-4">
      <div className="min-w-0">
        <Label htmlFor="review-pull-requests" className="text-[13px]">
          {t('Review pull requests')}
        </Label>
        <p className="text-[11px] text-muted-foreground">
          {review.alreadyOn
            ? t('Code review is already on for this repository.')
            : t(
                'Appwrite reviews each pull request when it is opened or gets new commits, and comments on it.',
              )}
        </p>
        {isCloud && !review.alreadyOn && (
          <p className="text-[11px] text-muted-foreground">
            {t("Reviews use your organization's review credits.")}
          </p>
        )}
      </div>
      <Switch
        id="review-pull-requests"
        checked={review.checked}
        onCheckedChange={review.setChecked}
        disabled={review.alreadyOn || review.loading}
        className="shrink-0"
      />
    </div>
  )
}
