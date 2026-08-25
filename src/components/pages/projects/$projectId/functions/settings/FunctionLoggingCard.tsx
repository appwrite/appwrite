import { useState, useEffect } from 'react'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { buildFunctionUpdateParams } from '@/lib/react-query/hooks'
import { sdk } from '@/lib/appwrite/sdk'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { toast } from 'sonner'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'

interface FunctionLoggingCardProps {
  projectId: string | null | undefined
  functionId: string | null | undefined
  func: Models.Function
}

export function FunctionLoggingCard({
  projectId,
  functionId,
  func,
}: FunctionLoggingCardProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const [logging, setLogging] = useState(true)

  useEffect(() => {
    setLogging(func.logging ?? true)
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
      toast.success(t('Logging updated successfully'))
      queryClient.setQueryData(
        ['function', 'project', projectId, functionId],
        updated,
      )
      queryClient.invalidateQueries({
        queryKey: ['functions', 'project', projectId],
      })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error, t('Failed to update logging')))
    },
  })

  const handleSave = () => {
    updateMutation.mutate({ logging })
  }

  const hasChanges = logging !== (func.logging ?? true)

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Logging')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t(
            'When enabled, execution output is written to your function logs in the console, which helps debugging. Disabling it reduces log volume when you do not need stdout and stderr from every run.',
          )}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <div className="flex items-center justify-between gap-4">
          <div className="min-w-0">
            <Label htmlFor="function-logging" className="text-[13px]">
              {t('Execution logging')}
            </Label>
            <p className="text-[12px] text-muted-foreground mt-1">
              {logging
                ? t('Enabled - function stdout and stderr are recorded.')
                : t('Disabled - less log output per execution.')}
            </p>
          </div>
          <Switch
            id="function-logging"
            checked={logging}
            onCheckedChange={setLogging}
            disabled={updateMutation.isPending}
            className="shrink-0"
          />
        </div>
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={!hasChanges || updateMutation.isPending}
          onClick={handleSave}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}
