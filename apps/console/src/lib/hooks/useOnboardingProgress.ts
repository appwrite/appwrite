import { useMemo } from 'react'
import {
  useOnboardingProductCategories,
  useProjectOnboardingSnapshot,
} from '@/lib/react-query/hooks/onboarding'
import {
  computeOnboardingProgress,
  getAtomicOnboardingStepCount,
} from '@/lib/onboarding/project-onboarding'

/**
 * Sidebar + anywhere else: onboarding completion from live project resources.
 */
export function useOnboardingProgress(projectId: string | undefined) {
  const { data: snapshot, isPending } = useProjectOnboardingSnapshot(projectId)
  const categories = useOnboardingProductCategories(projectId)

  const values = useMemo(() => {
    if (!snapshot) {
      return {
        progress: 0,
        completedSteps: 0,
        totalSteps: getAtomicOnboardingStepCount(categories),
      }
    }
    return computeOnboardingProgress(snapshot, categories)
  }, [snapshot, categories])

  return { ...values, isPending }
}
