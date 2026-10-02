import { useEffect, useState } from 'react'
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
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useSaveVideoProfile } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'

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
  width: '1280',
  height: '720',
  videoBitRate: '2800',
  audioBitRate: '128',
}

function toForm(profile: Models.VideoProfile | null | undefined): FormState {
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
  const saveMutation = useSaveVideoProfile(projectId)
  const isUpdate = !!profile

  useEffect(() => {
    if (open) setForm(toForm(profile))
  }, [open, profile])

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
    form.name.trim().length > 0 &&
    numericFields.every(({ field }) => isInRange(field, form[field]))

  const handleSubmit = (event: React.FormEvent) => {
    event.preventDefault()
    if (!isValid) return
    saveMutation.mutate(
      {
        profileId: profile?.$id,
        name: form.name.trim(),
        width: Number(form.width),
        height: Number(form.height),
        videoBitRate: Number(form.videoBitRate),
        audioBitRate: Number(form.audioBitRate),
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

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0 max-h-[90dvh] overflow-y-auto">
        <form onSubmit={handleSubmit}>
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>
              {isUpdate ? t('Update profile') : t('Create profile')}
            </DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t(
                'Profiles define the resolution and bitrate of each rendition. Video codec is chosen by the server when encoding. Use profiles when creating adaptive streams.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
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
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
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
