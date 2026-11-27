/**
 * Onboarding checklist: shared query for sidebar progress + onboarding page.
 */

import { useQuery, useMutation, useQueryClient, queryOptions } from '@tanstack/react-query'
import { useMemo } from 'react'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useConsoleVariables } from '@/lib/react-query/hooks/console-variables'
import { useProject } from '@/lib/react-query/hooks/projects'
import {
  fetchProjectOnboardingSnapshot,
  skipOnboardingSteps,
  computeOnboardingProgress,
  buildOnboardingStepStateMap,
  getAtomicOnboardingStepCount,
  getOnboardingProductCategories,
  ONBOARDING_CONNECT,
  ONBOARDING_PRODUCT_CATEGORIES,
  type OnboardingProductCategoryDef,
  type OnboardingStepState,
  type ProjectOnboardingSnapshot,
} from '@/lib/onboarding/project-onboarding'

export function onboardingSnapshotQueryOptions(projectId: string | null | undefined) {
  return queryOptions({
    queryKey: ['onboarding', 'snapshot', 'project', projectId],
    queryFn: () => fetchProjectOnboardingSnapshot(projectId!),
    enabled: !!projectId,
    staleTime: 30 * 1000,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: true,
    refetchOnReconnect: false,
    gcTime: projectId ? 5 * 60 * 1000 : 0,
  })
}

export function useProjectOnboardingSnapshot(projectId: string | null | undefined) {
  return useQuery(onboardingSnapshotQueryOptions(projectId))
}

/**
 * Product categories the checklist shows for this project: the same feature
 * flags as the sidebar decide which products are listed and counted.
 */
export function useOnboardingProductCategories(
  projectId: string | null | undefined,
): OnboardingProductCategoryDef[] {
  const { features } = useConsoleProfile()
  const { project } = useProject(projectId ?? undefined)
  // Self-hosted runs the review service only when it opts in
  const { reviewsEnabled } = useConsoleVariables(project?.region)

  return useMemo(
    () =>
      getOnboardingProductCategories((feature) =>
        feature === 'reviews'
          ? features.reviews && reviewsEnabled
          : features[feature],
      ),
    [features, reviewsEnabled],
  )
}

export function useOnboardingProgressFromSnapshot(
  snapshot: ProjectOnboardingSnapshot | undefined,
  categories: readonly OnboardingProductCategoryDef[],
) {
  return useMemo(() => {
    if (!snapshot) {
      return {
        progress: 0,
        completedSteps: 0,
        totalSteps: getAtomicOnboardingStepCount(categories),
      }
    }
    return computeOnboardingProgress(snapshot, categories)
  }, [snapshot, categories])
}

function emptyStepStateMap(): Map<string, OnboardingStepState> {
  const map = new Map<string, OnboardingStepState>()
  for (const step of ONBOARDING_CONNECT) {
    map.set(step.id, 'pending')
  }
  for (const cat of ONBOARDING_PRODUCT_CATEGORIES) {
    for (const group of cat.groups) {
      for (const sub of group.subSteps) {
        map.set(sub.id, 'pending')
      }
    }
  }
  return map
}

export function useOnboardingStepStates(snapshot: ProjectOnboardingSnapshot | undefined) {
  return useMemo(() => {
    if (!snapshot) {
      return emptyStepStateMap()
    }
    return buildOnboardingStepStateMap(snapshot)
  }, [snapshot])
}

export function useSkipOnboardingStep(projectId: string | null | undefined) {
  const queryClient = useQueryClient()

  return useMutation({
    mutationFn: (sdkKeys: readonly string[]) =>
      skipOnboardingSteps(projectId!, sdkKeys),
    onSuccess: async () => {
      await queryClient.refetchQueries({
        queryKey: ['onboarding', 'snapshot', 'project', projectId],
      })
    },
  })
}
