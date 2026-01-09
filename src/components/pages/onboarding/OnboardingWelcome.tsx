import { useOnboarding } from './OnboardingLayout'
import { Button } from '@/components/ui/button'
import { ArrowRight, ExternalLink } from 'lucide-react'
import { motion } from 'motion/react'

export function OnboardingWelcome() {
  const { setPhase, skipOnboarding } = useOnboarding()

  return (
    <div className="flex min-h-screen flex-col items-center justify-center px-6">
      {/* Logo */}
      <motion.div
        initial={{ opacity: 0, y: -20 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.5 }}
        className="mb-16"
      >
        <img
          src="https://appwrite.io/images/logos/logo.svg"
          alt="Appwrite"
          className="h-10 w-10"
        />
      </motion.div>

      {/* Main content */}
      <motion.div
        initial={{ opacity: 0, y: 20 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.5, delay: 0.1 }}
        className="max-w-xl text-center"
      >
        <h1 className="text-3xl font-semibold tracking-tight text-foreground sm:text-4xl">
          Build faster with Appwrite
        </h1>
        <p className="mt-4 text-lg text-muted-foreground">
          Backend infrastructure for web, mobile, and serverless applications.
          Authentication, databases, storage, and functions—ready in minutes.
        </p>
      </motion.div>

      {/* Actions */}
      <motion.div
        initial={{ opacity: 0, y: 20 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.5, delay: 0.2 }}
        className="mt-12 flex flex-col items-center gap-4"
      >
        <Button
          size="lg"
          onClick={() => setPhase('create-project')}
          className="h-12 min-w-[200px] gap-2 bg-[#f02e65] text-white hover:bg-[#f02e65]/90"
        >
          Create project
          <ArrowRight className="h-4 w-4" />
        </Button>

        <div className="flex items-center gap-6 text-sm">
          <button
            onClick={skipOnboarding}
            className="text-muted-foreground transition-colors hover:text-foreground"
          >
            Skip onboarding
          </button>
          <span className="text-border">|</span>
          <a
            href="https://appwrite.io/docs"
            target="_blank"
            rel="noopener noreferrer"
            className="flex items-center gap-1.5 text-muted-foreground transition-colors hover:text-foreground"
          >
            Documentation
            <ExternalLink className="h-3.5 w-3.5" />
          </a>
        </div>
      </motion.div>

      {/* Features grid */}
      <motion.div
        initial={{ opacity: 0 }}
        animate={{ opacity: 1 }}
        transition={{ duration: 0.5, delay: 0.4 }}
        className="mt-24 grid max-w-3xl grid-cols-2 gap-8 sm:grid-cols-4"
      >
        {[
          { label: 'Auth', desc: 'User management' },
          { label: 'Databases', desc: 'Structured data' },
          { label: 'Storage', desc: 'File management' },
          { label: 'Functions', desc: 'Serverless code' },
        ].map((feature) => (
          <div key={feature.label} className="text-center">
            <p className="text-sm font-medium text-foreground">
              {feature.label}
            </p>
            <p className="mt-1 text-xs text-muted-foreground">{feature.desc}</p>
          </div>
        ))}
      </motion.div>

      {/* Footer */}
      <motion.div
        initial={{ opacity: 0 }}
        animate={{ opacity: 1 }}
        transition={{ duration: 0.5, delay: 0.5 }}
        className="absolute bottom-8 text-xs text-muted-foreground"
      >
        <a
          href="https://appwrite.io"
          target="_blank"
          rel="noopener noreferrer"
          className="hover:text-foreground"
        >
          appwrite.io
        </a>
      </motion.div>
    </div>
  )
}
