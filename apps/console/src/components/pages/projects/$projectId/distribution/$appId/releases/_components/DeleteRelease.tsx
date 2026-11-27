import { toast } from 'sonner'
import { ConfirmActionDialog } from '@/components/global/shared/ConfirmActionDialog'
import type { DistributionRelease } from '@/lib/appwrite/distribution'
import { useT } from '@/lib/i18n/translate'
import { useDeleteDistributionRelease } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'

type DeleteReleaseProps = {
  projectId: string
  release: DistributionRelease | null
  onOpenChange: (open: boolean) => void
  onDeleted?: () => void
}

export function DeleteRelease({
  projectId,
  release,
  onOpenChange,
  onDeleted,
}: DeleteReleaseProps) {
  const t = useT()
  const deleteRelease = useDeleteDistributionRelease(projectId)
  const [beforeVersion, afterVersion = ''] = t(
    'Delete release {version}? Its submission history is deleted with it. Versions already in the stores stay there. This action cannot be undone.',
  ).split('{version}')

  const handleConfirm = async () => {
    if (!release) return
    try {
      await deleteRelease.mutateAsync({
        appId: release.appId,
        releaseId: release.$id,
      })
    } catch (error) {
      onOpenChange(false)
      toast.error(getErrorMessage(error, t('Failed to delete release')))
      return
    }
    toast.success(t('Release deleted'))
    onOpenChange(false)
    onDeleted?.()
  }

  return (
    <ConfirmActionDialog
      open={release !== null}
      onOpenChange={onOpenChange}
      title="Delete release"
      description={
        <>
          {beforeVersion}
          <strong dir="ltr">{release?.version}</strong>
          {afterVersion}
        </>
      }
      confirmLabel="Delete"
      confirmVariant="destructive"
      onConfirm={() => void handleConfirm()}
      isConfirming={deleteRelease.isPending}
    />
  )
}
