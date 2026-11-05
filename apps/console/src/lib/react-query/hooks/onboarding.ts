/**
 * Onboarding checklist: shared query for sidebar progress + onboarding page.
 */

import { useQuery, useMutation, useQueryClient, queryOptions } from '@tanstack/react-query'
import { useMemo } from 'react'
import {
  fetchProjectOnboardingSnapshot,
  skipOnboardingSteps,
  computeOnboardingProgress,
  buildOnboardingStepStateMap,
  getAtomicOnboardingStepCount,
  ONBOARDING_CONNECT,
  ONBOARDING_PRODUCT_CATEGORIES,
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

export function useOnboardingProgressFromSnapshot(
  snapshot: ProjectOnboardingSnapshot | undefined,
) {
  return useMemo(() => {
    if (!snapshot) {
      return {
        progress: 0,
        completedSteps: 0,
        totalSteps: getAtomicOnboardingStepCount(),
      }
    }
    return computeOnboardingProgress(snapshot)
  }, [snapshot])
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
