import { useState, useEffect, useRef } from 'react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Upload } from 'lucide-react'
import { useT } from '@/lib/i18n/translate'

interface ImportZoneDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onImport: (content: string) => void
  isLoading?: boolean
}

const MAX_FILE_SIZE = 5 * 1024 * 1024 // 5MB

export function ImportZoneDialog({
  open,
  onOpenChange,
  onImport,
  isLoading = false,
}: ImportZoneDialogProps) {
  const t = useT()
  const [file, setFile] = useState<File | null>(null)
  const [error, setError] = useState<string>('')
  const fileInputRef = useRef<HTMLInputElement>(null)

  // Reset form when dialog closes
  useEffect(() => {
    if (!open) {
      setFile(null)
      setError('')
      if (fileInputRef.current) {
        fileInputRef.current.value = ''
      }
    }
  }, [open])

  const handleFileChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const selectedFile = e.target.files?.[0]
    setError('')

    if (!selectedFile) {
      setFile(null)
      return
    }

    // Check file type
    if (!selectedFile.name.endsWith('.txt')) {
      setError(t('Please select a .txt file'))
      setFile(null)
      return
    }

    // Check file size
    if (selectedFile.size > MAX_FILE_SIZE) {
      setError(`${t('File size must be less than')} ${MAX_FILE_SIZE / 1000 / 1000}MB`)
      setFile(null)
      return
    }

    setFile(selectedFile)
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!file) return

    try {
      const content = await file.text()
      onImport(content)
    } catch {
      setError(t('Failed to read file. Please try again.'))
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>{t('Import Zone File')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Upload a DNS zone file (.txt format) to import DNS records. Maximum file size is 5MB.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <form onSubmit={handleSubmit}>
          <div className="px-6 pb-4 pt-0">
            <div className="space-y-2">
              <Label htmlFor="zone-file">{t('Zone File')}</Label>
              <div className="flex items-center gap-2">
                <input
                  ref={fileInputRef}
                  id="zone-file"
                  type="file"
                  accept=".txt"
                  onChange={handleFileChange}
                  disabled={isLoading}
                  className="hidden"
                />
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => fileInputRef.current?.click()}
                  disabled={isLoading}
                  className="gap-1.5"
                >
                  <Upload className="h-4 w-4" />
                  {t('Choose File')}
                </Button>
                {file && (
                  <span className="text-[13px] text-muted-foreground">
                    {file.name}
                  </span>
                )}
              </div>
              {error && <p className="text-[12px] text-destructive">{error}</p>}
              {file && !error && (
                <p className="text-[12px] text-muted-foreground">
                  {t('File selected:')} {file.name} (
                  {(file.size / 1000).toFixed(2)} KB)
                </p>
              )}
            </div>
          </div>

          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => onOpenChange(false)}
              disabled={isLoading}
            >
              {t('Cancel')}
            </Button>
            <Button type="submit" disabled={isLoading || !file || !!error}>
              {t('Import')}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
