import { toast } from 'sonner'
import { ConfirmActionDialog } from '@/components/global/shared/ConfirmActionDialog'
import type { DistributionBuild } from '@/lib/appwrite/distribution'
import { useT } from '@/lib/i18n/translate'
import { useDeleteDistributionBuild } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'

type DeleteBuildProps = {
  projectId: string
  build: DistributionBuild | null
  onOpenChange: (open: boolean) => void
  onDeleted?: () => void
}

export function DeleteBuild({
  projectId,
  build,
  onOpenChange,
  onDeleted,
}: DeleteBuildProps) {
  const t = useT()
  const deleteBuild = useDeleteDistributionBuild(projectId)

  const handleConfirm = () => {
    if (!build) return
    deleteBuild.mutate(
      { appId: build.appId, buildId: build.$id },
      {
        onSuccess: () => {
          toast.success(t('Build deleted'))
          onOpenChange(false)
          onDeleted?.()
        },
        onError: (error) =>
          toast.error(getErrorMessage(error, t('Failed to delete build'))),
      },
    )
  }

  return (
    <ConfirmActionDialog
      open={!!build}
      onOpenChange={onOpenChange}
      title="Delete build"
      description={t(
        'Its logs are deleted, and so is its artifact when no submission uses it. This action cannot be undone.',
      )}
      confirmLabel="Delete"
      confirmVariant="destructive"
      onConfirm={handleConfirm}
      isConfirming={deleteBuild.isPending}
    />
  )
}
