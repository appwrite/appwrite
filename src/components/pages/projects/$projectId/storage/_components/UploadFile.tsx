import { useState, useEffect, useRef } from 'react'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { IdInput } from '@/components/ui/id-input'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Upload, X, AlertTriangle } from 'lucide-react'
import { cn } from '@/lib/utils'
import type { Models } from '@appwrite.io/console'

interface UploadFileProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onUpload: (data: {
    fileId?: string
    file: File
    permissions?: string[]
  }) => void
  bucket?: Models.Bucket
  isLoading?: boolean
}

export function UploadFile({
  open,
  onOpenChange,
  onUpload,
  bucket,
  isLoading = false,
}: UploadFileProps) {
  const [fileId, setFileId] = useState<string | undefined>(undefined)
  const [file, setFile] = useState<File | null>(null)
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
    setFile(null)
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

  const validateFile = (fileToValidate: File): boolean => {
    const newErrors: Record<string, string> = {}

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
        newErrors.file = `Only ${bucket.allowedFileExtensions.join(', ')} files allowed`
      }
    }

    // Check file size
    if (
      bucket?.maximumFileSize &&
      fileToValidate.size > bucket.maximumFileSize
    ) {
      const maxSizeMB = (bucket.maximumFileSize / (1000 * 1000)).toFixed(2)
      newErrors.file = `File size exceeds maximum of ${maxSizeMB} MB`
    }

    setErrors(newErrors)
    return Object.keys(newErrors).length === 0
  }

  const handleFileSelect = (selectedFile: File) => {
    if (validateFile(selectedFile)) {
      setFile(selectedFile)
    }
  }

  const handleFileInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const selectedFile = e.target.files?.[0]
    if (selectedFile) {
      handleFileSelect(selectedFile)
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

    const droppedFile = e.dataTransfer.files?.[0]
    if (droppedFile) {
      handleFileSelect(droppedFile)
    }
  }

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()

    if (!file) {
      setErrors({ file: 'Please select a file to upload' })
      return
    }

    if (!validateFile(file)) {
      return
    }

    onUpload({
      fileId,
      file,
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
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 text-left">
          <DialogTitle>Create file</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            Upload a new file to this bucket.
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <form onSubmit={handleSubmit}>
          <div className="px-6 pb-4 pt-0 space-y-4">
            {/* File Upload */}
            <div className="space-y-2">
              <Label htmlFor="file-upload">
                File <span className="text-destructive">*</span>
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
                    {file ? file.name : 'Click to upload or drag and drop'}
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
              {file && (
                <div className="flex items-center gap-2 rounded-md border border-border bg-muted/30 p-2">
                  <span className="flex-1 truncate text-[12px] text-foreground">
                    {file.name} ({formatFileSize(file.size)})
                  </span>
                  <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="h-6 w-6 p-0"
                    onClick={() => {
                      setFile(null)
                      if (fileInputRef.current) {
                        fileInputRef.current.value = ''
                      }
                    }}
                  >
                    <X className="h-3.5 w-3.5" />
                  </Button>
                </div>
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

            {/* File Size Warning */}
            {bucket?.maximumFileSize &&
              file &&
              file.size > bucket.maximumFileSize && (
                <Alert>
                  <AlertTriangle className="h-4 w-4" />
                  <AlertDescription className="text-[12px]">
                    The maximum file upload size for this bucket is{' '}
                    {formatFileSize(bucket.maximumFileSize)}. You can adjust it
                    in your bucket settings.
                  </AlertDescription>
                </Alert>
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
            <Button type="submit" disabled={isLoading || !file}>
              Create
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
