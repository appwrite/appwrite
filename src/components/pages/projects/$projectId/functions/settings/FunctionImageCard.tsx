import { useState, useEffect, useMemo } from 'react'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  buildFunctionUpdateParams,
  useProjectRuntimes,
} from '@/lib/react-query/hooks'
import { sdk } from '@/lib/appwrite/sdk'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { toast } from 'sonner'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { RuntimeIcon } from '@/components/global/shared/RuntimeIcon'
import { useT } from '@/lib/i18n/translate'

interface FunctionImageCardProps {
  projectId: string | null | undefined
  functionId: string | null | undefined
  func: Models.Function
}

export function FunctionImageCard({
  projectId,
  functionId,
  func,
}: FunctionImageCardProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const { data: runtimesData } = useProjectRuntimes(projectId)

  const [runtime, setRuntime] = useState('')
  const [entrypoint, setEntrypoint] = useState('')

  const runtimes = useMemo(() => runtimesData?.runtimes || [], [runtimesData])

  useEffect(() => {
    setRuntime(func.runtime || '')
    setEntrypoint(func.entrypoint || '')
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
      toast.success(t('Image updated successfully'))
      queryClient.setQueryData(
        ['function', 'project', projectId, functionId],
        updated,
      )
      queryClient.invalidateQueries({
        queryKey: ['functions', 'project', projectId],
      })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error, t('Failed to update image')))
    },
  })

  const handleSave = () => {
    if (!runtime || !entrypoint.trim()) {
      toast.error(t('Image and entrypoint are required'))
      return
    }
    updateMutation.mutate({ runtime, entrypoint })
  }

  const hasChanges =
    runtime !== (func.runtime || '') ||
    entrypoint !== (func.entrypoint || '')

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">
          {t('Image')}
        </h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t(
            'Execution environment for this function and the file Appwrite loads as the handler. CPU and memory per run are set under Specification.', // pragma: allowlist secret
          )}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <div className="space-y-4">
          <div>
            <Label htmlFor="function-image" className="text-[13px]">
              {t('Image')}
            </Label>
            {runtimes.length > 0 ? (
              <Select value={runtime || undefined} onValueChange={setRuntime}>
                <SelectTrigger
                  id="function-image"
                  className="mt-2 h-9 border-border bg-background text-[13px]"
                >
                  <SelectValue placeholder={t('Select an image')} />
                </SelectTrigger>
                <SelectContent>
                  {runtimes.map((rt) => (
                    <SelectItem key={rt.$id} value={rt.$id}>
                      <div className="flex items-center gap-2">
                        <RuntimeIcon runtime={rt.$id} size="sm" />
                        <span>
                          {rt.name} {rt.version}
                        </span>
                      </div>
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            ) : (
              <Input
                id="function-image"
                value={runtime}
                onChange={(e) => setRuntime(e.target.value)}
                placeholder={t('Runtime ID')}
                className="mt-2 h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
              />
            )}
          </div>
          <div>
            <Label htmlFor="function-entrypoint" className="text-[13px]">
              {t('Entrypoint')}
            </Label>
            <Input
              id="function-entrypoint"
              value={entrypoint}
              onChange={(e) => setEntrypoint(e.target.value)}
              placeholder="src/index.js"
              className="mt-2 h-9 font-mono border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
            />
            <p className="mt-1 text-[12px] text-muted-foreground">
              {t("Path to your function's entry point")}
            </p>
          </div>
        </div>
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={
            !hasChanges ||
            !runtime ||
            !entrypoint.trim() ||
            updateMutation.isPending
          }
          onClick={handleSave}
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}
