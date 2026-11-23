import { ConfirmNameDialog } from '@/components/global/shared/ConfirmNameDialog'
import { useDeleteDomain } from '@/lib/react-query/hooks'
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'
import type { Models } from '@appwrite.io/console'

interface DeleteDomainDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  region?: string
  rule: Models.ProxyRule
  onDeleteSuccess: () => void
}

export function DeleteDomainDialog({
  open,
  onOpenChange,
  projectId,
  region,
  rule,
  onDeleteSuccess,
}: DeleteDomainDialogProps) {
  const t = useT()
  const deleteDomainMutation = useDeleteDomain(projectId, region)

  const handleDelete = async () => {
    try {
      await deleteDomainMutation.mutateAsync(rule.$id)
      onDeleteSuccess()
    } catch (error: unknown) {
      toast.error(error.message || t('Failed to delete domain'))
    }
  }

  return (
    <ConfirmNameDialog
      open={open}
      onOpenChange={onOpenChange}
      title="Delete domain"
      description={
        <>
          {t('Are you sure you want to delete this domain?')}{' '}
          {t('This action cannot be undone.')}
        </>
      }
      confirmValue={rule.domain}
      confirmPlaceholder="Enter domain name"
      onConfirm={handleDelete}
      isConfirming={deleteDomainMutation.isPending}
    />
  )
}
