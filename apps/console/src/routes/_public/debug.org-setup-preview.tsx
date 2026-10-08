import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import {
  OrganizationSetupProgress,
  type OrganizationSetupProgressState,
} from '@/components/pages/organizations/$orgId/billing/change-plan/OrganizationSetupProgress'
import { pageTitle } from '@/lib/utils/page-title'

const orgSetupPreviewSearchSchema = z.object({
  phase: z
    .enum(['submitting', 'confirming-payment', 'activating', 'complete'])
    .optional(),
  mode: z.enum(['create', 'upgrade']).optional(),
  payment: z.boolean().optional(),
  activation: z.boolean().optional(),
})

export type OrgSetupPreviewPhase = NonNullable<
  z.infer<typeof orgSetupPreviewSearchSchema>['phase']
>

export const Route = createFileRoute('/_public/debug/org-setup-preview')({
  validateSearch: orgSetupPreviewSearchSchema,
  head: () => ({ meta: [{ title: pageTitle('Org setup preview') }] }),
  component: OrgSetupPreviewPage,
})

function OrgSetupPreviewPage() {
  const search = Route.useSearch()

  const progress: OrganizationSetupProgressState = {
    mode: search.mode ?? 'create',
    phase: search.phase ?? 'activating',
    organizationName: 'Acme Inc.',
    planLabel: 'Pro',
    showPaymentStep: search.payment ?? true,
    showActivationStep: search.activation ?? true,
  }

  return (
    <div className="fixed inset-0 z-[9997] flex flex-col bg-background">
      <WizardLayout
        title="Create organization"
        fullscreen
        useSidebar={false}
        skipInitialFieldFocus
        contentWrapperClassName="overflow-y-auto"
      >
        <OrganizationSetupProgress progress={progress} />
      </WizardLayout>
    </div>
  )
}
