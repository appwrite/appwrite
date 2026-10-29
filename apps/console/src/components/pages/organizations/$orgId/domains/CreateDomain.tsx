import { useState, useEffect } from 'react'
import { useQueryClient } from '@tanstack/react-query'
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
import { useCreateOrganizationDomain } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { buildConsoleUrl, openInNewTab } from '@/lib/utils/context-menu'
import { useT } from '@/lib/i18n/translate'

interface CreateDomainDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onCreate?: (domain: string) => void
  orgId?: string
  isLoading?: boolean
  openCreatedInNewTab?: boolean
}

export function CreateDomainDialog({
  open,
  onOpenChange,
  onCreate,
  orgId,
  isLoading = false,
  openCreatedInNewTab = false,
}: CreateDomainDialogProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const createDomainMutation = useCreateOrganizationDomain(orgId)
  const [domain, setDomain] = useState('')
  const submitting = isLoading || createDomainMutation.isPending

  // Reset form when dialog closes
  useEffect(() => {
    if (!open) {
      setDomain('')
    }
  }, [open])

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    const value = domain.trim().toLowerCase()
    if (!value) return
    if (onCreate) {
      onCreate(value)
      return
    }
    if (!orgId) return
    try {
      const createdDomain = await createDomainMutation.mutateAsync(value)
      onOpenChange(false)
      toast.success(`${createdDomain.domain} ${t('has been created')}`)
      await queryClient.refetchQueries({
        queryKey: ['domains', 'organization', orgId],
      })
      if (openCreatedInNewTab && createdDomain.$id) {
        openInNewTab(
          buildConsoleUrl(
            `/organizations/${orgId}/domains/${createdDomain.$id}`,
          ),
        )
      }
    } catch (error) {
      toast.error(getErrorMessage(error))
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className="sm:max-w-md p-0"
>
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>{t('Add Domain')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t('Enter the domain name you want to add to your organization.')}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />

        <form
          onSubmit={handleSubmit}
>
          <div className="px-6 pb-4 pt-0">
            <div className="space-y-2">
              <Label htmlFor="domain">{t('Domain')}</Label>
              <Input
                id="domain"
                value={domain}
                onChange={(e) => setDomain(e.target.value)}
                placeholder="example.com"
                disabled={submitting}
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
              disabled={submitting}
>
              {t('Cancel')}
            </Button>
            <Button
              type="submit"
              disabled={submitting || !domain.trim()}
>
              {t('Add Domain')}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
