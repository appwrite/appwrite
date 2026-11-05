import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { View } from '@/components/pages/projects/$projectId/apps/add/View'
import type { AddAppKind, WebFrameworkKey } from '@/lib/add-app-wizard/types'
import { pageTitle } from '@/lib/utils/page-title'
import { fetchProject } from '@/lib/react-query/hooks'

const addAppSearchSchema = z.object({
  step: z.enum(['configure', 'setup']).optional(),
  kind: z
    .enum([
      'web',
      'android',
      'apple',
      'flutter',
      'react-native',
      'windows',
      'linux',
    ])
    .optional(),
  variant: z.string().optional(),
  framework: z
    .enum([
      'analog',
      'angular',
      'astro',
      'js',
      'nextjs',
      'nuxt',
      'react',
      'remix',
      'solid',
      'svelte',
      'sveltekit',
      'tanstack-start',
      'vite',
      'vue',
    ])
    .optional(),
  platformId: z.string().optional(),
  /** `platform` = choose stack; `details` = register form (deep-link to skip step 1) */
  configureStep: z.enum(['platform', 'details']).optional(),
})

export const Route = createFileRoute('/_public/projects/$projectId/apps/add')({
  head: () => ({ meta: [{ title: pageTitle('Add app') }] }),
  validateSearch: addAppSearchSchema,
  loader: async ({ params, context }) => {
    if (typeof window === 'undefined') return undefined
    const { projectId } = params
    const { queryClient } = context
    if (!projectId) return undefined
    await queryClient.ensureQueryData({
      queryKey: ['project', projectId],
      queryFn: () => fetchProject(projectId),
      staleTime: 5 * 60 * 1000,
    })
    return undefined
  },
  component: AddAppPage,
})

function AddAppPage() {
  const { projectId } = Route.useParams()
  const search = Route.useSearch()
  return (
    <View
      projectId={projectId}
      search={
        search as {
          step?: 'configure' | 'setup'
          kind?: AddAppKind
          variant?: string
          framework?: WebFrameworkKey
          platformId?: string
          configureStep?: 'platform' | 'details'
        }
      }
    />
  )
}
