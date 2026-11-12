import { useState, useEffect } from 'react'
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
import { useT } from '@/lib/i18n/translate'

interface CreateDomainDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onCreate: (domain: string) => void
  isLoading?: boolean
}

export function CreateDomainDialog({
  open,
  onOpenChange,
  onCreate,
  isLoading = false,
}: CreateDomainDialogProps) {
  const t = useT()
  const [domain, setDomain] = useState('')

  // Reset form when dialog closes
  useEffect(() => {
    if (!open) {
      setDomain('')
    }
  }, [open])

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (!domain.trim()) return
    onCreate(domain.trim().toLowerCase())
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>{t('Add Domain')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t('Enter the domain name you want to add to your organization.')}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <form onSubmit={handleSubmit}>
          <div className="px-6 pb-4 pt-0">
            <div className="space-y-2">
              <Label htmlFor="domain">{t('Domain')}</Label>
              <Input
                id="domain"
                value={domain}
                onChange={(e) => setDomain(e.target.value)}
                placeholder="example.com"
                disabled={isLoading}
                autoFocus
              />
              <p className="text-[12px] text-muted-foreground">
                {t('Enter the domain name without protocol (e.g., example.com)')}
              </p>
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
            <Button type="submit" disabled={isLoading || !domain.trim()}>
              {t('Add Domain')}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
