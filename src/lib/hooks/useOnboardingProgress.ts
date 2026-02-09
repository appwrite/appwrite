import { useMemo } from 'react'

/**
 * Calculate onboarding progress based on project resources
 *
 * Progress is calculated based on:
 * - Databases created (1 point)
 * - Storage buckets created (1 point)
 * - Functions created (1 point)
 * - Users created (1 point)
 * - Sites deployed (1 point)
 *
 * Maximum progress: 5 points = 100%
 *
 * TODO: This is currently a mock implementation. Will be implemented later with real API calls.
 */
export function useOnboardingProgress(projectId: string | undefined) {
  // Mock data for now - no API calls
  // TODO: Replace with real API calls when onboarding is implemented
  const mockProgress = useMemo(() => {
    if (!projectId) return 0

    // Return mock progress (e.g., 40% = 2 out of 5 steps completed)
    // This can be adjusted for testing different states
    return 40
  }, [projectId])

  const mockCompletedSteps = useMemo(() => {
    if (!projectId) return 0
    // Mock: 2 out of 5 steps completed
    return 2
  }, [projectId])

  return {
    progress: mockProgress,
    completedSteps: mockCompletedSteps,
    totalSteps: 5,
  }
}
