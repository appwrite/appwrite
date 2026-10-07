import { useEffect, useState } from 'react'
import type { Models } from '@appwrite.io/console'
import { ArrowLeft, SlidersHorizontal } from 'lucide-react'
import { toast } from 'sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { useSaveVideoProfile, useVideoProfiles } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  formatBitrate,
  formatResolution,
  getQualityTier,
} from '@/lib/utils/video-format'
import {
  DEFAULT_PROFILE_PRESET,
  isSameSpec,
  PROFILE_PRESETS,
  type ProfilePreset,
} from '@/lib/videos/profile-presets'

const LIMITS = {
  width: { min: 16, max: 4320 },
  height: { min: 16, max: 4320 },
  videoBitRate: { min: 32, max: 20000 },
  audioBitRate: { min: 32, max: 512 },
} as const

type NumericField = keyof typeof LIMITS

type FormState = {
  name: string
} & Record<NumericField, string>

const EMPTY_FORM: FormState = {
  name: '',
  width: String(DEFAULT_PROFILE_PRESET.width),
  height: String(DEFAULT_PROFILE_PRESET.height),
  videoBitRate: String(DEFAULT_PROFILE_PRESET.videoBitRate),
  audioBitRate: String(DEFAULT_PROFILE_PRESET.audioBitRate),
}

function toForm(
  profile: Models.VideoProfile | ProfilePreset | null | undefined,
): FormState {
  if (!profile) return EMPTY_FORM
  return {
    name: profile.name,
    width: String(profile.width),
    height: String(profile.height),
    videoBitRate: String(profile.videoBitRate),
    audioBitRate: String(profile.audioBitRate),
  }
}

function isInRange(field: NumericField, value: string): boolean {
  const n = Number(value)
  return Number.isInteger(n) && n >= LIMITS[field].min && n <= LIMITS[field].max
}

type CreateProfileProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  /** When set, the dialog updates this profile instead of creating one. */
  profile?: Models.VideoProfile | null
}

export function CreateProfile({
  open,
  onOpenChange,
  projectId,
  profile,
}: CreateProfileProps) {
  const t = useT()
  const [form, setForm] = useState<FormState>(() => toForm(profile))
  const [mode, setMode] = useState<'preset' | 'custom'>('preset')
  const [preset, setPreset] = useState<ProfilePreset>(DEFAULT_PROFILE_PRESET)
  const saveMutation = useSaveVideoProfile(projectId)
  const { data: profilesData } = useVideoProfiles(projectId)
  const isUpdate = !!profile
  const isCustom = isUpdate || mode === 'custom'
  const existingProfiles = profilesData?.profiles ?? []

  useEffect(() => {
    if (!open) return
    setForm(toForm(profile))
    setMode('preset')
    setPreset(DEFAULT_PROFILE_PRESET)
  }, [open, profile])

  const openCustom = () => {
    setForm(toForm(preset))
    setMode('custom')
  }

  const numericFields: {
    field: NumericField
    label: string
    unit: string
  }[] = [
    { field: 'width', label: t('Width'), unit: 'px' },
    { field: 'height', label: t('Height'), unit: 'px' },
    { field: 'videoBitRate', label: t('Video bitrate'), unit: 'kbps' },
    { field: 'audioBitRate', label: t('Audio bitrate'), unit: 'kbps' },
  ]

  const isValid =
    !isCustom ||
    (form.name.trim().length > 0 &&
      numericFields.every(({ field }) => isInRange(field, form[field])))

  const handleSubmit = (event: React.FormEvent) => {
    event.preventDefault()
    if (!isValid) return
    saveMutation.mutate(
      isCustom
        ? {
            profileId: profile?.$id,
            name: form.name.trim(),
            width: Number(form.width),
            height: Number(form.height),
            videoBitRate: Number(form.videoBitRate),
            audioBitRate: Number(form.audioBitRate),
          }
        : {
            name: preset.name,
            width: preset.width,
            height: preset.height,
            videoBitRate: preset.videoBitRate,
            audioBitRate: preset.audioBitRate,
          },
      {
        onSuccess: () => {
          toast.success(isUpdate ? t('Profile updated') : t('Profile created'))
          setForm(EMPTY_FORM)
          onOpenChange(false)
        },
        onError: (error) => {
          toast.error(getErrorMessage(error) || t('Failed to save profile'))
        },
      },
    )
  }

  let title = t('Create profile')
  let description = t(
    'Choose a preset to add a rendition quality. Video codec is chosen by the server when encoding.',
  )
  if (isUpdate) {
    title = t('Update profile')
    description = t(
      'Profiles define the resolution and bitrate of each rendition. Video codec is chosen by the server when encoding. Use profiles when creating adaptive streams.',
    )
  } else if (isCustom) {
    title = t('Create custom profile')
    description = t(
      'Set the resolution and bitrate of the rendition. Video codec is chosen by the server when encoding.',
    )
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg p-0 max-h-[90dvh] overflow-y-auto">
        <form onSubmit={handleSubmit}>
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{title}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {description}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          {isCustom ? (
            <div className="space-y-4 px-6 pb-4 pt-4">
              <div className="space-y-2">
                <Label htmlFor="video-profile-name" className="text-[13px]">
                  {t('Name')}
                </Label>
                <Input
                  id="video-profile-name"
                  value={form.name}
                  onChange={(e) => setForm({ ...form, name: e.target.value })}
                  placeholder="720p"
                  autoFocus
                  maxLength={128}
                />
              </div>
              <div className="grid grid-cols-2 gap-4">
                {numericFields.map(({ field, label, unit }) => {
                  const invalid =
                    form[field].length > 0 && !isInRange(field, form[field])
                  return (
                    <div key={field} className="space-y-2">
                      <Label
                        htmlFor={`video-profile-${field}`}
                        className="text-[13px]"
                      >
                        {label}{' '}
                        <span className="text-muted-foreground">({unit})</span>
                      </Label>
                      <Input
                        id={`video-profile-${field}`}
                        type="number"
                        inputMode="numeric"
                        min={LIMITS[field].min}
                        max={LIMITS[field].max}
                        value={form[field]}
                        aria-invalid={invalid}
                        onChange={(e) =>
                          setForm({ ...form, [field]: e.target.value })
                        }
                      />
                      <p className="text-[12px] text-muted-foreground">
                        {LIMITS[field].min} - {LIMITS[field].max}
                      </p>
                    </div>
                  )
                })}
              </div>
            </div>
          ) : (
            <div className="px-6 pb-4 pt-4">
              <RadioGroup
                value={preset.name}
                onValueChange={(name) =>
                  setPreset(
                    PROFILE_PRESETS.find((item) => item.name === name) ??
                      DEFAULT_PROFILE_PRESET,
                  )
                }
                aria-label={t('Presets')}
                className="gap-0 divide-y divide-border overflow-hidden rounded-lg border border-border"
              >
                {PROFILE_PRESETS.map((item) => {
                  const selected = item.name === preset.name
                  const exists = existingProfiles.some((existing) =>
                    isSameSpec(existing, item),
                  )
                  return (
                    <Label
                      key={item.name}
                      htmlFor={`video-profile-preset-${item.name}`}
                      className={cn(
                        'flex cursor-pointer items-center gap-3 px-3 py-2.5 font-normal transition-colors hover:bg-muted/40',
                        selected && 'bg-muted/40',
                      )}
                    >
                      <RadioGroupItem
                        id={`video-profile-preset-${item.name}`}
                        value={item.name}
                      />
                      <span className="flex min-w-0 flex-1 items-center gap-2">
                        <span className="text-[13px] font-medium">
                          {item.name}
                        </span>
                        <Badge variant="outline" className="text-[10px]">
                          {getQualityTier(item.width, item.height)}
                        </Badge>
                        {exists ? (
                          <Badge variant="info" className="text-[10px]">
                            {t('Exists')}
                          </Badge>
                        ) : null}
                      </span>
                      <span className="w-[84px] shrink-0 text-end font-mono text-[11px] text-muted-foreground">
                        {formatResolution(item.width, item.height)}
                      </span>
                      <span className="w-[72px] shrink-0 text-end font-mono text-[11px] text-muted-foreground">
                        {formatBitrate(item.videoBitRate, 'kbps')}
                      </span>
                      <span className="w-[60px] shrink-0 text-end font-mono text-[11px] text-muted-foreground">
                        {formatBitrate(item.audioBitRate, 'kbps')}
                      </span>
                    </Label>
                  )
                })}
              </RadioGroup>
            </div>
          )}
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:items-center">
            {isUpdate ? null : (
              <Button
                type="button"
                variant="ghost"
                className="sm:me-auto text-muted-foreground"
                onClick={isCustom ? () => setMode('preset') : openCustom}
                disabled={saveMutation.isPending}
              >
                {isCustom ? (
                  <ArrowLeft className="rtl:rotate-180" />
                ) : (
                  <SlidersHorizontal />
                )}
                {isCustom ? t('Presets') : t('Custom profile')}
              </Button>
            )}
            <Button
              type="button"
              variant="outline"
              className={cn(isUpdate && 'sm:ms-auto')}
              onClick={() => onOpenChange(false)}
              disabled={saveMutation.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button type="submit" disabled={!isValid || saveMutation.isPending}>
              {isUpdate ? t('Update') : t('Create')}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
