import { createFileRoute } from '@tanstack/react-router'
import { OnboardingLayout } from '@/components/pages/onboarding'

export const Route = createFileRoute('/_public/onboarding')({
  component: OnboardingPage,
})

function OnboardingPage() {
  return <OnboardingLayout />
}
