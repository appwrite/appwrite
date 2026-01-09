import { useState } from 'react'
import { useOnboarding } from './OnboardingLayout'
import { OnboardingSidebar } from './OnboardingSidebar'
import { OnboardingIntentSelector } from './OnboardingIntentSelector'
import { OnboardingPathContent } from './OnboardingPathContent'
import { motion } from 'motion/react'
import { cn } from '@/lib/utils'

export function OnboardingDashboard() {
  const { state } = useOnboarding()
  const [sidebarCollapsed, setSidebarCollapsed] = useState(false)

  return (
    <div className="flex min-h-screen">
      {/* Sidebar */}
      <OnboardingSidebar
        collapsed={sidebarCollapsed}
        onToggleCollapse={() => setSidebarCollapsed(!sidebarCollapsed)}
      />

      {/* Main content */}
      <main
        className={cn(
          'flex-1 overflow-y-auto transition-all duration-200',
          sidebarCollapsed ? 'ml-[60px]' : 'ml-[280px]',
        )}
      >
        <div className="mx-auto max-w-4xl px-8 py-10">
          {!state.selectedIntent ? (
            <OnboardingIntentSelector />
          ) : (
            <motion.div
              key={state.selectedIntent}
              initial={{ opacity: 0, x: 20 }}
              animate={{ opacity: 1, x: 0 }}
              transition={{ duration: 0.2 }}
            >
              <OnboardingPathContent />
            </motion.div>
          )}
        </div>
      </main>
    </div>
  )
}
