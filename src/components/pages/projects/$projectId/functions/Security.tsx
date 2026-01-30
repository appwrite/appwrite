import { useState, useEffect, useMemo } from 'react'
import { useParams } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { useProjectFunction } from '@/lib/react-query/hooks'
import { sdk } from '@/lib/appwrite/sdk'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { toast } from 'sonner'
import { PermissionsEditor } from '@/components/pages/projects/$projectId/auth/PermissionsEditor'
import { ScopeEditor } from '@/components/global/shared/ScopeEditor'

export function View() {
  const { projectId, functionId } = useParams({ strict: false })
  const queryClient = useQueryClient()

  const { data: func, isLoading: funcLoading } = useProjectFunction(
    projectId,
    functionId,
  )

  const [execute, setExecute] = useState<string[]>([])
  const [scopes, setScopes] = useState<string[] | null>(null)

  // Initialize state from function data
  useEffect(() => {
    if (func) {
      setExecute(func.execute || [])
      setScopes(func.scopes || [])
    }
  }, [func])

  // Update function mutation
  const updateFunctionMutation = useMutation({
    mutationFn: async (updates: Partial<Models.Function>) => {
      if (!projectId || !functionId || !func)
        throw new Error('Project ID, Function ID, and Function are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.functions.update({
        functionId,
        name: func.name,
        runtime: func.runtime as any,
        execute: func.execute || undefined,
        events: func.events || undefined,
        schedule: func.schedule || undefined,
        timeout: func.timeout || undefined,
        enabled: func.enabled ?? undefined,
        logging: func.logging ?? undefined,
        entrypoint: func.entrypoint || undefined,
        commands: func.commands || undefined,
        scopes: func.scopes || undefined,
        ...updates,
      })
    },
    onSuccess: () => {
      toast.success('Function updated successfully')
      queryClient.invalidateQueries({
        queryKey: ['function', 'project', projectId, functionId],
      })
    },
    onError: (error: any) => {
      toast.error(error.message || 'Failed to update function')
    },
  })

  const handleSaveExecute = () => {
    updateFunctionMutation.mutate({ execute })
  }

  const handleSaveScopes = () => {
    updateFunctionMutation.mutate({ scopes: scopes || undefined })
  }

  // Check if arrays are equal
  const arraysEqual = (a: string[], b: string[]) => {
    if (a.length !== b.length) return false
    return a.every((val, idx) => val === b[idx])
  }

  // Compute symmetric difference for scopes (to detect changes)
  const scopesChanged = useMemo(() => {
    if (scopes === null || !func?.scopes) return false
    const original = new Set(func.scopes || [])
    const current = new Set(scopes)

    // Check if sets are different
    if (original.size !== current.size) return true

    // Check if any element is different
    for (const scope of original) {
      if (!current.has(scope)) return true
    }
    for (const scope of current) {
      if (!original.has(scope)) return true
    }

    return false
  }, [scopes, func?.scopes])

  if (funcLoading) {
    return (
      <div className="rounded-lg border border-border bg-card py-12 text-center">
        <p className="text-[13px] text-muted-foreground">
          Loading security settings...
        </p>
      </div>
    )
  }

  return (
    <div className="flex-1">
      <div className="mx-auto w-full max-w-7xl px-4 pb-4 sm:px-6 sm:pb-6 pt-4 sm:pt-6">
        <div className="space-y-6">
          {/* Permissions Card */}
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                Permissions
              </h3>
              <p className="text-[13px] text-muted-foreground mt-2">
                Choose who can execute this function
              </p>
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4">
              <PermissionsEditor
                permissions={execute}
                onPermissionsChange={setExecute}
                projectId={projectId}
              />
            </div>
            <div className="px-6 py-4 border-t border-border bg-muted/30">
              <Button
                size="sm"
                className="h-9 text-[13px]"
                disabled={
                  arraysEqual(execute, func?.execute || []) ||
                  updateFunctionMutation.isPending
                }
                onClick={handleSaveExecute}
              >
                Update
              </Button>
            </div>
          </div>

          {/* Scopes Card */}
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                Scopes
              </h3>
              <p className="text-[13px] text-muted-foreground mt-2">
                Select scopes to grant the dynamic key generated temporarily for
                your function. It is best practice to allow only necessary
                permissions.{' '}
                <a
                  href="https://appwrite.io/docs/advanced/platform/api-keys#scopes"
                  target="_blank"
                  rel="noopener noreferrer"
                  className="text-primary hover:underline"
                >
                  Learn more
                </a>
              </p>
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4">
              {scopes !== null ? (
                <ScopeEditor
                  value={scopes}
                  onChange={setScopes}
                  disabled={updateFunctionMutation.isPending}
                />
              ) : (
                <p className="text-[13px] text-muted-foreground">
                  Loading scopes...
                </p>
              )}
            </div>
            <div className="px-6 py-4 border-t border-border bg-muted/30">
              <Button
                size="sm"
                className="h-9 text-[13px]"
                disabled={
                  !scopesChanged ||
                  scopes === null ||
                  updateFunctionMutation.isPending
                }
                onClick={handleSaveScopes}
              >
                Update
              </Button>
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
