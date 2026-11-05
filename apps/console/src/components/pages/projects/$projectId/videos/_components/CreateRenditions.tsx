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
import { VideoOutputBadge } from './VideoOutputBadge'
import { VideoTermHint } from './VideoTermHint'

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

  const selectable = profiles.filter(
    (profile) => !existingProfileIds.has(profile.$id),
  )
  const allSelected =
    selectable.length > 0 &&
    selectable.every((profile) => selected.has(profile.$id))

  const toggleAll = () => {
    setSelected(
      allSelected
        ? new Set()
        : new Set(selectable.map((profile) => profile.$id)),
    )
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
            <RadioGroup
              value={output}
              onValueChange={(value) => setOutput(value as VideoOutput)}
              aria-label={t('Output')}
              className="gap-0 divide-y divide-border overflow-hidden rounded-lg border border-border"
            >
              {VIDEO_OUTPUTS.map((value) => (
                <Label
                  key={value}
                  htmlFor={`video-output-${value}`}
                  className={cn(
                    'flex cursor-pointer items-center gap-3 px-4 py-3 font-normal leading-5 transition-colors hover:bg-muted/40',
                    output === value && 'bg-muted/40',
                  )}
                >
                  <RadioGroupItem id={`video-output-${value}`} value={value} />
                  <VideoOutputBadge
                    output={value}
                    className="w-12 shrink-0 justify-center"
                  />
                  <span className="min-w-0 flex-1 text-[13px] leading-5 text-muted-foreground">
                    {t(OUTPUT_DESCRIPTIONS[value])}
                  </span>
                </Label>
              ))}
            </RadioGroup>
          </div>

          <div className="space-y-2.5">
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
            <div className="overflow-hidden rounded-lg border border-border">
              <div className="flex items-center gap-4 border-b border-border bg-muted/30 px-4 py-2.5 text-[12px] font-medium leading-5 text-muted-foreground">
                <Checkbox
                  aria-label={t('Select all')}
                  checked={
                    allSelected
                      ? true
                      : selected.size > 0
                        ? 'indeterminate'
                        : false
                  }
                  disabled={selectable.length === 0}
                  onCheckedChange={toggleAll}
                />
                <span className="min-w-0 flex-1">{t('Profile')}</span>
                <span className="w-[96px] shrink-0 text-end">
                  {t('Resolution')}
                </span>
                <span className="w-[80px] shrink-0 text-end">{t('Video')}</span>
                <span className="w-[72px] shrink-0 text-end">{t('Audio')}</span>
              </div>
              {profilesLoading ? (
                <p className="px-3 py-6 text-center text-[13px] text-muted-foreground">
                  {t('Loading profiles...')}
                </p>
              ) : profiles.length === 0 ? (
                <p className="px-3 py-6 text-center text-[13px] text-muted-foreground">
                  {t('No profiles. Create a profile first.')}
                </p>
              ) : (
                <div className="divide-y divide-border">
                  {profiles.map((profile) => {
                    const exists = existingProfileIds.has(profile.$id)
                    const checked = selected.has(profile.$id)
                    return (
                      <label
                        key={profile.$id}
                        className={cn(
                          'flex items-center gap-4 px-4 py-3 leading-5 transition-colors',
                          exists
                            ? 'cursor-not-allowed'
                            : 'cursor-pointer hover:bg-muted/40',
                          checked && 'bg-muted/40',
                        )}
                      >
                        <Checkbox
                          checked={exists || checked}
                          disabled={exists}
                          onCheckedChange={() => toggle(profile.$id)}
                        />
                        <span
                          className={cn(
                            'flex min-w-0 flex-1 items-center gap-2',
                            exists && 'opacity-60',
                          )}
                        >
                          <span className="truncate text-[13px] font-medium leading-5">
                            {profile.name}
                          </span>
                          {exists ? (
                            <Badge
                              variant="info"
                              className="text-[10px] shrink-0"
                            >
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
                        </span>
                        <span
                          className={cn(
                            'w-[96px] shrink-0 text-end font-mono text-[12px] text-muted-foreground',
                            exists && 'opacity-60',
                          )}
                        >
                          {formatResolution(profile.width, profile.height)}
                        </span>
                        <span
                          className={cn(
                            'w-[80px] shrink-0 text-end font-mono text-[12px] text-muted-foreground',
                            exists && 'opacity-60',
                          )}
                        >
                          {formatBitrate(profile.videoBitRate, 'kbps')}
                        </span>
                        <span
                          className={cn(
                            'w-[72px] shrink-0 text-end font-mono text-[12px] text-muted-foreground',
                            exists && 'opacity-60',
                          )}
                        >
                          {formatBitrate(profile.audioBitRate, 'kbps')}
                        </span>
                      </label>
                    )
                  })}
                </div>
              )}
            </div>
          </div>
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
