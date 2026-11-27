import { toast } from 'sonner'
import { ConfirmActionDialog } from '@/components/global/shared/ConfirmActionDialog'
import type {
  DistributionSubmission,
  DistributionSubmissionStatusRequest,
} from '@/lib/appwrite/distribution'
import { useT } from '@/lib/i18n/translate'
import { useUpdateDistributionSubmissionStatus } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'

export type SubmissionControl = 'cancel' | 'halt' | 'resume' | 'publish'

const CONTROL_STATUSES: Record<
  SubmissionControl,
  DistributionSubmissionStatusRequest
> = {
  cancel: 'canceled',
  halt: 'halted',
  resume: 'published',
  publish: 'published',
}

export const CONTROL_LABELS: Record<SubmissionControl, string> = {
  cancel: 'Cancel submission',
  halt: 'Pause rollout',
  resume: 'Resume rollout',
  publish: 'Release to the store',
}

const CONTROL_DESCRIPTIONS: Record<SubmissionControl, string> = {
  cancel:
    'Appwrite stops this submission and withdraws it from review. You can submit the release again later.',
  halt: 'The store stops offering this version to more users. Users who already updated keep it. You can resume the rollout later.',
  resume:
    'The store continues offering this version from the current rollout percentage.',
  publish:
    'The store makes this approved version available to users. A released version cannot be withdrawn.',
}

const CONTROL_CONFIRM_LABELS: Record<SubmissionControl, string> = {
  cancel: 'Cancel submission',
  halt: 'Pause rollout',
  resume: 'Resume rollout',
  publish: 'Release',
}

export type SubmissionControlRequest = {
  submission: DistributionSubmission
  control: SubmissionControl
}

type UpdateSubmissionStatusProps = {
  projectId: string
  request: SubmissionControlRequest | null
  onOpenChange: (open: boolean) => void
  onRequested: (submission: DistributionSubmission) => void
}

export function UpdateSubmissionStatus({
  projectId,
  request,
  onOpenChange,
  onRequested,
}: UpdateSubmissionStatusProps) {
  const t = useT()
  const updateStatus = useUpdateDistributionSubmissionStatus(projectId)
  const control = request?.control ?? 'cancel'

  const handleConfirm = () => {
    if (!request) return
    const { submission } = request
    updateStatus.mutate(
      {
        appId: submission.appId,
        submissionId: submission.$id,
        status: CONTROL_STATUSES[request.control],
      },
      {
        onSuccess: () => {
          onRequested(submission)
          toast.success(t('Change requested'))
          onOpenChange(false)
        },
        onError: (error) => {
          onOpenChange(false)
          toast.error(
            getErrorMessage(error, t('Failed to update the submission')),
          )
        },
      },
    )
  }

  return (
    <ConfirmActionDialog
      open={request !== null}
      onOpenChange={onOpenChange}
      title={CONTROL_LABELS[control]}
      description={t(CONTROL_DESCRIPTIONS[control])}
      confirmLabel={CONTROL_CONFIRM_LABELS[control]}
      confirmVariant={control === 'cancel' ? 'destructive' : 'default'}
      onConfirm={handleConfirm}
      isConfirming={updateStatus.isPending}
    />
  )
}
