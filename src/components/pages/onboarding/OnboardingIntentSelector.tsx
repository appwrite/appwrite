import { useOnboarding } from './OnboardingLayout'
import { cn } from '@/lib/utils'
import {
  Globe,
  Users,
  Database,
  Folder,
  Zap,
  Compass,
  ArrowRight,
} from 'lucide-react'
import { motion } from 'motion/react'
import { onboardingPaths, type OnboardingIntent } from './data'

const intentIcons: Record<OnboardingIntent, typeof Globe> = {
  'deploy-site': Globe,
  'add-auth': Users,
  'create-database': Database,
  'manage-files': Folder,
  'run-functions': Zap,
  explore: Compass,
}

const intentColors: Record<OnboardingIntent, string> = {
  'deploy-site': 'from-blue-500/20 to-cyan-500/20',
  'add-auth': 'from-purple-500/20 to-pink-500/20',
  'create-database': 'from-green-500/20 to-emerald-500/20',
  'manage-files': 'from-orange-500/20 to-amber-500/20',
  'run-functions': 'from-yellow-500/20 to-lime-500/20',
  explore: 'from-gray-500/20 to-slate-500/20',
}

export function OnboardingIntentSelector() {
  const { state, setSelectedIntent } = useOnboarding()

  return (
    <div>
      {/* Header */}
      <motion.div
        initial={{ opacity: 0, y: -10 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.3 }}
      >
        <h1 className="text-2xl font-semibold tracking-tight text-foreground">
          What do you want to build?
        </h1>
        <p className="mt-2 text-sm text-muted-foreground">
          Choose a path to get started. You can switch or explore other features
          at any time.
        </p>
      </motion.div>

      {/* Intent grid */}
      <div className="mt-8 grid gap-4 sm:grid-cols-2">
        {onboardingPaths.map((path, index) => {
          const Icon = intentIcons[path.id]
          const gradientClass = intentColors[path.id]

          return (
            <motion.button
              key={path.id}
              initial={{ opacity: 0, y: 20 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ duration: 0.3, delay: index * 0.05 }}
              onClick={() => setSelectedIntent(path.id)}
              className={cn(
                'group relative flex flex-col items-start rounded-xl border border-border p-5 text-left transition-all',
                'hover:border-muted-foreground/50',
                'focus:outline-none focus-visible:ring-2 focus-visible:ring-ring',
              )}
            >
              {/* Gradient background */}
              <div
                className={cn(
                  'absolute inset-0 rounded-xl bg-gradient-to-br opacity-0 transition-opacity group-hover:opacity-100',
                  gradientClass,
                )}
              />

              {/* Content */}
              <div className="relative z-10">
                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
                  <Icon className="h-5 w-5 text-foreground" />
                </div>
                <h3 className="mt-4 text-base font-medium text-foreground">
                  {path.title}
                </h3>
                <p className="mt-1 text-sm text-muted-foreground">
                  {path.description}
                </p>
                <div className="mt-4 flex items-center gap-1 text-sm font-medium text-[#f02e65]">
                  Get started
                  <ArrowRight className="h-3.5 w-3.5 transition-transform group-hover:translate-x-1" />
                </div>
              </div>
            </motion.button>
          )
        })}
      </div>

      {/* Quick stats */}
      <motion.div
        initial={{ opacity: 0 }}
        animate={{ opacity: 1 }}
        transition={{ duration: 0.3, delay: 0.4 }}
        className="mt-12 rounded-lg border border-border bg-muted/30 p-4"
      >
        <div className="flex items-center justify-between text-sm">
          <div>
            <p className="font-medium text-foreground">
              Project: {state.projectName}
            </p>
            <p className="text-muted-foreground">
              SDK: {state.selectedSdk} • Region:{' '}
              {state.selectedRegion.toUpperCase()}
            </p>
          </div>
          <div className="text-right text-muted-foreground">
            <p>Project ID</p>
            <p className="font-mono text-xs">{state.projectId}</p>
          </div>
        </div>
      </motion.div>
    </div>
  )
}
