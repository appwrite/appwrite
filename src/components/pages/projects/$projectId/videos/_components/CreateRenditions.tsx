import { useEffect, useMemo, useState } from 'react'
import { Link } from '@tanstack/react-router'
import { VideoOutput } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { toast } from 'sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Label } from '@/components/ui/label'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import {
  useCreateVideoRenditions,
  useVideoProfiles,
  useVideoRenditions,
  VIDEO_OUTPUTS,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { formatBitrate, formatResolution } from '@/lib/utils/video-format'
import { VideoFormatLabel } from './VideoOutputBadge'
import { VideoTermHint } from './VideoTermHint'

const OUTPUT_LABELS: Record<VideoOutput, string> = {
  [VideoOutput.Hls]: 'HLS',
  [VideoOutput.Dash]: 'DASH',
  [VideoOutput.Cmaf]: 'CMAF',
}

const OUTPUT_DESCRIPTIONS: Record<VideoOutput, string> = {
  [VideoOutput.Hls]: 'MPEG-TS segments. Plays natively on Safari and iOS.',
  [VideoOutput.Dash]: 'MPEG-DASH with fragmented MP4 segments.',
  [VideoOutput.Cmaf]: 'Fragmented MP4 served as both HLS and DASH.',
}

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
    () =>
      [...(profilesData?.profiles ?? [])].sort(
        (a, b) => a.width * a.height - b.width * b.height,
      ),
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

  const isUpscale = (profile: Models.VideoProfile) =>
    video.height > 0 && profile.height > video.height

  useEffect(() => {
    if (!open) return
    setSelected(
      new Set(
        profiles
          .filter(
            (profile) =>
              !existingProfileIds.has(profile.$id) && !isUpscale(profile),
          )
          .map((profile) => profile.$id),
      ),
    )
    // Reset the selection when the dialog opens or the output changes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, output, profiles.length])

  const toggle = (profileId: string) => {
    const next = new Set(selected)
    if (next.has(profileId)) next.delete(profileId)
    else next.add(profileId)
    setSelected(next)
  }

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
      <DialogContent className="sm:max-w-lg p-0 max-h-[90dvh] overflow-y-auto">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Create renditions')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Encode the source into one rendition per profile. Progress updates live while the worker encodes.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="space-y-5 px-6 pb-4 pt-4">
          <div className="space-y-2">
            <Label className="flex items-center gap-1 text-[13px]">
              {t('Output')}
              <VideoTermHint term="output" />
            </Label>
            <RadioGroup
              value={output}
              onValueChange={(value) => setOutput(value as VideoOutput)}
              className="grid gap-2 sm:grid-cols-3"
            >
              {VIDEO_OUTPUTS.map((value) => (
                <Label
                  key={value}
                  htmlFor={`video-output-${value}`}
                  className={cn(
                    'flex cursor-pointer flex-col items-start gap-1 rounded-lg border border-border p-3 transition-colors hover:bg-muted/40',
                    output === value && 'border-primary bg-muted/40',
                  )}
                >
                  <span className="flex items-center gap-2 text-[13px] font-medium">
                    <RadioGroupItem
                      id={`video-output-${value}`}
                      value={value}
                    />
                    <VideoFormatLabel format={value}>
                      {OUTPUT_LABELS[value]}
                    </VideoFormatLabel>
                  </span>
                  <span className="text-[12px] font-normal leading-snug text-muted-foreground">
                    {t(OUTPUT_DESCRIPTIONS[value])}
                  </span>
                </Label>
              ))}
            </RadioGroup>
          </div>

          <div className="space-y-2">
            <div className="flex items-center justify-between">
              <Label className="flex items-center gap-1 text-[13px]">
                {t('Profiles')}
                <VideoTermHint term="profile" />
              </Label>
              <Link
                to="/projects/$projectId/videos/profiles"
                params={{ projectId }}
                className="text-[12px] text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
              >
                {t('Manage profiles')}
              </Link>
            </div>
            {profilesLoading ? (
              <p className="py-4 text-center text-[13px] text-muted-foreground">
                {t('Loading profiles...')}
              </p>
            ) : profiles.length === 0 ? (
              <p className="py-4 text-center text-[13px] text-muted-foreground">
                {t('No profiles. Create a profile first.')}
              </p>
            ) : (
              <div className="divide-y divide-border overflow-hidden rounded-lg border border-border">
                {profiles.map((profile) => {
                  const exists = existingProfileIds.has(profile.$id)
                  return (
                    <label
                      key={profile.$id}
                      className={cn(
                        'flex items-center gap-3 px-3 py-2.5',
                        exists
                          ? 'cursor-not-allowed opacity-60'
                          : 'cursor-pointer hover:bg-muted/40',
                      )}
                    >
                      <Checkbox
                        checked={exists || selected.has(profile.$id)}
                        disabled={exists}
                        onCheckedChange={() => toggle(profile.$id)}
                      />
                      <span className="min-w-0 flex-1">
                        <span className="block truncate text-[13px] font-medium">
                          {profile.name}
                        </span>
                        <span className="block font-mono text-[11px] text-muted-foreground">
                          {formatResolution(profile.width, profile.height)} ·{' '}
                          {formatBitrate(profile.videoBitRate, 'kbps')} ·{' '}
                          {formatBitrate(profile.audioBitRate, 'kbps')}
                        </span>
                      </span>
                      {exists ? (
                        <Badge variant="info" className="text-[10px] shrink-0">
                          {t('Exists')}
                        </Badge>
                      ) : isUpscale(profile) ? (
                        <span className="flex shrink-0 items-center gap-1">
                          <Badge
                            variant="warning"
                            className="text-[10px] shrink-0"
                          >
                            {t('Upscale')}
                          </Badge>
                          <VideoTermHint term="upscale" />
                        </span>
                      ) : null}
                    </label>
                  )
                })}
              </div>
            )}
          </div>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
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
