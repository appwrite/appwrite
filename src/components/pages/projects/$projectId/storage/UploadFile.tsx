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
import type { Models } from '@appwrite.io/console'

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
        return `Only ${bucket.allowedFileExtensions.join(', ')} files allowed`
      }
    }

    // Check file size
    if (
      bucket?.maximumFileSize &&
      fileToValidate.size > bucket.maximumFileSize
    ) {
      const maxSizeMB = (bucket.maximumFileSize / (1000 * 1000)).toFixed(2)
      return `File size exceeds maximum of ${maxSizeMB} MB`
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
      setErrors({ file: 'Please select at least one file to upload' })
      return
    }

    onUpload({
      fileId: files.length === 1 ? fileId : undefined,
      files,
    })
  }

  const formatFileSize = (bytes: number): string => {
    if (bytes === 0) return '0 Bytes'
    const k = 1000
    const sizes = ['Bytes', 'KB', 'MB', 'GB']
    const i = Math.floor(Math.log(bytes) / Math.log(k))
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]
  }

  return (
    <Dialog open={open} onOpenChange={handleOpenChange}>
      <DialogContent className="sm:max-w-md p-0 max-h-[85dvh] overflow-hidden">
        <DialogHeader className="px-6 pt-6 pb-4 text-left">
          <DialogTitle>
            {files.length > 1 ? 'Create files' : 'Create file'}
          </DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            Upload files to this bucket.
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <form onSubmit={handleSubmit}>
          <div className="px-6 pb-4 pt-0 space-y-4 max-h-[60dvh] overflow-y-auto">
            {/* File Upload */}
            <div className="space-y-2">
              <Label htmlFor="file-upload">
                Files <span className="text-destructive">*</span>
              </Label>
              <div
                ref={dropZoneRef}
                onDragOver={handleDragOver}
                onDragLeave={handleDragLeave}
                onDrop={handleDrop}
                className={cn(
                  'border-2 border-dashed rounded-lg p-6 text-center transition-colors',
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
                  className="cursor-pointer flex flex-col items-center gap-2"
                >
                  <Upload className="h-8 w-8 text-muted-foreground" />
                  <span className="text-[13px] text-foreground">
                    {files.length === 0
                      ? 'Click to upload or drag and drop'
                      : files.length === 1
                        ? files[0].name
                        : `${files.length} files selected`}
                  </span>
                  {bucket?.allowedFileExtensions &&
                    bucket.allowedFileExtensions.length > 0 && (
                      <span className="text-[12px] text-muted-foreground">
                        Allowed: {bucket.allowedFileExtensions.join(', ')}
                      </span>
                    )}
                  {bucket?.maximumFileSize && (
                    <span className="text-[12px] text-muted-foreground">
                      Max size: {formatFileSize(bucket.maximumFileSize)}
                    </span>
                  )}
                </label>
              </div>
              {files.length > 0 && (
                <div className="space-y-2 max-h-40 overflow-y-auto pr-1">
                  {files.map((selectedFile, index) => (
                    <div
                      key={`${selectedFile.name}-${selectedFile.size}-${index}`}
                      className="flex items-center gap-2 rounded-md border border-border bg-muted/30 p-2"
                    >
                      <span className="flex-1 truncate text-[12px] text-foreground">
                        {selectedFile.name} ({formatFileSize(selectedFile.size)}
                        )
                      </span>
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-6 w-6 p-0"
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
                    {`Skipped ${invalidFiles.length} file${
                      invalidFiles.length > 1 ? 's' : ''
                    }: ${invalidFiles
                      .slice(0, 3)
                      .map((file) => `${file.name} (${file.reason})`)
                      .join(', ')}${
                      invalidFiles.length > 3 ? ', and more.' : '.'
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
                <Label htmlFor="file-id">File ID</Label>
                <IdInput
                  id="file-id"
                  value={fileId}
                  onChange={setFileId}
                  maxLength={36}
                  disabled={isLoading}
                  placeholder="Leave blank to auto-generate"
                />
              </div>
            )}
            {files.length > 1 && (
              <p className="text-[12px] text-muted-foreground">
                File IDs will be auto-generated for bulk uploads.
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
              Cancel
            </Button>
            <Button type="submit" disabled={isLoading || files.length === 0}>
              Create
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
