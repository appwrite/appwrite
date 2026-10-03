import { useMemo } from 'react'
import type { Models } from '@appwrite.io/console'
import { SearchableSelect } from '@/components/global/shared/SearchableSelect'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import { useT } from '@/lib/i18n/translate'
import {
  getVideoSubtitleLanguage,
  VIDEO_SUBTITLE_LANGUAGES,
} from '@/lib/videos/subtitle-languages'
import { VIDEO_SUBTITLE_NAME_PATTERN } from '@/lib/videos/subtitle-cues'
import { VideoActionButton } from '../VideoPage'

export type SubtitleTrackDraft = {
  name: string
  code: string
  isDefault: boolean
  bucketId: string
}

/** Buckets with an extension allowlist must include `vtt` to accept the saved file. */
export function bucketAcceptsWebVtt(bucket: Models.Bucket | undefined): boolean {
  if (!bucket) return false
  const allowed = bucket.allowedFileExtensions ?? []
  return allowed.length === 0 || allowed.some((ext) => ext.toLowerCase() === 'vtt')
}

export function isSubtitleTrackNameValid(name: string): boolean {
  return name.trim().length > 0 && VIDEO_SUBTITLE_NAME_PATTERN.test(name)
}

export function SubtitleTrackDetails({
  draft,
  onChange,
  buckets,
  bucketsLoading,
  embedded,
  onDelete,
  deleteDisabledReason,
}: {
  draft: SubtitleTrackDraft
  onChange: (patch: Partial<SubtitleTrackDraft>) => void
  buckets: Models.Bucket[]
  bucketsLoading: boolean
  embedded: boolean
  /** Shown for existing tracks only. */
  onDelete?: () => void
  deleteDisabledReason?: string | null
}) {
  const t = useT()
  const languageItems = useMemo(
    () =>
      VIDEO_SUBTITLE_LANGUAGES.map((language) => ({
        value: language.code,
        label: `${language.name} (${language.code})`,
        searchText: `${language.name} ${language.nativeName} ${language.code}`,
        description: language.nativeName,
        inlineDescription: true,
      })),
    [],
  )

  const handleLanguageChange = (code: string) => {
    const previous = getVideoSubtitleLanguage(draft.code)
    const patch: Partial<SubtitleTrackDraft> = { code }
    if (!draft.name.trim() || draft.name === previous?.name) {
      patch.name = getVideoSubtitleLanguage(code)?.name ?? draft.name
    }
    onChange(patch)
  }

  const nameValid = isSubtitleTrackNameValid(draft.name)
  const bucket = buckets.find((b) => b.$id === draft.bucketId)
  const bucketRejectsVtt = !!bucket && !bucketAcceptsWebVtt(bucket)

  return (
    <div className="min-h-0 flex-1 space-y-4 overflow-y-auto p-4">
      <div className="space-y-2">
        <Label className="text-[13px]">{t('Language')}</Label>
        <SearchableSelect
          value={draft.code}
          onValueChange={handleLanguageChange}
          items={languageItems}
          placeholder={t('Select language')}
          searchPlaceholder={t('Search languages...')}
          emptyMessage={t('No languages found')}
          triggerClassName="h-9 w-full text-[13px]"
        />
      </div>
      <div className="space-y-2">
        <Label htmlFor="subtitle-editor-name" className="text-[13px]">
          {t('Name')}
        </Label>
        <Input
          id="subtitle-editor-name"
          value={draft.name}
          maxLength={128}
          aria-invalid={draft.name.length > 0 && !nameValid}
          onChange={(event) => onChange({ name: event.target.value })}
          placeholder="English"
          className="h-9 text-[13px]"
        />
        <p className="text-[12px] text-muted-foreground">
          {t(
            "Shown in the player's subtitle menu. Letters, numbers, spaces, and - . , ( ) _ ' only.",
          )}
        </p>
      </div>
      <div className="flex items-start justify-between gap-4 rounded-lg border border-border px-3 py-2.5">
        <div className="min-w-0">
          <Label htmlFor="subtitle-editor-default" className="text-[13px]">
            {t('Default subtitle')}
          </Label>
          <p className="mt-0.5 text-[12px] text-muted-foreground">
            {t('Players turn this subtitle on automatically.')}
          </p>
        </div>
        <Switch
          id="subtitle-editor-default"
          checked={draft.isDefault}
          onCheckedChange={(isDefault) => onChange({ isDefault })}
        />
      </div>
      <div className="space-y-2">
        <Label className="text-[13px]">{t('Storage bucket')}</Label>
        <Select
          value={draft.bucketId || undefined}
          onValueChange={(bucketId) => onChange({ bucketId })}
          disabled={bucketsLoading}
        >
          <SelectTrigger className="h-9 w-full text-[13px]">
            <SelectValue placeholder={t('Select a bucket')} />
          </SelectTrigger>
          <SelectContent>
            {buckets.map((item) => (
              <SelectItem key={item.$id} value={item.$id}>
                <span className="truncate">{item.name}</span>
                <span className="ms-2 font-mono text-[11px] text-muted-foreground">
                  {item.$id}
                </span>
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
        {bucketRejectsVtt ? (
          <p className="text-[12px] text-amber-600 dark:text-amber-400">
            {t(
              'This bucket only allows specific file extensions and .vtt is not one of them. Choose another bucket or allow .vtt in the bucket settings.',
            )}
          </p>
        ) : (
          <p className="text-[12px] text-muted-foreground">
            {embedded
              ? t(
                  'Embedded tracks have no file in Storage. Saving uploads the cues as a new WebVTT file to this bucket and links the track to it.',
                )
              : t(
                  'Saving uploads a new WebVTT file to this bucket and re-packages the track. The previous file is kept.',
                )}
          </p>
        )}
      </div>
      {onDelete ? (
        <div className="overflow-hidden rounded-lg border border-destructive/50">
          <div className="px-3 py-2.5">
            <p className="text-[13px] font-medium text-foreground">
              {t('Delete subtitle')}
            </p>
            <p className="mt-0.5 text-[12px] text-muted-foreground">
              {embedded
                ? t(
                    'Embedded tracks are extracted only once, so this track will not come back. This action cannot be undone.',
                  )
                : t('The Storage file is kept. This action cannot be undone.')}
            </p>
          </div>
          <div className="border-t border-destructive/20 bg-destructive/5 px-3 py-2.5">
            <VideoActionButton
              variant="destructive"
              size="sm"
              className="h-8 text-[12px]"
              disabledReason={deleteDisabledReason}
              onClick={onDelete}
            >
              {t('Delete subtitle')}
            </VideoActionButton>
          </div>
        </div>
      ) : null}
    </div>
  )
}
