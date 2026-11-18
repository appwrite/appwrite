import type { Models } from '@appwrite.io/console'
import { Link } from '@tanstack/react-router'
import { Lock } from 'lucide-react'
import {
  HeaderAlertBar,
  headerAlertOutlineButtonClass,
} from '@/components/global/shared/HeaderAlertBar'
import { isVideoPublic } from '@/lib/videos/public-playback'
import { useT } from '@/lib/i18n/translate'

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
  /** Off on the settings tab, which holds the switch itself. */
  linkToSettings?: boolean
}) {
  const t = useT()
  if (isVideoPublic(video)) return null

  return (
    <HeaderAlertBar
      variant="warning"
      icon={Lock}
      className="min-h-12 shrink-0 py-2.5"
      action={
        linkToSettings ? (
          <Link
            to="/projects/$projectId/videos/$videoId/settings"
            params={{ projectId, videoId: video.$id }}
            hash="access"
            className={headerAlertOutlineButtonClass('warning')}
          >
            {t('Make public')}
          </Link>
        ) : undefined
      }
    >
      <p className="min-w-0 leading-snug" data-video-private>
        <span className="font-semibold">{t('This video is private')}</span>
        <span className="font-normal opacity-90">
          {' · '}
          {t(
            'Only project members can watch it. Visitors get an error on the player page and in embeds.',
          )}
        </span>
      </p>
    </HeaderAlertBar>
  )
}
