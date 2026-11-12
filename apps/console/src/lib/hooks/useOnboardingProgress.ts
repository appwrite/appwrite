import { useMemo } from 'react'
import { useProjectOnboardingSnapshot } from '@/lib/react-query/hooks/onboarding'
import {
  computeOnboardingProgress,
  getAtomicOnboardingStepCount,
} from '@/lib/onboarding/project-onboarding'

/**
 * Sidebar + anywhere else: onboarding completion from live project resources.
 */
export function useOnboardingProgress(projectId: string | undefined) {
  const { data: snapshot, isPending } = useProjectOnboardingSnapshot(projectId)

  const values = useMemo(() => {
    if (!snapshot) {
      return {
        progress: 0,
        completedSteps: 0,
        totalSteps: getAtomicOnboardingStepCount(),
      }
    }
    return computeOnboardingProgress(snapshot)
  }, [snapshot])

  return { ...values, isPending }
}
