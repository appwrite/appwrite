import { useState, useEffect } from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { buildFunctionUpdateParams } from '@/lib/react-query/hooks'
import { sdk } from '@/lib/appwrite/sdk'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { toast } from 'sonner'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'

interface FunctionTimeoutCardProps {
  projectId: string | null | undefined
  functionId: string | null | undefined
  func: Models.Function
}

export function FunctionTimeoutCard({
  projectId,
  functionId,
  func,
}: FunctionTimeoutCardProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const [requestTimeout, setRequestTimeout] = useState(15)

  useEffect(() => {
    setRequestTimeout(func.timeout || 15)
  }, [func])

  const updateMutation = useMutation({
    mutationFn: async (updates: Partial<Models.Function>) => {
      if (!projectId || !functionId)
        throw new Error('Project ID and Function ID are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.functions.update(
        buildFunctionUpdateParams(func, updates),
      )
    },
    onSuccess: (updated) => {
      toast.success(t('Timeout updated successfully'))
      queryClient.setQueryData(
        ['function', 'project', projectId, functionId],
        updated,
      )
      queryClient.invalidateQueries({
        queryKey: ['functions', 'project', projectId],
      })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error, t('Failed to update timeout')))
    },
  })

  const handleSave = () => {
    if (requestTimeout < 1 || requestTimeout > 900) {
      toast.error(t('Timeout must be between 1 and 900 seconds'))
      return
    }
    updateMutation.mutate({ timeout: requestTimeout })
  }

  const hasChanges = requestTimeout !== (func.timeout || 15)
  const timeoutValid = requestTimeout >= 1 && requestTimeout <= 900

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Timeout')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t(
            'Maximum time a single execution may run before it is stopped. Use a higher value for slow I/O or heavy work; use a lower value to cap run time. Allowed range is 1–900 seconds.',
          )}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <div className="space-y-2">
          <Label htmlFor="function-timeout" className="text-[13px]">
            {t('Seconds per execution')}
          </Label>
          <Input
            id="function-timeout"
            type="number"
            min={1}
            max={900}
            value={requestTimeout}
            onChange={(e) => setRequestTimeout(Number(e.target.value))}
            className="h-9 max-w-sm border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
          />
        </div>
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={!hasChanges || !timeoutValid || updateMutation.isPending}
          onClick={handleSave}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}
