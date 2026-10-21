import { useEffect, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { Captions } from 'lucide-react'
import { toast } from 'sonner'
import { SearchableSelect } from '@/components/global/shared/SearchableSelect'
import {
  StorageFileExplorerDialog,
  type StorageFileSelection,
} from '@/components/global/shared/StorageFileExplorerDialog'
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
import { Switch } from '@/components/ui/switch'
import { sdk } from '@/lib/appwrite/sdk'
import {
  useCreateVideoSubtitle,
  useUpdateVideoSubtitle,
} from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { formatBytes } from '@/lib/utils/mock-data'
import {
  getVideoSubtitleLanguage,
  VIDEO_SUBTITLE_LANGUAGES,
} from '@/lib/videos/subtitle-languages'

const SUBTITLE_MIME_TYPES = ['text/vtt', 'text/plain', 'application/x-subrip']
const SUBTITLE_NAME_PATTERN = /^[A-Za-z0-9 \-.,()_']*$/

type CreateSubtitleProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  videoId: string
  /** When set, the dialog updates this subtitle instead of creating one. */
  subtitle?: Models.VideoSubtitle | null
}

export function CreateSubtitle({
  open,
  onOpenChange,
  projectId,
  videoId,
  subtitle,
}: CreateSubtitleProps) {
  const t = useT()
  const isUpdate = !!subtitle
  const [source, setSource] = useState<StorageFileSelection | null>(null)
  const [explorerOpen, setExplorerOpen] = useState(false)
  const [name, setName] = useState('')
  const [code, setCode] = useState('')
  const [isDefault, setIsDefault] = useState(false)
  const createMutation = useCreateVideoSubtitle(projectId, videoId)
  const updateMutation = useUpdateVideoSubtitle(projectId, videoId)
  const isPending = createMutation.isPending || updateMutation.isPending

  useEffect(() => {
    if (!open) return
    setSource(null)
    setName(subtitle?.name ?? '')
    setCode(subtitle?.code ?? '')
    setIsDefault(subtitle?.default ?? false)
  }, [open, subtitle])

  const fileRef =
    source ??
    (subtitle?.bucketId && subtitle.fileId
      ? { bucketId: subtitle.bucketId, fileId: subtitle.fileId }
      : null)
  const { data: file } = useQuery({
    queryKey: [
      'video-subtitle-file',
      projectId,
      fileRef?.bucketId,
      fileRef?.fileId,
    ],
    queryFn: () =>
      sdk.forProject(projectId).storage.getFile({
        bucketId: fileRef!.bucketId,
        fileId: fileRef!.fileId,
      }),
    enabled: open && !!fileRef?.bucketId && !!fileRef?.fileId,
    staleTime: 60 * 1000,
    retry: false,
  })

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

  const handleLanguageChange = (value: string) => {
    const previous = getVideoSubtitleLanguage(code)
    setCode(value)
    if (!name.trim() || name === previous?.name) {
      setName(getVideoSubtitleLanguage(value)?.name ?? name)
    }
  }

  const nameValid = name.trim().length > 0 && SUBTITLE_NAME_PATTERN.test(name)
  const fileLooksWrong =
    !!source && !!file && !SUBTITLE_MIME_TYPES.includes(file.mimeType)
  const canSubmit = nameValid && !!code && (isUpdate || !!source) && !isPending

  const handleSubmit = () => {
    if (!canSubmit) return
    const onError = (error: Error) =>
      toast.error(getErrorMessage(error) || t('Failed to save subtitle'))

    if (subtitle) {
      updateMutation.mutate(
        {
          subtitleId: subtitle.$id,
          name: name.trim(),
          code,
          isDefault,
          ...(source
            ? { bucketId: source.bucketId, fileId: source.fileId }
            : {}),
        },
        {
          onSuccess: () => {
            toast.success(t('Subtitle updated'))
            onOpenChange(false)
          },
          onError,
        },
      )
      return
    }

    createMutation.mutate(
      {
        bucketId: source!.bucketId,
        fileId: source!.fileId,
        name: name.trim(),
        code,
        isDefault,
      },
      {
        onSuccess: () => {
          toast.success(t('Subtitle added'))
          onOpenChange(false)
        },
        onError,
      },
    )
  }

  return (
    <>
      <Dialog open={open} onOpenChange={onOpenChange}>
        <DialogContent className="sm:max-w-md p-0 max-h-[90dvh] overflow-y-auto">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>
              {isUpdate ? t('Update subtitle') : t('Create subtitle')}
            </DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t(
                'Attach a WebVTT or SRT file from Storage. Subtitles are segmented to match the stream and listed in every manifest.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="space-y-4 px-6 pb-4 pt-4">
            <div className="space-y-2">
              <Label className="text-[13px]">{t('Subtitle file')}</Label>
              {fileRef ? (
                <div className="flex items-center gap-3 rounded-lg border border-border px-3 py-2.5">
                  <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
                    <Captions className="h-4 w-4" />
                  </div>
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-[13px] font-medium text-foreground">
                      {file?.name ?? fileRef.fileId}
                    </p>
                    <p className="truncate text-[12px] text-muted-foreground">
                      {file
                        ? `${file.mimeType} · ${formatBytes(file.sizeOriginal)}`
                        : fileRef.bucketId}
                    </p>
                  </div>
                  <Button
                    variant="outline"
                    size="sm"
                    className="h-8 text-[12px]"
                    onClick={() => setExplorerOpen(true)}
                  >
                    {isUpdate && !source ? t('Replace') : t('Change')}
                  </Button>
                </div>
              ) : (
                <Button
                  variant="outline"
                  className="h-9 w-full justify-start gap-1.5 text-[13px] text-muted-foreground"
                  onClick={() => setExplorerOpen(true)}
                >
                  <Captions className="h-4 w-4" />
                  {t('Select file')}
                </Button>
              )}
              {fileLooksWrong ? (
                <p className="text-[12px] text-amber-600 dark:text-amber-400">
                  {t(
                    'Subtitle files must be WebVTT (text/vtt), SRT (application/x-subrip), or plain text.',
                  )}
                </p>
              ) : null}
            </div>
            <div className="space-y-2">
              <Label className="text-[13px]">{t('Language')}</Label>
              <SearchableSelect
                value={code}
                onValueChange={handleLanguageChange}
                items={languageItems}
                placeholder={t('Select language')}
                searchPlaceholder={t('Search languages...')}
                emptyMessage={t('No languages found')}
                triggerClassName="h-9 w-full text-[13px]"
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="video-subtitle-name" className="text-[13px]">
                {t('Name')}
              </Label>
              <Input
                id="video-subtitle-name"
                value={name}
                maxLength={128}
                aria-invalid={name.length > 0 && !nameValid}
                onChange={(e) => setName(e.target.value)}
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
                <Label htmlFor="video-subtitle-default" className="text-[13px]">
                  {t('Default subtitle')}
                </Label>
                <p className="mt-0.5 text-[12px] text-muted-foreground">
                  {t('Players turn this subtitle on automatically.')}
                </p>
              </div>
              <Switch
                id="video-subtitle-default"
                checked={isDefault}
                onCheckedChange={setIsDefault}
              />
            </div>
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => onOpenChange(false)}
              disabled={isPending}
            >
              {t('Cancel')}
            </Button>
            <Button onClick={handleSubmit} disabled={!canSubmit}>
              {isUpdate ? t('Update') : t('Create')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
      <StorageFileExplorerDialog
        open={explorerOpen}
        onOpenChange={setExplorerOpen}
        projectId={projectId}
        title={t('Select subtitle file')}
        description={t(
          'Choose a bucket, then pick or upload a .vtt or .srt file.',
        )}
        confirmLabel={t('Select')}
        onConfirm={(selection) => setSource(selection)}
      />
    </>
  )
}
