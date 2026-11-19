import type { Models } from '@appwrite.io/console'
import { Link } from '@tanstack/react-router'
import { Loader2, Lock } from 'lucide-react'
import { toast } from 'sonner'
import {
  HeaderAlertBar,
  headerAlertGhostButtonClass,
  headerAlertOutlineButtonClass,
} from '@/components/global/shared/HeaderAlertBar'
import { useUpdateVideoPermissions } from '@/lib/react-query/hooks'
import {
  hasSignedInReaders,
  isVideoPublic,
  withPublicPlayback,
} from '@/lib/videos/public-playback'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'

/**
 * Warns on every video tab that visitors cannot play this video, since the
 * player page and embeds fail for them without a word from the Console.
 */
export function VideoPrivateHeaderAlert({
  projectId,
  video,
  linkToSettings = true,
}: {
  projectId: string
  video: Models.Video
  /** Off on the settings tab, which holds the permissions themselves. */
  linkToSettings?: boolean
}) {
  const t = useT()
  const mutation = useUpdateVideoPermissions(projectId, video)
  if (isVideoPublic(video)) return null

  const makePublic = () =>
    mutation.mutate(withPublicPlayback(video.$permissions, true), {
      onSuccess: () => toast.success(t('Video is now public')),
      onError: (error) =>
        toast.error(getErrorMessage(error) || t('Failed to update video')),
    })

  return (
    <HeaderAlertBar
      variant="warning"
      icon={Lock}
      className="min-h-12 shrink-0 py-2.5"
      action={
        <div className="flex w-full gap-2 sm:w-auto">
          {linkToSettings ? (
            <Link
              to="/projects/$projectId/videos/$videoId/settings"
              params={{ projectId, videoId: video.$id }}
              hash="access"
              className={headerAlertGhostButtonClass('warning')}
            >
              {t('Permissions')}
            </Link>
          ) : null}
          <button
            type="button"
            onClick={makePublic}
            disabled={mutation.isPending}
            className={headerAlertOutlineButtonClass('warning')}
          >
            {mutation.isPending ? (
              <Loader2 className="size-3.5 animate-spin" aria-hidden />
            ) : null}
            {t('Make public')}
          </button>
        </div>
      }
    >
      <p className="min-w-0 leading-snug" data-video-private>
        <span className="font-semibold">{t('This video is private')}</span>
        <span className="font-normal opacity-90">
          {' · '}
          {hasSignedInReaders(video)
            ? t(
                'Only signed-in users with read access can watch it in your app. Visitors get an error on the player page and in embeds.',
              )
            : t(
                'Only project members and API keys can watch it. Visitors get an error on the player page and in embeds.',
              )}
        </span>
      </p>
    </HeaderAlertBar>
  )
}
