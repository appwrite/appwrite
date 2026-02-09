import { useOnboarding } from '../OnboardingLayout'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import {
  ArrowLeft,
  ArrowRight,
  BookOpen,
  Compass,
  Database,
  ExternalLink,
  Globe,
  Folder,
  MessageSquare,
  Users,
  Zap,
} from 'lucide-react'
import { motion } from 'motion/react'

const features = [
  {
    id: 'auth',
    title: 'Authentication',
    description: 'User management, sessions, OAuth providers, and security',
    icon: Users,
    color: 'from-purple-500/20 to-pink-500/20',
    docs: 'https://appwrite.io/docs/products/auth',
  },
  {
    id: 'databases',
    title: 'Databases',
    description: 'Document storage, queries, indexes, and relationships',
    icon: Database,
    color: 'from-green-500/20 to-emerald-500/20',
    docs: 'https://appwrite.io/docs/products/databases',
  },
  {
    id: 'storage',
    title: 'Storage',
    description: 'File uploads, previews, transformations, and CDN',
    icon: Folder,
    color: 'from-orange-500/20 to-amber-500/20',
    docs: 'https://appwrite.io/docs/products/storage',
  },
  {
    id: 'functions',
    title: 'Functions',
    description: 'Serverless code execution, triggers, and scheduling',
    icon: Zap,
    color: 'from-yellow-500/20 to-lime-500/20',
    docs: 'https://appwrite.io/docs/products/functions',
  },
  {
    id: 'messaging',
    title: 'Messaging',
    description: 'Push notifications, SMS, and email delivery',
    icon: MessageSquare,
    color: 'from-blue-500/20 to-cyan-500/20',
    docs: 'https://appwrite.io/docs/products/messaging',
  },
  {
    id: 'sites',
    title: 'Sites',
    description: 'Deploy and host web applications at the edge',
    icon: Globe,
    color: 'from-indigo-500/20 to-violet-500/20',
    docs: 'https://appwrite.io/docs/products/sites',
  },
]

const resources = [
  {
    title: 'Quick start guides',
    description: 'Step-by-step tutorials for popular frameworks',
    href: 'https://appwrite.io/docs/quick-starts',
  },
  {
    title: 'API reference',
    description: 'Complete API documentation for all services',
    href: 'https://appwrite.io/docs/references',
  },
  {
    title: 'SDKs',
    description: 'Client and server SDKs for all platforms',
    href: 'https://appwrite.io/docs/sdks',
  },
  {
    title: 'Community',
    description: 'Join Discord and connect with other developers',
    href: 'https://appwrite.io/discord',
  },
]

export function ExplorePath() {
  const { setSelectedIntent, skipOnboarding } = useOnboarding()

  return (
    <div>
      {/* Header */}
      <div className="mb-8 flex items-center gap-4">
        <button
          onClick={() => setSelectedIntent(null)}
          className="flex items-center gap-2 text-sm text-muted-foreground hover:text-foreground"
        >
          <ArrowLeft className="h-4 w-4" />
          Back
        </button>
        <div className="h-4 w-px bg-border" />
        <div className="flex items-center gap-2">
          <Compass className="h-4 w-4 text-[#f02e65]" />
          <span className="text-sm font-medium">Explore everything</span>
        </div>
      </div>

      {/* Content */}
      <motion.div
        initial={{ opacity: 0, y: 20 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.3 }}
      >
        <div>
          <h2 className="text-lg font-medium">Explore Appwrite features</h2>
          <p className="mt-1 text-sm text-muted-foreground">
            Browse all available services and find what you need.
          </p>
        </div>

        {/* Features grid */}
        <div className="mt-6 grid gap-4 sm:grid-cols-2">
          {features.map((feature, index) => (
            <motion.a
              key={feature.id}
              href={feature.docs}
              target="_blank"
              rel="noopener noreferrer"
              initial={{ opacity: 0, y: 20 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ duration: 0.3, delay: index * 0.05 }}
              className={cn(
                'group relative flex flex-col rounded-xl border border-border p-5 transition-all',
                'hover:border-muted-foreground/50',
              )}
            >
              {/* Gradient background */}
              <div
                className={cn(
                  'absolute inset-0 rounded-xl bg-gradient-to-br opacity-0 transition-opacity group-hover:opacity-100',
                  feature.color,
                )}
              />

              {/* Content */}
              <div className="relative z-10">
                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
                  <feature.icon className="h-5 w-5 text-foreground" />
                </div>
                <h3 className="mt-4 text-base font-medium text-foreground">
                  {feature.title}
                </h3>
                <p className="mt-1 text-sm text-muted-foreground">
                  {feature.description}
                </p>
                <div className="mt-4 flex items-center gap-1 text-sm font-medium text-[#f02e65]">
                  View docs
                  <ExternalLink className="h-3.5 w-3.5" />
                </div>
              </div>
            </motion.a>
          ))}
        </div>

        {/* Resources */}
        <div className="mt-10">
          <h3 className="text-base font-medium">Resources</h3>
          <div className="mt-4 grid gap-3 sm:grid-cols-2">
            {resources.map((resource) => (
              <a
                key={resource.title}
                href={resource.href}
                target="_blank"
                rel="noopener noreferrer"
                className="flex items-start gap-3 rounded-lg border border-border p-4 transition-colors hover:bg-accent/50"
              >
                <BookOpen className="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground" />
                <div>
                  <p className="text-sm font-medium">{resource.title}</p>
                  <p className="mt-0.5 text-xs text-muted-foreground">
                    {resource.description}
                  </p>
                </div>
              </a>
            ))}
          </div>
        </div>

        {/* CTA */}
        <div className="mt-10 rounded-lg border border-border bg-muted/30 p-6 text-center">
          <h3 className="text-base font-medium">Ready to build?</h3>
          <p className="mt-1 text-sm text-muted-foreground">
            Head to the console to start building your application.
          </p>
          <Button
            onClick={skipOnboarding}
            className="mt-4 gap-2 bg-[#f02e65] text-white hover:bg-[#f02e65]/90"
          >
            Go to console
            <ArrowRight className="h-4 w-4" />
          </Button>
        </div>
      </motion.div>
    </div>
  )
}
