import type { Models } from '@appwrite.io/console'
import { useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { useVerifyDomain } from '@/lib/react-query/hooks'
import { useT } from '@/lib/i18n/translate'
import {
  getAppwriteErrorInfo,
  getErrorMessage,
} from '@/lib/utils/error-formatting'
import { isSmtpRule } from '@/lib/function-smtp-domain'
import { isWaitingForDns } from '@/lib/utils/status-badge'

interface CheckDomainProps {
  projectId: string
  region?: string
  rule: Models.ProxyRule
  organizationDomainId?: string
}

/**
 * Row action for a domain Appwrite is still working on. While it waits for
 * DNS, it shows when the records were last checked and checks them now. While
 * its certificate is issued, it reloads the status.
 */
export function CheckDomain({
  projectId,
  region,
  rule,
  organizationDomainId,
}: CheckDomainProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const verifyDomainMutation = useVerifyDomain(projectId, region)
  const [refreshing, setRefreshing] = useState(false)

  const handleCheck = async () => {
    try {
      const updatedRule = await verifyDomainMutation.mutateAsync({
        ruleId: rule.$id,
        organizationDomainId,
      })
      // The API reports a DNS check that has not passed as 'unverified'.
      if (updatedRule.status === 'unverified') {
        toast.info(t('Not verified yet. Appwrite keeps checking.'))
      } else if (updatedRule.status === 'verified') {
        toast.success(
          isSmtpRule(rule)
            ? t('Email domain verified')
            : `${rule.domain} ${t('has been verified')}`,
        )
      } else {
        toast.success(t('Verification in progress'))
      }
    } catch (err) {
      // The same error covers a missing record, a wrong value and a CAA
      // conflict, so show what the check found.
      if (getAppwriteErrorInfo(err).type === 'rule_verification_failed') {
        toast.info(t('Not verified yet. Appwrite keeps checking.'), {
          description: getErrorMessage(err),
        })
      } else {
        toast.error(getErrorMessage(err))
      }
    }
  }

  const handleRefresh = async () => {
    setRefreshing(true)
    try {
      // Every proxy rule list of this project, as after a verification.
      await queryClient.refetchQueries({
        queryKey: ['proxy-rules'],
        predicate: (query) => query.queryKey.includes(projectId),
      })
    } finally {
      setRefreshing(false)
    }
  }

  if (isWaitingForDns(rule)) {
    return (
      <>
        <span className="text-[12px] text-muted-foreground inline-flex items-center gap-1 whitespace-nowrap">
          {t('Last check:')}
          <DateTooltip date={rule.$updatedAt} live disableTooltip />
        </span>
        <Button
          variant="link"
          size="sm"
          className="h-auto p-0 text-[13px]"
          disabled={verifyDomainMutation.isPending}
          onClick={handleCheck}
        >
          {verifyDomainMutation.isPending ? t('Checking...') : t('Check now')}
        </Button>
      </>
    )
  }

  if (rule.status === 'verifying') {
    return (
      <Button
        variant="link"
        size="sm"
        className="h-auto p-0 text-[13px]"
        disabled={refreshing}
        onClick={handleRefresh}
      >
        {refreshing ? t('Refreshing...') : t('Refresh')}
      </Button>
    )
  }

  return null
}
