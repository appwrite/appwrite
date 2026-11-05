'use client'

import type { CommunitySupportActionId } from '@/lib/community/support-prompt'
import { CommunitySupportWizardContent } from '@/components/global/shared/community-support-wizard/Content'

type CommunitySupportWizardProps = {
  onSkip: () => void
  onAction: (actionId: CommunitySupportActionId) => void
}

export function CommunitySupportWizard({
  onSkip,
  onAction,
}: CommunitySupportWizardProps) {
  return <CommunitySupportWizardContent onSkip={onSkip} onAction={onAction} />
}
