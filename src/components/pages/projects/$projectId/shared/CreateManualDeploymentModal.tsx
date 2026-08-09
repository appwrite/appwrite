/**
 * Create deployment via manual .tar.gz upload.
 * Validates file type (.tar.gz only) and max size. Supports onProgress for upload UI.
 */

import { useState, useRef } from 'react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Upload, Loader2, FileArchive } from 'lucide-react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'
import { closeDialogBeforeOverlayUnmount } from '@/lib/utils/overlay-lock'
import { sdk } from '@/lib/appwrite/sdk'

export type CreateManualDeploymentResourceType = 'function' | 'site'

/** Default max upload size (10MB). Can be overridden by plan/env. */
export const DEFAULT_DEPLOYMENT_UPLOAD_MAX_BYTES = 10 * 1024 * 1024

export interface CreateManualDeploymentModalProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  resourceType: CreateManualDeploymentResourceType
  projectId: string
  resourceId: string
  onSuccess?: () => void
  /** Max file size in bytes; default 10MB */
  maxFileSizeBytes?: number
}

function isTarGzFile(file: File): boolean {
  const name = file.name?.toLowerCase() ?? ''
  return name.endsWith('.tar.gz') || name.endsWith('.tgz')
}

export function CreateManualDeploymentModal({
  open,
  onOpenChange,
  resourceType,
  projectId,
  resourceId,
  onSuccess,
  maxFileSizeBytes = DEFAULT_DEPLOYMENT_UPLOAD_MAX_BYTES,
}: CreateManualDeploymentModalProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const inputRef = useRef<HTMLInputElement>(null)
  const [file, setFile] = useState<File | null>(null)
  const [uploadProgress, setUploadProgress] = useState<number | null>(null)
  const [validationError, setValidationError] = useState<string | null>(null)

  const reset = () => {
    setFile(null)
    setUploadProgress(null)
    setValidationError(null)
    if (inputRef.current) {
      inputRef.current.value = ''
    }
  }

  const handleClose = (isOpen: boolean) => {
    if (!isOpen) reset()
    onOpenChange(isOpen)
  }

  const validateFile = (f: File): string | null => {
    if (!isTarGzFile(f)) {
      return t('Only .tar.gz files are allowed.')
    }
    if (f.size > maxFileSizeBytes) {
      const mb = (maxFileSizeBytes / (1024 * 1024)).toFixed(0)
      return `${t('File size exceeds')} ${mb}MB.`
    }
    return null
  }

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const chosen = e.target.files?.[0]
    setValidationError(null)
    if (!chosen) {
      setFile(null)
      return
    }
    const err = validateFile(chosen)
    if (err) {
      setValidationError(err)
      setFile(null)
      return
    }
    setFile(chosen)
  }

  const mutation = useMutation({
    mutationFn: async () => {
      if (!file) throw new Error('No file selected')
      const projectSdk = sdk.forProject(projectId)
      if (resourceType === 'function') {
        return await projectSdk.functions.createDeployment({
          functionId: resourceId,
          code: file,
          activate: true,
          onProgress: (progress) => {
            if (progress.chunksTotal && progress.chunksTotal > 0) {
              setUploadProgress(
                Math.round(
                  (progress.chunksUploaded / progress.chunksTotal) * 100,
                ),
              )
            }
          },
        })
      }
      return await projectSdk.sites.createDeployment({
        siteId: resourceId,
        code: file,
        activate: true,
        onProgress: (progress) => {
          if (progress.chunksTotal && progress.chunksTotal > 0) {
            setUploadProgress(
              Math.round(
                (progress.chunksUploaded / progress.chunksTotal) * 100,
              ),
            )
          }
        },
      })
    },
    onSuccess: () => {
      closeDialogBeforeOverlayUnmount(() => {
        handleClose(false)
      })
      const deployKey =
        resourceType === 'function'
          ? ['deployments', 'function', projectId, resourceId]
          : ['deployments', 'site', projectId, resourceId]
      queryClient.refetchQueries({ queryKey: deployKey })
      if (resourceType === 'site') {
        queryClient.invalidateQueries({
          queryKey: ['site', 'project', projectId, resourceId],
        })
      }
      toast.success(t('Deployment created successfully'))
      onSuccess?.()
    },
    onError: (err: Error) => {
      toast.error(err?.message ?? t('Failed to create deployment'))
      setUploadProgress(null)
    },
  })

  const handleSubmit = () => {
    if (!file) {
      setValidationError(t('Please select a .tar.gz file.'))
      return
    }
    const err = validateFile(file)
    if (err) {
      setValidationError(err)
      return
    }
    mutation.mutate()
  }

  const maxMb = (maxFileSizeBytes / (1024 * 1024)).toFixed(0)

  return (
    <Dialog open={open} onOpenChange={handleClose}>
      <DialogContent className="sm:max-w-lg p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>{t('Create manual deployment')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t('Upload a .tar.gz archive of your code. Maximum file size is')}{' '}
            {maxMb}
            MB.
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="px-6 pb-4 pt-0">
          <input
            ref={inputRef}
            type="file"
            accept=".tar.gz,.tgz,application/gzip"
            className="hidden"
            onChange={handleFileChange}
          />
          <div
            onClick={() => inputRef.current?.click()}
            className="flex flex-col items-center justify-center rounded-lg border border-dashed border-border bg-muted/20 py-8 px-4 cursor-pointer hover:bg-muted/30 transition-colors"
          >
            {file ? (
              <div className="flex items-center gap-2 text-[13px] text-foreground">
                <FileArchive className="h-5 w-5 text-muted-foreground" />
                <span className="font-medium truncate max-w-[240px]">
                  {file.name}
                </span>
                <span className="text-muted-foreground">
                  ({(file.size / 1024).toFixed(1)} KB)
                </span>
              </div>
            ) : (
              <>
                <Upload className="h-10 w-10 text-muted-foreground mb-2" />
                <p className="text-[13px] text-muted-foreground text-center">
                  {t('Click to select a .tar.gz file')}
                </p>
              </>
            )}
          </div>
          {validationError && (
            <p className="mt-2 text-[12px] text-destructive">
              {validationError}
            </p>
          )}
          {uploadProgress !== null && (
            <div className="mt-3 flex items-center gap-2">
              <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
              <span className="text-[13px] text-muted-foreground">
                {t('Uploading…')} {uploadProgress}%
              </span>
            </div>
          )}
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            variant="outline"
            onClick={() => handleClose(false)}
            disabled={mutation.isPending}
            className="h-9 text-[13px]"
          >
            {t('Cancel')}
          </Button>
          <Button
            onClick={handleSubmit}
            disabled={!file || mutation.isPending}
            className="h-9 text-[13px]"
          >
            {t('Create deployment')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
