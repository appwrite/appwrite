import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from '@tanstack/react-router'
import type { Models } from '@appwrite.io/console'
import { Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { ConfirmNameDialog } from '@/components/global/shared/ConfirmNameDialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  useDeleteVideo,
  useProjectVideo,
  useUpdateVideo,
} from '@/lib/react-query/hooks'
import { VideoPage, VideoSectionCard } from '../../_components/VideoPage'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'

type ViewProps = {
  initialData?: { video: Models.Video }
}

export function View({ initialData }: ViewProps = {}) {
  const t = useT()
  const { projectId, videoId } = useParams({ strict: false }) as {
    projectId: string
    videoId: string
  }
  const { data: videoFromHook } = useProjectVideo(projectId, videoId)
  const video = videoFromHook ?? initialData?.video

  if (!video) return null

  return (
    <VideoPage title={t('Settings')}>
      <NameCard projectId={projectId} video={video} />
      <SourceCard projectId={projectId} video={video} />
      <DeleteCard projectId={projectId} video={video} />
    </VideoPage>
  )
}

function NameCard({
  projectId,
  video,
}: {
  projectId: string
  video: Models.Video
}) {
  const t = useT()
  const [name, setName] = useState(video.name)
  const updateMutation = useUpdateVideo(projectId, video.$id)

  useEffect(() => {
    setName(video.name)
  }, [video.name])

  const trimmed = name.trim()
  const changed = trimmed.length > 0 && trimmed !== video.name

  const handleSubmit = (event: React.FormEvent) => {
    event.preventDefault()
    if (!changed) return
    updateMutation.mutate(trimmed, {
      onSuccess: () => toast.success(t('Video name updated')),
      onError: (error) =>
        toast.error(getErrorMessage(error) || t('Failed to update video')),
    })
  }

  return (
    <form
      onSubmit={handleSubmit}
      className="rounded-xl border border-border bg-card/50 overflow-hidden"
    >
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Name')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t('Shown in the Console and returned by the API.')}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <Input
          value={name}
          maxLength={128}
          onChange={(e) => setName(e.target.value)}
          className="h-9 max-w-md text-[13px]"
          aria-label={t('Name')}
        />
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          type="submit"
          size="sm"
          className="h-9 text-[13px]"
          disabled={!changed || updateMutation.isPending}
        >
          {t('Update')}
        </Button>
      </div>
    </form>
  )
}

function SourceCard({
  projectId,
  video,
}: {
  projectId: string
  video: Models.Video
}) {
  const t = useT()
  return (
    <VideoSectionCard
      title={t('Source')}
      term="source"
      description={t(
        'The Storage file this video was created from. Deleting the video keeps the file.',
      )}
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="min-w-0 space-y-1">
          <p className="text-[12px] text-muted-foreground">{t('Bucket')}</p>
          <Link
            to="/projects/$projectId/storage/$bucketId"
            params={{ projectId, bucketId: video.bucketId }}
            className="block truncate font-mono text-[13px] text-foreground hover:underline"
          >
            {video.bucketId}
          </Link>
        </div>
        <div className="min-w-0 space-y-1">
          <p className="text-[12px] text-muted-foreground">{t('File')}</p>
          <Link
            to="/projects/$projectId/storage/$bucketId"
            params={{ projectId, bucketId: video.bucketId }}
            search={{ file: video.fileId }}
            className="block truncate font-mono text-[13px] text-foreground hover:underline"
          >
            {video.fileId}
          </Link>
        </div>
      </div>
    </VideoSectionCard>
  )
}

function DeleteCard({
  projectId,
  video,
}: {
  projectId: string
  video: Models.Video
}) {
  const t = useT()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const deleteMutation = useDeleteVideo(projectId)

  const handleDelete = () => {
    deleteMutation.mutate(video.$id, {
      onSuccess: async () => {
        toast.success(t('Video deleted'))
        setOpen(false)
        await navigate({
          to: '/projects/$projectId/videos',
          params: { projectId },
        })
      },
      onError: (error) =>
        toast.error(getErrorMessage(error) || t('Failed to delete video')),
    })
  }

  return (
    <div className="rounded-xl border border-destructive/50 bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Delete video')}
        </h3>
      </div>
      <div className="border-t border-destructive/20" />
      <div className="px-6 py-4">
        <p className="text-[13px] text-muted-foreground">
          {t(
            'Permanently delete this video with its renditions, subtitles, and previews. The source file in Storage is kept.',
          )}
        </p>
        <div className="mt-4 flex items-center gap-3">
          <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
            <Trash2 className="h-5 w-5 text-muted-foreground" />
          </div>
          <div className="min-w-0 flex-1">
            <p className="truncate text-[14px] font-medium text-foreground">
              {video.name || t('Untitled video')}
            </p>
            <p className="text-[12px] text-muted-foreground">{video.$id}</p>
          </div>
        </div>
      </div>
      <div className="px-6 py-4 border-t border-destructive/20 bg-destructive/5">
        <Button
          variant="destructive"
          size="sm"
          className="h-9 text-[13px]"
          disabled={deleteMutation.isPending}
          onClick={() => setOpen(true)}
        >
          {t('Delete video')}
        </Button>
      </div>
      <ConfirmNameDialog
        open={open}
        onOpenChange={setOpen}
        title={t('Delete video')}
        description={
          <>
            {t('Are you sure you want to delete')}{' '}
            <span className="font-medium text-foreground">
              {video.name || t('this video')}
            </span>
            ? {t('This action cannot be undone.')}
          </>
        }
        confirmValue={video.name?.trim() || video.$id}
        confirmPlaceholder={t('Enter video name')}
        onConfirm={handleDelete}
        isConfirming={deleteMutation.isPending}
      />
    </div>
  )
}
