import { useSyncStateFromServer } from '@/hooks/use-sync-state-from-server'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { buildFunctionUpdateParams } from '@/lib/react-query/hooks'
import { useConsoleVariables } from '@/lib/react-query/hooks/console-variables'
import { sdk } from '@/lib/appwrite/sdk'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { Mail } from 'lucide-react'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import {
  buildFunctionEmailAddress,
  isFunctionEmailEnabled,
  putFunction,
  type FunctionWithEmail,
} from '@/lib/function-email'

interface FunctionEmailCardProps {
  projectId: string | null | undefined
  functionId: string | null | undefined
  region: string | undefined
  func: FunctionWithEmail
  disabled?: boolean
}

/** Opt-in inbound email (SMTP) trigger, next to Schedule and Events. */
export function FunctionEmailCard({
  projectId,
  functionId,
  region,
  func,
  disabled = false,
}: FunctionEmailCardProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const { emailDomain } = useConsoleVariables(region)
  const savedEnabled = isFunctionEmailEnabled(func)
  const [emailEnabled, setEmailEnabled] = useSyncStateFromServer(savedEnabled)

  const updateMutation = useMutation({
    mutationFn: async (nextEnabled: boolean) => {
      if (!projectId || !functionId)
        throw new Error('Project ID and Function ID are required')
      const projectSdk = sdk.forProject(projectId)
      const params = buildFunctionUpdateParams(func, {}) as {
        functionId: string
      } & Record<string, unknown>
      return await putFunction(projectSdk.client, {
        ...params,
        emailEnabled: nextEnabled,
      })
    },
    onSuccess: (updated, nextEnabled) => {
      toast.success(
        nextEnabled ? t('Email trigger enabled') : t('Email trigger disabled'),
      )
      queryClient.setQueryData(
        ['function', 'project', projectId, functionId],
        updated,
      )
      queryClient.invalidateQueries({
        queryKey: ['functions', 'project', projectId],
      })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error, t('Failed to update email trigger')))
      setEmailEnabled(savedEnabled)
    },
  })

  const pending = disabled || updateMutation.isPending
  const address = emailEnabled
    ? buildFunctionEmailAddress(projectId, func.$id, emailDomain)
    : null

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Email')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t(
            'Allow inbound email to trigger this function. It is off by default, so no mail is accepted until you enable it.',
          )}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4 space-y-4">
        <div className="flex items-center justify-between gap-4">
          <div className="min-w-0">
            <Label htmlFor="function-email-enabled" className="text-[13px]">
              {t('Email trigger')}
            </Label>
            <p className="text-[12px] text-muted-foreground mt-1">
              {emailEnabled
                ? t('Inbound mail for this function is accepted.')
                : t('Inbound mail for this function is rejected.')}
            </p>
          </div>
          <Switch
            id="function-email-enabled"
            checked={emailEnabled}
            onCheckedChange={setEmailEnabled}
            disabled={pending}
            className="shrink-0"
          />
        </div>
        {address ? (
          <div className="flex items-start gap-3 rounded-lg border border-border bg-muted/30 px-3 py-3">
            <Mail className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
            <div className="min-w-0 flex-1 space-y-1">
              <p className="text-[12px] font-medium text-foreground">
                {t('Testing address')}
              </p>
              <CopyableId
                id={address}
                size="md"
                maxWidth={420}
                showCopyOnHover={false}
                copyToastLabel="Email"
                className="max-w-full"
              />
              {!func.deploymentId ? (
                <p className="text-[12px] text-amber-600 dark:text-amber-400">
                  {t(
                    'Mail is delivered when this function has an active deployment.',
                  )}
                </p>
              ) : null}
            </div>
          </div>
        ) : null}
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={emailEnabled === savedEnabled || pending}
          onClick={() => updateMutation.mutate(emailEnabled)}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}
