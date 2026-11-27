import { toast } from 'sonner'
import { ConfirmActionDialog } from '@/components/global/shared/ConfirmActionDialog'
import type { DistributionArtifact } from '@/lib/appwrite/distribution'
import { useT } from '@/lib/i18n/translate'
import { useDeleteDistributionArtifact } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { closeDialogBeforeOverlayUnmount } from '@/lib/utils/overlay-lock'

type DeleteArtifactProps = {
  projectId: string
  appId: string
  artifact: DistributionArtifact | null
  onOpenChange: (open: boolean) => void
}

export function DeleteArtifact({
  projectId,
  appId,
  artifact,
  onOpenChange,
}: DeleteArtifactProps) {
  const t = useT()
  const remove = useDeleteDistributionArtifact(projectId)
  const [beforeName, afterName = ''] = t(
    'Delete {name}? Its file is removed and it can no longer be submitted. This action cannot be undone.',
  ).split('{name}')

  const handleDelete = () => {
    if (!artifact) return
    closeDialogBeforeOverlayUnmount(() => onOpenChange(false))
    remove.mutate(
      { appId, artifactId: artifact.$id },
      {
        onSuccess: () => toast.success(t('Artifact deleted')),
        onError: (error) =>
          toast.error(getErrorMessage(error, t('Failed to delete artifact'))),
      },
    )
  }

  return (
    <ConfirmActionDialog
      open={artifact !== null}
      onOpenChange={onOpenChange}
      title="Delete artifact"
      description={
        <>
          {beforeName}
          <strong>{artifact?.name}</strong>
          {afterName}
        </>
      }
      confirmLabel="Delete"
      confirmVariant="destructive"
      onConfirm={handleDelete}
      isConfirming={remove.isPending}
    />
  )
}
