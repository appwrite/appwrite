import { useState, useEffect } from 'react'
import { useSearch } from '@tanstack/react-router'
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
import { useCreateDomain } from '@/lib/react-query/hooks'
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'
import type { Models } from '@appwrite.io/console'

interface AddDomainDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  region?: string
  onCreateSuccess: (rule: Models.ProxyRule) => void
}

export function AddDomainDialog({
  open,
  onOpenChange,
  projectId,
  region,
  onCreateSuccess,
}: AddDomainDialogProps) {
  const t = useT()
  const search = useSearch({ strict: false })
  const createDomainMutation = useCreateDomain(projectId, region)
  const [domainName, setDomainName] = useState('')
  const [isSubmitting, setIsSubmitting] = useState(false)

  // Pre-fill domain from URL query parameter
  useEffect(() => {
    const searchParams = search as { domain?: string }
    if (open && searchParams?.domain) {
      setDomainName(searchParams.domain)
    }
  }, [open, search])

  // Reset form when dialog closes
  useEffect(() => {
    if (!open) {
      setDomainName('')
      setIsSubmitting(false)
    }
  }, [open])

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!domainName.trim()) {
      toast.error(t('Domain is required'))
      return
    }

    setIsSubmitting(true)
    try {
      const rule = await createDomainMutation.mutateAsync(
        domainName.trim().toLowerCase(),
      )

      onCreateSuccess(rule)
    } catch (error) {
      toast.error(
        error instanceof Error ? error.message : t('Failed to add domain'),
      )
      setIsSubmitting(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className="sm:max-w-md p-0"
>
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>{t('Add domain')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t('Enter the domain name you want to use for your API endpoint.')}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <form
          onSubmit={handleSubmit}
>
          <div className="px-6 pb-4 pt-0">
            <div className="space-y-2">
              <Label htmlFor="domain" className="text-[12px] font-medium">
                {t('Domain')} <span className="text-destructive">*</span>
              </Label>
              <Input
                id="domain"
                placeholder="appwrite.example.com"
                value={domainName}
                onChange={(e) => setDomainName(e.target.value)}
                autoFocus
                disabled={isSubmitting}
              />
            </div>
          </div>

          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
              disabled={isSubmitting}
>
              {t('Cancel')}
            </Button>
            <Button
              type="submit"
              disabled={isSubmitting || !domainName.trim()}
            >
              {t('Add')}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
