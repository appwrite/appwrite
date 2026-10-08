import { useState, useEffect, useRef } from 'react'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Label } from '@/components/ui/label'
import { IdInput } from '@/components/ui/id-input'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Upload, X, AlertTriangle } from 'lucide-react'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import type { Models } from '@appwrite.io/console'
import { formatDecimalBytes, toByteCount } from '@/lib/utils/byte-display-unit'

interface UploadFileDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onUpload: (data: {
    fileId?: string
    files: File[]
    permissions?: string[]
  }) => void
  bucket?: Models.Bucket
  isLoading?: boolean
}

export function UploadFileDialog({
  open,
  onOpenChange,
  onUpload,
  bucket,
  isLoading = false,
}: UploadFileDialogProps) {
  const t = useT()
  const [fileId, setFileId] = useState<string | undefined>(undefined)
  const [files, setFiles] = useState<File[]>([])
  const [invalidFiles, setInvalidFiles] = useState<
    { name: string; reason: string }[]
  >([])
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [isDragging, setIsDragging] = useState(false)
  const fileInputRef = useRef<HTMLInputElement>(null)
  const dropZoneRef = useRef<HTMLDivElement>(null)

  const handleOpenChange = (newOpen: boolean) => {
    if (!isLoading) {
      onOpenChange(newOpen)
      if (!newOpen) {
        resetForm()
      }
    }
  }

  const resetForm = () => {
    setFileId(undefined)
    setFiles([])
    setInvalidFiles([])
    setErrors({})
    setIsDragging(false)
    if (fileInputRef.current) {
      fileInputRef.current.value = ''
    }
  }

  useEffect(() => {
    if (!open) {
      resetForm()
    }
  }, [open])

  const getFileValidationError = (fileToValidate: File): string | null => {
    // Check file extension if allowed extensions are set
    if (
      bucket?.allowedFileExtensions &&
      bucket.allowedFileExtensions.length > 0
    ) {
      const fileExtension = fileToValidate.name.split('.').pop()?.toLowerCase()
      if (
        !fileExtension ||
        !bucket.allowedFileExtensions.includes(fileExtension)
      ) {
        return `${t('Only')} ${bucket.allowedFileExtensions.join(', ')} ${t('files allowed')}`
      }
    }

    // Check file size
    if (
      bucket?.maximumFileSize &&
      fileToValidate.size > toByteCount(bucket.maximumFileSize)
    ) {
      const maxSizeMB = (
        toByteCount(bucket.maximumFileSize) /
        (1000 * 1000)
      ).toFixed(2)
      return `${t('File size exceeds maximum of')} ${maxSizeMB} MB`
    }

    return null
  }

  const validateFiles = (selectedFiles: File[]) => {
    const valid: File[] = []
    const invalid: { name: string; reason: string }[] = []

    selectedFiles.forEach((file) => {
      const error = getFileValidationError(file)
      if (error) {
        invalid.push({ name: file.name, reason: error })
      } else {
        valid.push(file)
      }
    })

    return { valid, invalid }
  }

  const handleFileSelect = (selectedFiles: File[]) => {
    const { valid, invalid } = validateFiles(selectedFiles)
    setFiles(valid)
    setInvalidFiles(invalid)
    setErrors({})
    if (valid.length !== 1) {
      setFileId(undefined)
    }
  }

  const handleFileInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const selectedFiles = Array.from(e.target.files ?? [])
    if (selectedFiles.length > 0) {
      handleFileSelect(selectedFiles)
    }
  }

  const handleDragOver = (e: React.DragEvent) => {
    e.preventDefault()
    e.stopPropagation()
    setIsDragging(true)
  }

  const handleDragLeave = (e: React.DragEvent) => {
    e.preventDefault()
    e.stopPropagation()
    setIsDragging(false)
  }

  const handleDrop = (e: React.DragEvent) => {
    e.preventDefault()
    e.stopPropagation()
    setIsDragging(false)

    const droppedFiles = Array.from(e.dataTransfer.files ?? [])
    if (droppedFiles.length > 0) {
      handleFileSelect(droppedFiles)
    }
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()

    if (files.length === 0) {
      setErrors({ file: t('Please select at least one file to upload') })
      return
    }

    onUpload({
      fileId: files.length === 1 ? fileId : undefined,
      files,
    })
  }

  const formatFileSize = (bytes: number | bigint): string => {
    return formatDecimalBytes(bytes)
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="min-w-0 sm:max-w-md p-0 max-h-[85dvh] overflow-hidden">
        <DialogHeader className="px-6 pt-6 pb-4 text-start">
          <DialogTitle>
            {files.length > 1 ? t('Create files') : t('Create file')}
          </DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t('Upload files to this bucket.')}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <form onSubmit={handleSubmit} className="min-w-0">
          <div className="min-w-0 space-y-4 overflow-x-hidden overflow-y-auto px-6 pb-4 pt-0 max-h-[60dvh]">
            {/* File Upload */}
            <div className="min-w-0 space-y-2">
              <Label htmlFor="file-upload">
                {t('Files')} <span className="text-destructive">*</span>
              </Label>
              <div
                ref={dropZoneRef}
                onDragOver={handleDragOver}
                onDragLeave={handleDragLeave}
                onDrop={handleDrop}
                className={cn(
                  'min-w-0 overflow-hidden border-2 border-dashed rounded-lg p-6 text-center transition-colors',
                  isDragging
                    ? 'border-primary bg-primary/5'
                    : 'border-border bg-muted/30',
                  errors.file && 'border-destructive',
                )}
              >
                <input
                  ref={fileInputRef}
                  id="file-upload"
                  type="file"
                  multiple
                  onChange={handleFileInputChange}
                  className="hidden"
                  disabled={isLoading}
                />
                <label
                  htmlFor="file-upload"
                  className="flex w-full min-w-0 cursor-pointer flex-col items-center gap-2 px-1"
                >
                  <Upload className="h-8 w-8 shrink-0 text-muted-foreground" />
                  <span
                    className="block w-full min-w-0 max-w-full overflow-hidden text-ellipsis whitespace-nowrap text-center text-[13px] text-foreground"
                    title={
                      files.length === 1 ? files[0].name : undefined
                    }
                  >
                    {files.length === 0
                      ? t('Click to upload or drag and drop')
                      : files.length === 1
                        ? files[0].name
                        : `${files.length} ${t('files selected')}`}
                  </span>
                  {bucket?.allowedFileExtensions &&
                    bucket.allowedFileExtensions.length > 0 && (
                      <span className="text-[12px] text-muted-foreground">
                        {t('Allowed:')} {bucket.allowedFileExtensions.join(', ')}
                      </span>
                    )}
                  {bucket?.maximumFileSize && (
                    <span className="text-[12px] text-muted-foreground">
                      {t('Max size:')} {formatFileSize(bucket.maximumFileSize)}
                    </span>
                  )}
                </label>
              </div>
              {files.length > 0 && (
                <div className="min-w-0 space-y-2 max-h-40 overflow-x-hidden overflow-y-auto pe-1">
                  {files.map((selectedFile, index) => (
                    <div
                      key={`${selectedFile.name}-${selectedFile.size}-${index}`}
                      className="grid min-w-0 grid-cols-[minmax(0,1fr)_auto_auto] items-center gap-2 rounded-md border border-border bg-muted/30 px-2.5 py-2"
                    >
                      <span
                        className="min-w-0 overflow-hidden text-ellipsis whitespace-nowrap text-start text-[12px] font-medium leading-snug text-foreground"
                        title={selectedFile.name}
                      >
                        {selectedFile.name}
                      </span>
                      <span className="shrink-0 whitespace-nowrap text-end text-[12px] text-muted-foreground tabular-nums">
                        {formatFileSize(selectedFile.size)}
                      </span>
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-7 w-7 shrink-0 p-0"
                        onClick={() => {
                          setFiles((prev) =>
                            prev.filter((_, fileIndex) => fileIndex !== index),
                          )
                          if (fileInputRef.current) {
                            fileInputRef.current.value = ''
                          }
                        }}
                      >
                        <X className="h-3.5 w-3.5" />
                      </Button>
                    </div>
                  ))}
                </div>
              )}
              {invalidFiles.length > 0 && (
                <Alert>
                  <AlertTriangle className="h-4 w-4" />
                  <AlertDescription className="text-[12px]">
                    {`${invalidFiles.length > 1 ? t('Skipped files:') : t('Skipped file:')} ${invalidFiles
                      .slice(0, 3)
                      .map((file) => `${file.name} (${file.reason})`)
                      .join(', ')}${
                      invalidFiles.length > 3 ? `, ${t('and more.')}` : '.'
                    }`}
                  </AlertDescription>
                </Alert>
              )}
              {errors.file && (
                <Alert variant="destructive">
                  <AlertTriangle className="h-4 w-4" />
                  <AlertDescription className="text-[12px]">
                    {errors.file}
                  </AlertDescription>
                </Alert>
              )}
            </div>

            {/* File ID */}
            {files.length === 1 && (
              <div className="space-y-2">
                <Label htmlFor="file-id">{t('File ID')}</Label>
                <IdInput
                  id="file-id"
                  value={fileId}
                  onChange={setFileId}
                  maxLength={36}
                  disabled={isLoading}
                  placeholder={t('Leave blank to auto-generate')}
                />
              </div>
            )}
            {files.length > 1 && (
              <p className="border-t border-border pt-3 text-[12px] leading-relaxed text-muted-foreground">
                {t('File IDs will be auto-generated for bulk uploads.')}
              </p>
            )}
          </div>

          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => handleOpenChange(false)}
              disabled={isLoading}
            >
              {t('Cancel')}
            </Button>
            <Button type="submit" disabled={isLoading || files.length === 0}>
              {t('Create')}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
