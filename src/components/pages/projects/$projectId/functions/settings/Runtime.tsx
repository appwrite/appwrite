import { useState, useEffect, useMemo } from 'react'
import { useParams } from '@tanstack/react-router'
import {
  useProjectFunction,
  useFunctionSpecifications,
  buildFunctionUpdateParams,
} from '@/lib/react-query/hooks'
import { sdk } from '@/lib/appwrite/sdk'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { toast } from 'sonner'
import { hasUnavailableSpecifications } from '@/lib/specifications'
import { SpecificationTableCard } from '../../shared/SpecificationTableCard'
import { FunctionImageCard } from './FunctionImageCard'
import { FunctionTimeoutCard } from './FunctionTimeoutCard'
import { FunctionLoggingCard } from './FunctionLoggingCard'

const CONTACT_SALES_URL =
  import.meta.env.VITE_CONTACT_SALES_URL ||
  'https://appwrite.io/contact-us/enterprise'

export function View() {
  const { projectId, functionId } = useParams({ strict: false })
  const queryClient = useQueryClient()

  const { data: func, isLoading: funcLoading } = useProjectFunction(
    projectId,
    functionId,
  )
  const { data: specificationsData } = useFunctionSpecifications(projectId)

  const [runtimeSpecification, setRuntimeSpecification] = useState('')

  useEffect(() => {
    if (func) {
      setRuntimeSpecification(func.runtimeSpecification || '')
    }
  }, [func])

  const specifications = useMemo(
    () => specificationsData?.specifications || [],
    [specificationsData],
  )

  const updateFunctionMutation = useMutation({
    mutationFn: async (updates: Partial<Models.Function>) => {
      if (!projectId || !functionId || !func)
        throw new Error('Project ID, Function ID, and Function are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.functions.update(
        buildFunctionUpdateParams(func, updates),
      )
    },
    onSuccess: () => {
      toast.success('Function updated successfully')
      queryClient.invalidateQueries({
        queryKey: ['function', 'project', projectId, functionId],
      })
      queryClient.invalidateQueries({
        queryKey: ['functions', 'project', projectId],
      })
    },
    onError: (error: unknown) => {
      toast.error(
        error instanceof Error ? error.message : 'Failed to update function',
      )
    },
  })

  const handleSaveSpecification = () => {
    updateFunctionMutation.mutate({
      runtimeSpecification: runtimeSpecification || undefined,
    })
  }

  const specDirty =
    runtimeSpecification !== (func?.runtimeSpecification || '')

  if (funcLoading) {
    return (
      <div className="rounded-lg border border-border bg-card py-12 text-center">
        <p className="text-[13px] text-muted-foreground">Loading settings...</p>
      </div>
    )
  }

  if (!func) return null

  return (
    <>
      <FunctionImageCard
        projectId={projectId}
        functionId={functionId}
        func={func}
      />
      <FunctionTimeoutCard
        projectId={projectId}
        functionId={functionId}
        func={func}
      />
      <FunctionLoggingCard
        projectId={projectId}
        functionId={functionId}
        func={func}
      />

      {specifications.length > 0 ? (
        <SpecificationTableCard
          title="Specification"
          description="CPU and memory available to each function execution at runtime."
          scope="runtime-function"
          specs={specifications}
          selectedSlug={runtimeSpecification}
          onSelectedSlugChange={setRuntimeSpecification}
          hasChanges={specDirty}
          isSaving={updateFunctionMutation.isPending}
          onSave={handleSaveSpecification}
          footerNote={
            hasUnavailableSpecifications(specifications) ? (
              <div className="rounded-lg border border-border bg-muted/30 px-3 py-2.5">
                <p className="text-[12px] text-muted-foreground">
                  Need more resources?{' '}
                  <a
                    href="#"
                    className="font-medium text-foreground underline hover:no-underline"
                    onClick={(e) => {
                      e.preventDefault()
                    }}
                  >
                    Upgrade your plan
                  </a>{' '}
                  or{' '}
                  <a
                    href={CONTACT_SALES_URL}
                    target="_blank"
                    rel="noopener noreferrer"
                    className="font-medium text-foreground underline hover:no-underline"
                  >
                    contact sales
                  </a>{' '}
                  to unlock additional specifications.
                </p>
              </div>
            ) : undefined
          }
        />
      ) : null}
    </>
  )
}
