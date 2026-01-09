import { useState, createContext, useContext, type ReactNode } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { cn } from '@/lib/utils'
import { OnboardingWelcome } from './OnboardingWelcome'
import { OnboardingProjectCreate } from './OnboardingProjectCreate'
import { OnboardingDashboard } from './OnboardingDashboard'
import type { OnboardingIntent } from './data'

type OnboardingPhase = 'welcome' | 'create-project' | 'dashboard'

interface OnboardingState {
  phase: OnboardingPhase
  projectName: string
  projectId: string | null
  selectedSdk: string
  selectedIntent: OnboardingIntent | null
  selectedTemplate: string
  selectedRegion: string
}

interface OnboardingContextValue {
  state: OnboardingState
  setPhase: (phase: OnboardingPhase) => void
  setProjectName: (name: string) => void
  setProjectId: (id: string) => void
  setSelectedSdk: (sdk: string) => void
  setSelectedIntent: (intent: OnboardingIntent | null) => void
  setSelectedTemplate: (template: string) => void
  setSelectedRegion: (region: string) => void
  skipOnboarding: () => void
  createProject: () => void
}

const OnboardingContext = createContext<OnboardingContextValue | null>(null)

export function useOnboarding() {
  const context = useContext(OnboardingContext)
  if (!context) {
    throw new Error('useOnboarding must be used within OnboardingLayout')
  }
  return context
}

interface OnboardingLayoutProps {
  children?: ReactNode
}

export function OnboardingLayout({ children }: OnboardingLayoutProps) {
  const navigate = useNavigate()
  const [state, setState] = useState<OnboardingState>({
    phase: 'welcome',
    projectName: '',
    projectId: null,
    selectedSdk: 'web',
    selectedIntent: null,
    selectedTemplate: 'blank',
    selectedRegion: 'fra',
  })

  const setPhase = (phase: OnboardingPhase) => {
    setState((prev) => ({ ...prev, phase }))
  }

  const setProjectName = (name: string) => {
    setState((prev) => ({ ...prev, projectName: name }))
  }

  const setProjectId = (id: string) => {
    setState((prev) => ({ ...prev, projectId: id }))
  }

  const setSelectedSdk = (sdk: string) => {
    setState((prev) => ({ ...prev, selectedSdk: sdk }))
  }

  const setSelectedIntent = (intent: OnboardingIntent | null) => {
    setState((prev) => ({ ...prev, selectedIntent: intent }))
  }

  const setSelectedTemplate = (template: string) => {
    setState((prev) => ({ ...prev, selectedTemplate: template }))
  }

  const setSelectedRegion = (region: string) => {
    setState((prev) => ({ ...prev, selectedRegion: region }))
  }

  const skipOnboarding = () => {
    // Navigate to root which will redirect to the correct org
    navigate({ to: '/' })
  }

  const createProject = () => {
    // Generate mock project ID
    const projectId = `proj_${Date.now().toString(36)}`
    setProjectId(projectId)
    setPhase('dashboard')
  }

  const contextValue: OnboardingContextValue = {
    state,
    setPhase,
    setProjectName,
    setProjectId,
    setSelectedSdk,
    setSelectedIntent,
    setSelectedTemplate,
    setSelectedRegion,
    skipOnboarding,
    createProject,
  }

  return (
    <OnboardingContext.Provider value={contextValue}>
      <div
        className={cn(
          'flex min-h-screen w-full flex-col bg-background',
          'transition-colors duration-300',
        )}
      >
        {state.phase === 'welcome' && <OnboardingWelcome />}
        {state.phase === 'create-project' && <OnboardingProjectCreate />}
        {state.phase === 'dashboard' && <OnboardingDashboard />}
        {children}
      </div>
    </OnboardingContext.Provider>
  )
}
