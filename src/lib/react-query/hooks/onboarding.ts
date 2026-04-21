/**
 * Onboarding checklist: shared query for sidebar progress + onboarding page.
 */

import { useQuery, queryOptions } from '@tanstack/react-query'
import { useMemo } from 'react'
import {
  fetchProjectOnboardingSnapshot,
  computeOnboardingProgress,
  buildOnboardingStepDoneMap,
  getAtomicOnboardingStepCount,
  ONBOARDING_CONNECT,
  ONBOARDING_PRODUCT_CATEGORIES,
  type ProjectOnboardingSnapshot,
} from '@/lib/onboarding/project-onboarding'

export function onboardingSnapshotQueryOptions(projectId: string | null | undefined) {
  return queryOptions({
    queryKey: ['onboarding', 'snapshot', 'project', projectId],
    queryFn: () => fetchProjectOnboardingSnapshot(projectId!),
    enabled: !!projectId,
    staleTime: Infinity,
    retry: false,
    refetchOnMount: false,
    refetchOnWindowFocus: false,
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

function emptyStepDoneMap(): Map<string, boolean> {
  const map = new Map<string, boolean>()
  for (const step of ONBOARDING_CONNECT) {
    map.set(step.id, false)
  }
  for (const cat of ONBOARDING_PRODUCT_CATEGORIES) {
    for (const group of cat.groups) {
      for (const sub of group.subSteps) {
        map.set(sub.id, false)
      }
    }
  }
  return map
}

export function useOnboardingStepStates(snapshot: ProjectOnboardingSnapshot | undefined) {
  return useMemo(() => {
    if (!snapshot) {
      return emptyStepDoneMap()
    }
    return buildOnboardingStepDoneMap(snapshot)
  }, [snapshot])
}
