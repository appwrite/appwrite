import { createFileRoute } from '@tanstack/react-router'
import { OnboardingLayout } from '@/components/pages/onboarding'
import { pageTitle } from '@/lib/utils/page-title'

export const Route = createFileRoute('/_public/onboarding')({
  head: () => ({ meta: [{ title: pageTitle('Onboarding') }] }),
  component: OnboardingPage,
})

function OnboardingPage() {
  return <OnboardingLayout />
}
