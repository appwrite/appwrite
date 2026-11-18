import { useState } from 'react'
import type { Models } from '@appwrite.io/console'
import { toast } from 'sonner'
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
import {
  formatResourceNotFoundError,
  getErrorMessage,
} from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'

interface UpdateDomainProps {
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

export function UpdateDomain({
  open,
  onOpenChange,
  projectId,
  region,
  rule,
  resourceType,
  resourceId,
  installationId,
  providerRepositoryId,
}: UpdateDomainProps) {
  const t = useT()
  const updateMutation = useUpdateDomainRule(projectId, region)

  const isRedirect = !!rule.redirectUrl
  const initialBehaviour: DomainBehaviour = isRedirect
    ? 'redirect'
    : rule.deploymentVcsProviderBranch
      ? 'branch'
      : 'active'
  const initialStatusCode = isRedirect ? String(rule.redirectStatusCode) : '302'

  const [behaviour, setBehaviour] = useState(initialBehaviour)
  const [branch, setBranch] = useState(rule.deploymentVcsProviderBranch ?? '')
  const [redirectUrl, setRedirectUrl] = useState(rule.redirectUrl ?? '')
  const [statusCode, setStatusCode] = useState(initialStatusCode)
  const [urlError, setUrlError] = useState('')

  const hasChanges =
    behaviour !== initialBehaviour ||
    (behaviour === 'branch' && branch !== rule.deploymentVcsProviderBranch) ||
    (behaviour === 'redirect' &&
      (redirectUrl.trim() !== rule.redirectUrl ||
        statusCode !== initialStatusCode))
  const isComplete =
    (behaviour !== 'branch' || !!branch) &&
    (behaviour !== 'redirect' || !!redirectUrl.trim())
  const canSubmit = hasChanges && isComplete && !updateMutation.isPending

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault()
    if (!canSubmit) return

    const trimmedUrl = redirectUrl.trim()
    const lowerUrl = trimmedUrl.toLowerCase()
    const url =
      lowerUrl.startsWith('http://') || lowerUrl.startsWith('https://')
        ? trimmedUrl
        : `https://${trimmedUrl}`
    // Browsers percent-encode a space in the host instead of rejecting it
    const hasWhitespace = [...url].some((char) => !char.trim())
    if (behaviour === 'redirect' && (hasWhitespace || !URL.canParse(url))) {
      setUrlError(t('Invalid URL'))
      return
    }
    try {
      await updateMutation.mutateAsync({
        ruleId: rule.$id,
        resourceType,
        resourceId,
        behaviour,
        branch,
        redirectUrl: url,
        statusCode,
      })
      toast.success(t('Domain has been updated'))
      onOpenChange(false)
    } catch (error: unknown) {
      toast.error(
        formatResourceNotFoundError(error)?.message ||
          getErrorMessage(error) ||
          t('Failed to update domain'),
      )
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-2xl p-0">
        <form onSubmit={handleSubmit}>
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Update domain')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              <span className="block font-mono text-foreground break-all">
                {rule.domain}
              </span>
              <span className="block mt-1">
                {t('Its DNS records and certificate stay the same.')}
              </span>
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />

          <div className="px-6 py-4">
            <DomainTargetCard
              behaviour={behaviour}
              onBehaviourChange={setBehaviour}
              branch={branch}
              onBranchChange={setBranch}
              redirectUrl={redirectUrl}
              onRedirectUrlChange={(value) => {
                setRedirectUrl(value)
                setUrlError('')
              }}
              redirectUrlError={urlError}
              statusCode={statusCode}
              onStatusCodeChange={setStatusCode}
              projectId={projectId}
              installationId={installationId}
              providerRepositoryId={providerRepositoryId}
              hasRepository={!!(installationId && providerRepositoryId)}
              ruleType={isRedirect ? 'redirect' : 'deployment'}
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
              type="submit"
              size="sm"
              className="h-9 text-[13px]"
              disabled={!canSubmit}
            >
              {t('Update')}
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
