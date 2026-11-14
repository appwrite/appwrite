import { useState } from 'react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import {
  DomainTargetCard,
  type DomainBehaviour,
} from '@/components/pages/projects/$projectId/shared/DomainTargetCard'
import { useUpdateDomainRule } from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { toast } from 'sonner'
import { useT } from '@/lib/i18n/translate'
import type { Models } from '@appwrite.io/console'

interface EditDomainDialogProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  region?: string
  rule: Models.ProxyRule
  resourceType: 'site' | 'function'
  resourceId: string
  installationId?: string | null
  providerRepositoryId?: string | null
}

export function EditDomainDialog({
  open,
  onOpenChange,
  projectId,
  region,
  rule,
  resourceType,
  resourceId,
  installationId,
  providerRepositoryId,
}: EditDomainDialogProps) {
  const t = useT()
  const updateMutation = useUpdateDomainRule(projectId, region)

  const [behaviour, setBehaviour] = useState<DomainBehaviour>(
    rule.redirectUrl
      ? 'redirect'
      : rule.deploymentVcsProviderBranch
        ? 'branch'
        : 'active',
  )
  const [branch, setBranch] = useState(rule.deploymentVcsProviderBranch ?? '')
  const [redirectUrl, setRedirectUrl] = useState(rule.redirectUrl ?? '')
  const [statusCode, setStatusCode] = useState(
    rule.redirectUrl ? String(rule.redirectStatusCode) : '302',
  )

  const hasRepository = !!(installationId && providerRepositoryId)

  const handleSubmit = async () => {
    if (behaviour === 'branch' && !branch) {
      toast.error(t('Select branch'))
      return
    }
    if (behaviour === 'redirect' && !redirectUrl.trim()) {
      toast.error(t('Enter a redirect URL'))
      return
    }
    const url = redirectUrl.trim()
    try {
      await updateMutation.mutateAsync({
        ruleId: rule.$id,
        resourceType,
        resourceId,
        behaviour,
        branch,
        redirectUrl: url.startsWith('http') ? url : `https://${url}`,
        statusCode,
      })
      toast.success(t('Domain has been updated'))
      onOpenChange(false)
    } catch (error: unknown) {
      toast.error(getErrorMessage(error) || t('Failed to update domain'))
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl p-0">
        <DialogHeader className="px-6 pt-6 text-start">
          <DialogTitle>{t('Edit domain')}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t('Change what')} <strong>{rule.domain}</strong>{' '}
            {t('serves. Its DNS and certificate stay as they are.')}
          </DialogDescription>
        </DialogHeader>

        <div className="px-6 pb-6">
          <DomainTargetCard
            behaviour={behaviour}
            onBehaviourChange={setBehaviour}
            branch={branch}
            onBranchChange={setBranch}
            redirectUrl={redirectUrl}
            onRedirectUrlChange={setRedirectUrl}
            statusCode={statusCode}
            onStatusCodeChange={setStatusCode}
            projectId={projectId}
            installationId={installationId}
            providerRepositoryId={providerRepositoryId}
            hasRepository={hasRepository}
            ruleType={rule.redirectUrl ? 'redirect' : 'deployment'}
            disabled={updateMutation.isPending}
          />
        </div>

        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button
            type="button"
            variant="outline"
            size="sm"
            className="h-9 text-[13px]"
            onClick={() => onOpenChange(false)}
            disabled={updateMutation.isPending}
          >
            {t('Cancel')}
          </Button>
          <Button
            type="button"
            size="sm"
            className="h-9 text-[13px]"
            onClick={handleSubmit}
            disabled={updateMutation.isPending}
          >
            {t('Update')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
