import { VideoCodec, type Models } from '@appwrite.io/console'
import { useEffect, useState } from 'react'
import { ArrowLeft, SlidersHorizontal } from 'lucide-react'
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
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import {
  useCreateVideoProfiles,
  useSaveVideoProfile,
  useVideoCodecs,
  useVideoProfiles,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import {
  WIZARD_DIALOG_CONTENT_Z,
  WIZARD_DIALOG_OVERLAY_Z,
} from '@/lib/wizard-portal-z'
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
  /** Stack the dialog above a fullscreen wizard. */
  aboveWizard?: boolean
}

export function CreateProfile({
  open,
  onOpenChange,
  projectId,
  profile,
  aboveWizard = false,
}: CreateProfileProps) {
  const t = useT()
  const [form, setForm] = useState<FormState>(() => toForm(profile))
  const [mode, setMode] = useState<'preset' | 'custom'>('preset')
  const [presets, setPresets] = useState<Set<string>>(
    () => new Set([DEFAULT_PROFILE_PRESET.name]),
  )
  const [codec, setCodec] = useState<string>(VideoCodec.H264)
  const saveMutation = useSaveVideoProfile(projectId)
  const createMutation = useCreateVideoProfiles(projectId)
  const pending = saveMutation.isPending || createMutation.isPending
  const { data: profilesData } = useVideoProfiles(projectId)
  const { data: codecs = [] } = useVideoCodecs(projectId)
  const isUpdate = !!profile
  const isCustom = isUpdate || mode === 'custom'
  const existingProfiles = profilesData?.profiles ?? []
  const codecName = (id: string) =>
    codecs.find((item) => item.$id === id)?.name ?? id.toUpperCase()
  const showCodecPicker = !isUpdate && codecs.length > 1
  const presetExists = (item: ProfilePreset) =>
    existingProfiles.some(
      (existing) =>
        (existing.codec || VideoCodec.H264) === codec &&
        isSameSpec(existing, item),
    )
  // A preset that already exists for the codec cannot be added again.
  const chosenPresets = PROFILE_PRESETS.filter(
    (item) => presets.has(item.name) && !presetExists(item),
  )
  const togglePreset = (name: string, checked: boolean) =>
    setPresets((current) => {
      const next = new Set(current)
      if (checked) next.add(name)
      else next.delete(name)
      return next
    })

  useEffect(() => {
    if (!open) return
    setForm(toForm(profile))
    setMode('preset')
    setPresets(new Set([DEFAULT_PROFILE_PRESET.name]))
    setCodec(profile?.codec || VideoCodec.H264)
  }, [open, profile])

  const openCustom = () => {
    setForm(toForm(chosenPresets[0] ?? DEFAULT_PROFILE_PRESET))
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

  const isValid = isCustom
    ? form.name.trim().length > 0 &&
      numericFields.every(({ field }) => isInRange(field, form[field]))
    : chosenPresets.length > 0

  const createPresets = () => {
    createMutation.mutate(
      chosenPresets.map((item) => ({
        name: item.name,
        width: item.width,
        height: item.height,
        videoBitRate: item.videoBitRate,
        audioBitRate: item.audioBitRate,
        codec: codec as VideoCodec,
      })),
      {
        onSuccess: (failures) => {
          const created = chosenPresets.length - failures.length
          if (created > 0) {
            toast.success(
              created === 1
                ? t('Profile created')
                : `${created} ${t('profiles created')}`,
            )
          }
          if (failures.length === 0) {
            onOpenChange(false)
            return
          }
          // Keep only the failed presets selected so the user can retry them.
          setPresets(new Set(failures.map(({ input }) => input.name)))
          for (const { input, error } of failures) {
            toast.error(
              `${input.name}: ${getErrorMessage(error) || t('Failed to save profile')}`,
            )
          }
        },
      },
    )
  }

  const handleSubmit = (event: React.FormEvent) => {
    event.preventDefault()
    if (!isValid) return
    if (!isCustom) {
      createPresets()
      return
    }
    saveMutation.mutate(
      {
        profileId: profile?.$id,
        name: form.name.trim(),
        width: Number(form.width),
        height: Number(form.height),
        videoBitRate: Number(form.videoBitRate),
        audioBitRate: Number(form.audioBitRate),
        codec: codec as VideoCodec,
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
  let description = t('Choose presets to add rendition qualities.')
  if (isUpdate) {
    title = t('Update profile')
    description = t(
      'Profiles define the codec, resolution, and bitrate of each rendition. A profile keeps the codec it was created with.',
    )
  } else if (isCustom) {
    title = t('Create custom profile')
    description = t('Set the resolution and bitrate of the rendition.')
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className={cn(
          'sm:max-w-lg p-0 max-h-[90dvh] overflow-y-auto',
          aboveWizard && WIZARD_DIALOG_CONTENT_Z,
        )}
        overlayClassName={aboveWizard ? WIZARD_DIALOG_OVERLAY_Z : undefined}
      >
        <form onSubmit={handleSubmit}>
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{title}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {description}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          {showCodecPicker ? (
            <div className="space-y-2 px-6 pt-4">
              <Label className="text-[13px]">{t('Codec')}</Label>
              <RadioGroup
                value={codec}
                onValueChange={setCodec}
                aria-label={t('Codec')}
                className="flex flex-wrap gap-2"
              >
                {codecs.map((item) => (
                  <Label
                    key={item.$id}
                    htmlFor={`video-profile-codec-${item.$id}`}
                    className={cn(
                      'flex cursor-pointer items-center gap-2 rounded-md border border-border px-3 py-2 text-[13px] font-normal transition-colors hover:bg-muted/40',
                      codec === item.$id && 'bg-muted/40',
                    )}
                  >
                    <RadioGroupItem
                      id={`video-profile-codec-${item.$id}`}
                      value={item.$id}
                    />
                    {item.name}
                    <span className="font-mono text-[11px] uppercase text-muted-foreground">
                      {item.outputs.join(' · ')}
                    </span>
                  </Label>
                ))}
              </RadioGroup>
            </div>
          ) : null}
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
              {isUpdate ? (
                <div className="space-y-2">
                  <Label htmlFor="video-profile-codec" className="text-[13px]">
                    {t('Codec')}
                  </Label>
                  <Input
                    id="video-profile-codec"
                    value={codecName(profile?.codec || VideoCodec.H264)}
                    disabled
                    readOnly
                  />
                </div>
              ) : null}
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
              <div
                role="group"
                aria-label={t('Presets')}
                className="divide-y divide-border overflow-hidden rounded-lg border border-border"
              >
                {PROFILE_PRESETS.map((item) => {
                  const exists = presetExists(item)
                  const checked = !exists && presets.has(item.name)
                  return (
                    <Label
                      key={item.name}
                      htmlFor={`video-profile-preset-${item.name}`}
                      className={cn(
                        'flex items-center gap-3 px-3 py-2.5 font-normal transition-colors',
                        exists
                          ? 'cursor-default opacity-60'
                          : 'cursor-pointer hover:bg-muted/40',
                        checked && 'bg-muted/40',
                      )}
                    >
                      <Checkbox
                        id={`video-profile-preset-${item.name}`}
                        checked={checked}
                        disabled={exists || pending}
                        onCheckedChange={(value) =>
                          togglePreset(item.name, value === true)
                        }
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
              </div>
            </div>
          )}
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:items-center">
            {isUpdate ? null : (
              <Button
                type="button"
                variant="ghost"
                className="sm:me-auto text-muted-foreground"
                onClick={isCustom ? () => setMode('preset') : openCustom}
                disabled={pending}
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
              disabled={pending}
            >
              {t('Cancel')}
            </Button>
            <Button type="submit" disabled={!isValid || pending}>
              {isUpdate
                ? t('Update')
                : !isCustom && chosenPresets.length > 1
                  ? `${t('Create')} (${chosenPresets.length})`
                  : t('Create')}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
