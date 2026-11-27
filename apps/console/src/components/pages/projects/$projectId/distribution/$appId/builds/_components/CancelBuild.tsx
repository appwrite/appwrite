import { toast } from 'sonner'
import { ConfirmActionDialog } from '@/components/global/shared/ConfirmActionDialog'
import type { DistributionBuild } from '@/lib/appwrite/distribution'
import { useT } from '@/lib/i18n/translate'
import { useUpdateDistributionBuildStatus } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'

type CancelBuildProps = {
  projectId: string
  build: DistributionBuild | null
  onOpenChange: (open: boolean) => void
}

export function CancelBuild({
  projectId,
  build,
  onOpenChange,
}: CancelBuildProps) {
  const t = useT()
  const cancelBuild = useUpdateDistributionBuildStatus(projectId)

  const handleConfirm = () => {
    if (!build) return
    cancelBuild.mutate(
      { appId: build.appId, buildId: build.$id, status: 'canceled' },
      {
        onSuccess: () => {
          toast.success(t('Build canceled'))
          onOpenChange(false)
        },
        onError: (error) =>
          toast.error(getErrorMessage(error, t('Failed to cancel build'))),
      },
    )
  }

  return (
    <ConfirmActionDialog
      open={!!build}
      onOpenChange={onOpenChange}
      title="Cancel build"
      description={t(
        'The build stops and its logs are kept. Start a new build to try again.',
      )}
      confirmLabel="Cancel build"
      confirmVariant="destructive"
      onConfirm={handleConfirm}
      isConfirming={cancelBuild.isPending}
    />
  )
}
