import { useMemo, useState } from 'react'
import { Link } from '@tanstack/react-router'
import { useQuery } from '@tanstack/react-query'
import { Query, type Models } from '@appwrite.io/console'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  canCreateVideo,
  canSeeProjectNavItem,
} from '@/lib/console-access-checks'
import type { ConsoleAccess } from '@/lib/console-roles'
import { videosQueryOptions } from '@/lib/react-query/hooks/videos'
import { VIDEOS_PRODUCT_ICON } from '@/lib/videos/product-icon'
import { useT } from '@/lib/i18n/translate'
import { CreateVideo } from '../../videos/_components/CreateVideo'

type FileVideoActionProps = {
  projectId: string
  bucketId: string
  file: Models.File
  access: ConsoleAccess
}

/**
 * Hands a video or audio file over to Videos: opens the existing video for the
 * file, or creates one with the file preselected.
 */
export function FileVideoAction({
  projectId,
  bucketId,
  file,
  access,
}: FileVideoActionProps) {
  const t = useT()
  const { features } = useConsoleProfile()
  const [createOpen, setCreateOpen] = useState(false)

  const isMedia = /^(video|audio)\//.test(file.mimeType.toLowerCase())
  const enabled =
    isMedia &&
    Boolean(features.videos) &&
    canSeeProjectNavItem(access, features, 'videos')

  const { data: existing } = useQuery({
    ...videosQueryOptions(projectId, 0, 1, undefined, [
      Query.equal('bucketId', [bucketId]),
      Query.equal('fileId', [file.$id]),
    ]),
    enabled,
  })
  const existingVideo = existing?.videos[0]

  const initialSource = useMemo(
    () => ({ bucketId, fileId: file.$id }),
    [bucketId, file.$id],
  )

  if (!enabled) return null

  const Icon = VIDEOS_PRODUCT_ICON

  if (existingVideo) {
    return (
      <Button
        asChild
        variant="outline"
        size="sm"
        className="h-8 w-fit gap-1.5 text-[13px]"
      >
        <Link
          to="/projects/$projectId/videos/$videoId"
          params={{ projectId, videoId: existingVideo.$id }}
        >
          <Icon className="h-3.5 w-3.5 shrink-0" />
          {t('Open in Videos')}
        </Link>
      </Button>
    )
  }

  const noCreatePermission = !canCreateVideo(access, features)
  const button = (
    <Button
      type="button"
      variant="outline"
      size="sm"
      className="h-8 w-fit gap-1.5 text-[13px]"
      disabled={noCreatePermission}
      onClick={() => setCreateOpen(true)}
    >
      <Icon className="h-3.5 w-3.5 shrink-0" />
      {t('Create video')}
    </Button>
  )

  return (
    <>
      {noCreatePermission ? (
        <Tooltip>
          <TooltipTrigger asChild>
            <span className="w-fit">{button}</span>
          </TooltipTrigger>
          <TooltipContent side="bottom" className="text-xs">
            {t("You don't have permission to create videos.")}
          </TooltipContent>
        </Tooltip>
      ) : (
        button
      )}
      <CreateVideo
        open={createOpen}
        onOpenChange={setCreateOpen}
        projectId={projectId}
        initialSource={initialSource}
      />
    </>
  )
}
