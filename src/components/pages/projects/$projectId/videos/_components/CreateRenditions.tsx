import { useEffect, useMemo, useState } from 'react'
import { VideoOutput } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Label } from '@/components/ui/label'
import {
  useCreateVideoRenditions,
  useVideoProfiles,
  useVideoRenditions,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  getDefaultProfileSelection,
  OutputPicker,
  ProfilePicker,
  sortProfilesByPixels,
} from './RenditionOptions'
import { VideoTermHint } from './VideoTermHint'

type CreateRenditionsProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  video: Models.Video
}

export function CreateRenditions({
  open,
  onOpenChange,
  projectId,
  video,
}: CreateRenditionsProps) {
  const t = useT()
  const [output, setOutput] = useState<VideoOutput>(VideoOutput.Hls)
  const [selected, setSelected] = useState<Set<string>>(new Set())
  const { data: profilesData, isLoading: profilesLoading } =
    useVideoProfiles(projectId)
  const { data: renditionsData } = useVideoRenditions(projectId, video.$id)
  const createMutation = useCreateVideoRenditions(projectId, video.$id)

  const profiles = useMemo(
    () => sortProfilesByPixels(profilesData?.profiles ?? []),
    [profilesData?.profiles],
  )

  const existingProfileIds = useMemo(
    () =>
      new Set(
        (renditionsData?.renditions ?? [])
          .filter((rendition) => rendition.output === output)
          .map((rendition) => rendition.profileId),
      ),
    [renditionsData?.renditions, output],
  )

  const source = { width: video.width, height: video.height }

  useEffect(() => {
    if (!open) return
    setSelected(getDefaultProfileSelection(profiles, source, existingProfileIds))
    // Reset the selection when the dialog opens or the output changes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, output, profiles.length])

  const handleSubmit = () => {
    if (selected.size === 0) return
    createMutation.mutate(
      { profileIds: Array.from(selected), output },
      {
        onSuccess: ({ created, failed }) => {
          if (failed > 0) {
            toast.warning(
              `${created} ${t('renditions queued')}, ${failed} ${t('failed')}`,
            )
          } else {
            toast.success(
              created === 1
                ? t('Rendition queued')
                : `${created} ${t('renditions queued')}`,
            )
          }
          onOpenChange(false)
        },
        onError: (error) => {
          toast.error(
            getErrorMessage(error) || t('Failed to create renditions'),
          )
        },
      },
    )
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-xl p-0 max-h-[90dvh] overflow-y-auto">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Create renditions')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Encode the source into one rendition per profile. Progress updates live while the worker encodes.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="space-y-6 px-6 pb-6 pt-5">
          <div className="space-y-2.5">
            <Label className="flex items-center gap-1 text-[13px]">
              {t('Output')}
              <VideoTermHint term="output" />
            </Label>
            <OutputPicker value={output} onValueChange={setOutput} />
          </div>
          <ProfilePicker
            projectId={projectId}
            profiles={profiles}
            loading={profilesLoading}
            selected={selected}
            onSelectedChange={setSelected}
            existingProfileIds={existingProfileIds}
            source={source}
          />
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:items-center">
          <span className="text-[12px] text-muted-foreground sm:me-auto">
            {selected.size} {t('selected')}
          </span>
          <Button
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={createMutation.isPending}
          >
            {t('Cancel')}
          </Button>
          <Button
            onClick={handleSubmit}
            disabled={selected.size === 0 || createMutation.isPending}
          >
            {t('Create')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
