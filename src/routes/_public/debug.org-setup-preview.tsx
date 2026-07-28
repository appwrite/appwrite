import { useState } from 'react'
import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { WizardLayout } from '@/components/global/shared/WizardLayout'
import {
  OrganizationSetupProgress,
  type OrganizationSetupPhase,
  type OrganizationSetupProgressState,
} from '@/components/pages/organizations/$orgId/billing/change-plan/OrganizationSetupProgress'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { pageTitle } from '@/lib/utils/page-title'

const orgSetupPreviewSearchSchema = z.object({
  phase: z
    .enum(['submitting', 'confirming-payment', 'activating', 'complete'])
    .optional(),
  mode: z.enum(['create', 'upgrade']).optional(),
  payment: z.boolean().optional(),
  activation: z.boolean().optional(),
})

export const Route = createFileRoute('/_public/debug/org-setup-preview')({
  validateSearch: orgSetupPreviewSearchSchema,
  head: () => ({ meta: [{ title: pageTitle('Org setup preview') }] }),
  component: OrgSetupPreviewPage,
})

const PHASE_OPTIONS: { value: OrganizationSetupPhase; label: string }[] = [
  { value: 'submitting', label: 'Creating organization' },
  { value: 'confirming-payment', label: 'Confirming payment' },
  { value: 'activating', label: 'Activating plan' },
  { value: 'complete', label: 'Finishing up' },
]

function OrgSetupPreviewPage() {
  const search = Route.useSearch()

  const [progress, setProgress] = useState<OrganizationSetupProgressState>({
    mode: search.mode ?? 'create',
    phase: search.phase ?? 'activating',
    organizationName: 'Acme Inc.',
    planLabel: 'Pro',
    showPaymentStep: search.payment ?? true,
    showActivationStep: search.activation ?? true,
  })

  return (
    <div className="fixed inset-0 z-[9997] flex flex-col bg-background">
      <WizardLayout
        title="Create organization"
        fullscreen
        useSidebar={false}
        skipInitialFieldFocus
        footer={
          <div className="flex w-full flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <p className="text-[12px] text-muted-foreground">
              Debug preview - adjust the setup progress stage below.
            </p>
            <div className="flex flex-wrap gap-2">
              {PHASE_OPTIONS.map((option) => (
                <Button
                  key={option.value}
                  type="button"
                  size="sm"
                  variant={
                    progress.phase === option.value ? 'default' : 'outline'
                  }
                  className="h-8 text-[12px]"
                  onClick={() =>
                    setProgress((prev) => ({ ...prev, phase: option.value }))
                  }
                >
                  {option.label}
                </Button>
              ))}
            </div>
          </div>
        }
        contentWrapperClassName="overflow-y-auto"
      >
        <OrganizationSetupProgress progress={progress} />

        <div className="mx-auto mt-8 w-full max-w-lg rounded-xl border border-dashed border-border bg-muted/20 px-6 py-4">
          <p className="mb-4 text-[13px] font-semibold text-foreground">
            Preview options
          </p>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-2">
              <Label htmlFor="preview-mode">Mode</Label>
              <Select
                value={progress.mode}
                onValueChange={(value: 'create' | 'upgrade') =>
                  setProgress((prev) => ({ ...prev, mode: value }))
                }
              >
                <SelectTrigger id="preview-mode" className="h-9">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="create">Create organization</SelectItem>
                  <SelectItem value="upgrade">Change plan</SelectItem>
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-2">
              <Label htmlFor="preview-plan">Plan label</Label>
              <Input
                id="preview-plan"
                value={progress.planLabel}
                onChange={(e) =>
                  setProgress((prev) => ({
                    ...prev,
                    planLabel: e.target.value,
                  }))
                }
                className="h-9"
              />
            </div>

            {progress.mode === 'create' ? (
              <div className="space-y-2 sm:col-span-2">
                <Label htmlFor="preview-org-name">Organization name</Label>
                <Input
                  id="preview-org-name"
                  value={progress.organizationName ?? ''}
                  onChange={(e) =>
                    setProgress((prev) => ({
                      ...prev,
                      organizationName: e.target.value,
                    }))
                  }
                  className="h-9"
                />
              </div>
            ) : null}

            <div className="flex items-center justify-between gap-3 rounded-lg border border-border px-3 py-2">
              <Label htmlFor="preview-payment" className="text-[13px]">
                Payment step
              </Label>
              <Switch
                id="preview-payment"
                checked={progress.showPaymentStep}
                onCheckedChange={(checked) =>
                  setProgress((prev) => ({
                    ...prev,
                    showPaymentStep: checked,
                  }))
                }
              />
            </div>

            <div className="flex items-center justify-between gap-3 rounded-lg border border-border px-3 py-2">
              <Label htmlFor="preview-activation" className="text-[13px]">
                Activation step
              </Label>
              <Switch
                id="preview-activation"
                checked={progress.showActivationStep}
                onCheckedChange={(checked) =>
                  setProgress((prev) => ({
                    ...prev,
                    showActivationStep: checked,
                  }))
                }
              />
            </div>
          </div>
        </div>
      </WizardLayout>
    </div>
  )
}
