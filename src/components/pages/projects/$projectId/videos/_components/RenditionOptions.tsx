import { Link } from '@tanstack/react-router'
import { AlertTriangle } from 'lucide-react'
import { VideoOutput } from '@appwrite.io/console'
import type { Models } from '@appwrite.io/console'
import { Badge } from '@/components/ui/badge'
import { Checkbox } from '@/components/ui/checkbox'
import { Label } from '@/components/ui/label'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { VIDEO_OUTPUTS } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { formatBitrate, formatResolution } from '@/lib/utils/video-format'
import { VideoOutputBadge } from './VideoOutputBadge'
import { VideoTermHint } from './VideoTermHint'

const OUTPUT_DESCRIPTIONS: Record<VideoOutput, string> = {
  [VideoOutput.Hls]: 'MPEG-TS segments. Plays natively on Safari and iOS.',
  [VideoOutput.Dash]: 'MPEG-DASH with fragmented MP4 segments.',
  [VideoOutput.Cmaf]: 'Fragmented MP4 served as both HLS and DASH.',
}

export function sortProfilesByPixels(
  profiles: Models.VideoProfile[],
): Models.VideoProfile[] {
  return [...profiles].sort((a, b) => a.width * a.height - b.width * b.height)
}

export type VideoSourceSize = { width: number; height: number }

/**
 * Compares short and long sides so portrait sources are judged against
 * landscape profiles. A zero or missing size means it is not known yet.
 */
export function isProfileUpscale(
  profile: Pick<Models.VideoProfile, 'width' | 'height'>,
  source: VideoSourceSize | null | undefined,
): boolean {
  if (!source || source.width <= 0 || source.height <= 0) return false
  const sourceShort = Math.min(source.width, source.height)
  const sourceLong = Math.max(source.width, source.height)
  return (
    Math.min(profile.width, profile.height) > sourceShort ||
    Math.max(profile.width, profile.height) > sourceLong
  )
}

/** Every profile that is not already encoded and would not upscale the source. */
export function getDefaultProfileSelection(
  profiles: Models.VideoProfile[],
  source: VideoSourceSize | null | undefined,
  existingProfileIds: ReadonlySet<string> = new Set(),
): Set<string> {
  return new Set(
    profiles
      .filter(
        (profile) =>
          !existingProfileIds.has(profile.$id) &&
          !isProfileUpscale(profile, source),
      )
      .map((profile) => profile.$id),
  )
}

export function OutputPicker({
  value,
  onValueChange,
}: {
  value: VideoOutput
  onValueChange: (value: VideoOutput) => void
}) {
  const t = useT()
  return (
    <RadioGroup
      value={value}
      onValueChange={(next) => onValueChange(next as VideoOutput)}
      aria-label={t('Output')}
      className="gap-0 divide-y divide-border overflow-hidden rounded-lg border border-border"
    >
      {VIDEO_OUTPUTS.map((output) => (
        <Label
          key={output}
          htmlFor={`video-output-${output}`}
          className={cn(
            'flex cursor-pointer items-center gap-3 px-4 py-3 font-normal leading-5 transition-colors hover:bg-muted/40',
            value === output && 'bg-muted/40',
          )}
        >
          <RadioGroupItem id={`video-output-${output}`} value={output} />
          <VideoOutputBadge
            output={output}
            className="w-12 shrink-0 justify-center"
          />
          <span className="min-w-0 flex-1 text-[13px] leading-5 text-muted-foreground">
            {t(OUTPUT_DESCRIPTIONS[output])}
          </span>
        </Label>
      ))}
    </RadioGroup>
  )
}

export function ProfilePicker({
  projectId,
  profiles,
  loading,
  selected,
  onSelectedChange,
  existingProfileIds,
  source,
  openManageInNewTab = false,
}: {
  projectId: string
  /** Pre-sorted, see `sortProfilesByPixels`. */
  profiles: Models.VideoProfile[]
  loading: boolean
  selected: ReadonlySet<string>
  onSelectedChange: (selected: Set<string>) => void
  existingProfileIds?: ReadonlySet<string>
  source?: VideoSourceSize | null
  openManageInNewTab?: boolean
}) {
  const t = useT()
  const exists = (profileId: string) => !!existingProfileIds?.has(profileId)
  const selectable = profiles.filter((profile) => !exists(profile.$id))
  const allSelected =
    selectable.length > 0 &&
    selectable.every((profile) => selected.has(profile.$id))
  const selectedUpscales = profiles.filter(
    (profile) =>
      selected.has(profile.$id) &&
      !exists(profile.$id) &&
      isProfileUpscale(profile, source),
  )

  const toggle = (profileId: string) => {
    const next = new Set(selected)
    if (next.has(profileId)) next.delete(profileId)
    else next.add(profileId)
    onSelectedChange(next)
  }

  const toggleAll = () => {
    onSelectedChange(
      allSelected
        ? new Set()
        : new Set(selectable.map((profile) => profile.$id)),
    )
  }

  return (
    <div className="space-y-2.5">
      <div className="flex items-center justify-between">
        <Label className="flex items-center gap-1 text-[13px]">
          {t('Profiles')}
          <VideoTermHint term="profile" />
        </Label>
        <Link
          to="/projects/$projectId/videos/profiles"
          params={{ projectId }}
          target={openManageInNewTab ? '_blank' : undefined}
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
              allSelected ? true : selected.size > 0 ? 'indeterminate' : false
            }
            disabled={selectable.length === 0}
            onCheckedChange={toggleAll}
          />
          <span className="min-w-0 flex-1">{t('Profile')}</span>
          <span className="w-[96px] shrink-0 text-end">{t('Resolution')}</span>
          <span className="w-[80px] shrink-0 text-end">{t('Video')}</span>
          <span className="w-[72px] shrink-0 text-end">{t('Audio')}</span>
        </div>
        {loading ? (
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
              const encoded = exists(profile.$id)
              const checked = selected.has(profile.$id)
              const dimmed = encoded && 'opacity-60'
              return (
                <label
                  key={profile.$id}
                  className={cn(
                    'flex items-center gap-4 px-4 py-3 leading-5 transition-colors',
                    encoded
                      ? 'cursor-not-allowed'
                      : 'cursor-pointer hover:bg-muted/40',
                    checked && 'bg-muted/40',
                  )}
                >
                  <Checkbox
                    checked={encoded || checked}
                    disabled={encoded}
                    onCheckedChange={() => toggle(profile.$id)}
                  />
                  <span
                    className={cn(
                      'flex min-w-0 flex-1 items-center gap-2',
                      dimmed,
                    )}
                  >
                    <span className="truncate text-[13px] font-medium leading-5">
                      {profile.name}
                    </span>
                    {encoded ? (
                      <Badge variant="info" className="text-[10px] shrink-0">
                        {t('Exists')}
                      </Badge>
                    ) : isProfileUpscale(profile, source) ? (
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
                      dimmed,
                    )}
                  >
                    {formatResolution(profile.width, profile.height)}
                  </span>
                  <span
                    className={cn(
                      'w-[80px] shrink-0 text-end font-mono text-[12px] text-muted-foreground',
                      dimmed,
                    )}
                  >
                    {formatBitrate(profile.videoBitRate, 'kbps')}
                  </span>
                  <span
                    className={cn(
                      'w-[72px] shrink-0 text-end font-mono text-[12px] text-muted-foreground',
                      dimmed,
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
      {selectedUpscales.length > 0 && source ? (
        <p className="flex items-start gap-1.5 text-[12px] text-amber-600 dark:text-amber-400">
          <AlertTriangle className="mt-0.5 h-3.5 w-3.5 shrink-0" />
          <span>
            {selectedUpscales.length === 1
              ? t('1 selected profile is larger than the source')
              : `${selectedUpscales.length} ${t('selected profiles are larger than the source')}`}{' '}
            ({formatResolution(source.width, source.height)}).{' '}
            {t('Upscaling adds size without adding detail.')}
          </span>
        </p>
      ) : null}
    </div>
  )
}
