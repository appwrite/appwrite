import { useEffect, useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { useQuery } from '@tanstack/react-query'
import { FileVideo } from 'lucide-react'
import { toast } from 'sonner'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  StorageFileExplorerDialog,
  type StorageFileSelection,
} from '@/components/global/shared/StorageFileExplorerDialog'
import { sdk } from '@/lib/appwrite/sdk'
import { useCreateVideo } from '@/lib/react-query/hooks/videos'
import { formatBytes } from '@/lib/utils/mock-data'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'

interface CreateVideoProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
}

export function CreateVideo({
  open,
  onOpenChange,
  projectId,
}: CreateVideoProps) {
  const t = useT()
  const navigate = useNavigate()
  const [name, setName] = useState('')
  const [source, setSource] = useState<StorageFileSelection | null>(null)
  const [explorerOpen, setExplorerOpen] = useState(false)
  const createMutation = useCreateVideo(projectId)

  useEffect(() => {
    if (open) return
    setName('')
    setSource(null)
  }, [open])

  const { data: sourceFile } = useQuery({
    queryKey: [
      'video-source-file',
      projectId,
      source?.bucketId,
      source?.fileId,
    ],
    queryFn: () =>
      sdk.forProject(projectId).storage.getFile({
        bucketId: source!.bucketId,
        fileId: source!.fileId,
      }),
    enabled: !!source,
    staleTime: 60 * 1000,
  })

  const isVideoFile =
    !sourceFile || sourceFile.mimeType.toLowerCase().startsWith('video/')

  const handleCreate = () => {
    if (!source) return
    createMutation.mutate(
      { bucketId: source.bucketId, fileId: source.fileId, name },
      {
        onSuccess: (video) => {
          toast.success(t('Video created. Downloading source...'))
          onOpenChange(false)
          navigate({
            to: '/projects/$projectId/videos/$videoId',
            params: { projectId, videoId: video.$id },
          })
        },
        onError: (error) => {
          toast.error(getErrorMessage(error) || t('Failed to create video'))
        },
      },
    )
  }

  return (
    <>
      <Dialog open={open} onOpenChange={onOpenChange}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Create video')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t(
                'Choose a video file from Storage. Appwrite downloads a working copy so you can encode renditions and stream it.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="space-y-4 px-6 pb-4 pt-4">
            <div className="space-y-2">
              <Label className="text-[13px]">{t('Source file')}</Label>
              {source ? (
                <div className="flex items-center gap-3 rounded-lg border border-border px-3 py-2.5">
                  <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
                    <FileVideo className="h-4 w-4" />
                  </div>
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-[13px] font-medium text-foreground">
                      {sourceFile?.name ?? source.fileId}
                    </p>
                    <p className="truncate text-[12px] text-muted-foreground">
                      {sourceFile
                        ? `${sourceFile.mimeType} · ${formatBytes(sourceFile.sizeOriginal)}`
                        : source.bucketId}
                    </p>
                  </div>
                  <Button
                    variant="outline"
                    size="sm"
                    className="h-8 text-[12px]"
                    onClick={() => setExplorerOpen(true)}
                  >
                    {t('Change')}
                  </Button>
                </div>
              ) : (
                <Button
                  variant="outline"
                  className="h-9 w-full justify-start gap-1.5 text-[13px] text-muted-foreground"
                  onClick={() => setExplorerOpen(true)}
                >
                  <FileVideo className="h-4 w-4" />
                  {t('Select file')}
                </Button>
              )}
              {!isVideoFile ? (
                <p className="text-[12px] text-amber-600 dark:text-amber-400">
                  {t(
                    'This file does not look like a video. The server may reject it.',
                  )}
                </p>
              ) : null}
            </div>
            <div className="space-y-2">
              <Label htmlFor="video-name" className="text-[13px]">
                {t('Name')}{' '}
                <span className="text-muted-foreground">({t('optional')})</span>
              </Label>
              <Input
                id="video-name"
                value={name}
                maxLength={128}
                onChange={(e) => setName(e.target.value)}
                placeholder={t('Defaults to the file name')}
                className="h-9 text-[13px]"
              />
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
              onClick={handleCreate}
              disabled={!source || createMutation.isPending}
            >
              {t('Create')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
      <StorageFileExplorerDialog
        open={explorerOpen}
        onOpenChange={setExplorerOpen}
        projectId={projectId}
        title={t('Select source video')}
        description={t('Choose a bucket, then pick or upload a video file.')}
        confirmLabel={t('Select')}
        onConfirm={(selection) => {
          setSource(selection)
        }}
      />
    </>
  )
}
