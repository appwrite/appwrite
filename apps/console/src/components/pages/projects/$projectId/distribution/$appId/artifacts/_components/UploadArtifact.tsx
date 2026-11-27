import { useRef, useState } from 'react'
import type { ChangeEvent, DragEvent } from 'react'
import { FileUp, Package, X } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Label } from '@/components/ui/label'
import { analyticsAttrs } from '@/lib/analytics-actions'
import type { DistributionTarget } from '@/lib/appwrite/distribution'
import {
  ARTIFACT_EXTENSIONS,
  isArtifactFile,
  startArtifactUpload,
} from '@/lib/distribution/uploads'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { formatBinaryBytes } from '@/lib/utils/byte-display-unit'
import { PlatformIcon, platformLabel } from '../../../_components/platform'

type UploadArtifactProps = {
  projectId: string
  appId: string
  targets: DistributionTarget[]
  open: boolean
  onOpenChange: (open: boolean) => void
}

export function UploadArtifact({
  projectId,
  appId,
  targets,
  open,
  onOpenChange,
}: UploadArtifactProps) {
  const t = useT()
  const inputRef = useRef<HTMLInputElement>(null)
  const [file, setFile] = useState<File | null>(null)
  const [rejected, setRejected] = useState(false)
  const [dragging, setDragging] = useState(false)

  const reset = () => {
    setFile(null)
    setRejected(false)
    setDragging(false)
  }

  const handleOpenChange = (next: boolean) => {
    if (!next) reset()
    onOpenChange(next)
  }

  const choose = (selected: File | undefined) => {
    if (inputRef.current) inputRef.current.value = ''
    if (!selected) return
    if (!isArtifactFile(selected.name)) {
      setFile(null)
      setRejected(true)
      return
    }
    setRejected(false)
    setFile(selected)
  }

  const handleInput = (event: ChangeEvent<HTMLInputElement>) =>
    choose(event.target.files?.[0])

  const handleDrop = (event: DragEvent<HTMLDivElement>) => {
    event.preventDefault()
    setDragging(false)
    choose(event.dataTransfer.files?.[0])
  }

  const handleUpload = () => {
    if (!file) return
    startArtifactUpload(projectId, appId, file)
    handleOpenChange(false)
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-lg p-0 max-h-[90dvh] overflow-y-auto">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Upload artifact')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Upload an IPA for iOS or an AAB for Android. Appwrite inspects the binary and matches it to a target by its bundle ID or package name.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="space-y-5 px-6 py-4">
          <div className="space-y-2">
            <Label
              htmlFor="distribution-artifact-file"
              className="text-[12px] font-medium"
            >
              {t('File')}
            </Label>
            <input
              ref={inputRef}
              id="distribution-artifact-file"
              type="file"
              accept={ARTIFACT_EXTENSIONS.join(',')}
              className="sr-only"
              onChange={handleInput}
            />
            {file ? (
              <div className="flex items-center gap-3 rounded-lg border border-border bg-muted/30 px-3 py-2.5">
                <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-border bg-background">
                  <Package className="h-4 w-4 text-muted-foreground" />
                </div>
                <div className="min-w-0 flex-1">
                  <p
                    className="truncate text-[13px] font-medium text-foreground"
                    dir="auto"
                    title={file.name}
                  >
                    {file.name}
                  </p>
                  <p className="text-[12px] tabular-nums text-muted-foreground">
                    {formatBinaryBytes(file.size)}
                  </p>
                </div>
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  className="h-8 w-8 shrink-0 p-0 text-muted-foreground hover:text-foreground"
                  onClick={reset}
                  aria-label={`${t('Remove')} ${file.name}`}
                >
                  <X className="h-4 w-4" />
                </Button>
              </div>
            ) : (
              <div
                onDragOver={(event) => {
                  event.preventDefault()
                  setDragging(true)
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={handleDrop}
                className={cn(
                  'rounded-lg border-2 border-dashed px-4 py-5 text-center transition-colors',
                  dragging
                    ? 'border-primary bg-primary/5'
                    : 'border-border bg-muted/30',
                  rejected && 'border-destructive',
                )}
              >
                <FileUp className="mx-auto h-6 w-6 text-muted-foreground" />
                <p className="mt-2 text-[13px] font-medium text-foreground">
                  {t('Drop a file here')}
                </p>
                <p className="mt-0.5 text-[12px] text-muted-foreground">
                  {ARTIFACT_EXTENSIONS.join(', ')}
                </p>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="mt-3 h-8 text-[13px]"
                  onClick={() => inputRef.current?.click()}
                >
                  {t('Choose file')}
                </Button>
              </div>
            )}
            {rejected ? (
              <p className="text-[12px] text-destructive" role="alert">
                {t('Choose an .ipa or .aab file.')}
              </p>
            ) : (
              <p className="text-[12px] text-muted-foreground">
                {t(
                  'The upload runs in the background while you keep working. Reloading the page stops it.',
                )}
              </p>
            )}
          </div>
          <div className="space-y-2">
            <p className="text-[12px] font-medium text-foreground">
              {t('Targets')}
            </p>
            {targets.length > 0 ? (
              <ul className="divide-y divide-border rounded-lg border border-border">
                {targets.map((target) => (
                  <li
                    key={target.$id}
                    className="flex items-center gap-2 px-3 py-2"
                  >
                    <PlatformIcon
                      platform={target.platform}
                      className="shrink-0 text-muted-foreground"
                    />
                    <span
                      className="min-w-0 truncate font-mono text-[12px] text-foreground"
                      dir="ltr"
                      title={target.identifier}
                    >
                      {target.identifier}
                    </span>
                    <span className="ms-auto shrink-0 text-[12px] text-muted-foreground">
                      {platformLabel(target.platform)}
                    </span>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="text-[12px] text-muted-foreground">
                {t(
                  'This app has no targets yet. An artifact that matches no target is marked invalid.',
                )}
              </p>
            )}
          </div>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button variant="outline" onClick={() => handleOpenChange(false)}>
            {t('Cancel')}
          </Button>
          <Button
            onClick={handleUpload}
            disabled={!file}
            {...analyticsAttrs('distribution-upload-artifact')}
          >
            {t('Upload')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
