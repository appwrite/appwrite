import { useState } from 'react'
import { useOnboarding } from './OnboardingLayout'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { cn } from '@/lib/utils'
import {
  ArrowLeft,
  ArrowRight,
  Globe,
  Server,
  Check,
  ChevronDown,
} from 'lucide-react'
import { motion } from 'motion/react'
import { sdks, starterTemplates, regions } from './data'
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible'

type SdkCategory = 'client' | 'server'

const sdkCategories: { id: SdkCategory; label: string; icon: typeof Globe }[] =
  [
    { id: 'client', label: 'Client', icon: Globe },
    { id: 'server', label: 'Server', icon: Server },
  ]

const clientSdks = ['web', 'flutter', 'apple', 'android', 'react-native']
const serverSdks = ['node', 'python', 'php', 'ruby', 'deno']

export function OnboardingProjectCreate() {
  const {
    state,
    setPhase,
    setProjectName,
    setSelectedSdk,
    setSelectedTemplate,
    setSelectedRegion,
    createProject,
  } = useOnboarding()

  const [sdkCategory, setSdkCategory] = useState<SdkCategory>('client')
  const [showAdvanced, setShowAdvanced] = useState(false)

  const filteredSdks = sdks.filter((sdk) =>
    sdkCategory === 'client'
      ? clientSdks.includes(sdk.id)
      : serverSdks.includes(sdk.id),
  )

  const canCreate = state.projectName.trim().length > 0

  const handleCreate = () => {
    if (canCreate) {
      createProject()
    }
  }

  return (
    <div className="flex min-h-screen flex-col">
      {/* Header */}
      <header className="flex h-14 items-center justify-between border-b border-border px-6">
        <button
          onClick={() => setPhase('welcome')}
          className="flex items-center gap-2 text-sm text-muted-foreground transition-colors hover:text-foreground"
        >
          <ArrowLeft className="h-4 w-4" />
          Back
        </button>
        <img
          src="https://appwrite.io/images/logos/logo.svg"
          alt="Appwrite"
          className="h-6 w-6"
        />
        <div className="w-16" />
      </header>

      {/* Main content */}
      <main className="flex flex-1 items-start justify-center overflow-y-auto px-6 py-12">
        <motion.div
          initial={{ opacity: 0, y: 20 }}
          animate={{ opacity: 1, y: 0 }}
          transition={{ duration: 0.3 }}
          className="w-full max-w-2xl"
        >
          <h1 className="text-2xl font-semibold tracking-tight text-foreground">
            Create a new project
          </h1>
          <p className="mt-2 text-sm text-muted-foreground">
            Configure your project settings. All options can be changed later.
          </p>

          {/* Project name */}
          <div className="mt-8 space-y-6">
            <div className="space-y-2">
              <Label htmlFor="project-name" className="text-sm font-medium">
                Project name
              </Label>
              <Input
                id="project-name"
                placeholder="My Project"
                value={state.projectName}
                onChange={(e) => setProjectName(e.target.value)}
                className="h-11"
                autoFocus
              />
            </div>

            {/* Region */}
            <div className="space-y-2">
              <Label className="text-sm font-medium">Region</Label>
              <div className="grid grid-cols-5 gap-2">
                {regions.map((region) => (
                  <button
                    key={region.id}
                    onClick={() => setSelectedRegion(region.id)}
                    className={cn(
                      'flex flex-col items-center gap-1 rounded-lg border p-3 text-center transition-all',
                      state.selectedRegion === region.id
                        ? 'border-[#f02e65] bg-[#f02e65]/5'
                        : 'border-border hover:border-muted-foreground/50',
                    )}
                  >
                    <span className="text-lg">{region.flag}</span>
                    <span className="text-xs text-muted-foreground">
                      {region.name}
                    </span>
                  </button>
                ))}
              </div>
            </div>

            {/* SDK selection */}
            <div className="space-y-3">
              <Label className="text-sm font-medium">Primary SDK</Label>

              {/* SDK category tabs */}
              <div className="flex gap-1 rounded-lg bg-muted/50 p-1">
                {sdkCategories.map((cat) => (
                  <button
                    key={cat.id}
                    onClick={() => setSdkCategory(cat.id)}
                    className={cn(
                      'flex flex-1 items-center justify-center gap-2 rounded-md px-3 py-2 text-sm font-medium transition-all',
                      sdkCategory === cat.id
                        ? 'bg-background text-foreground'
                        : 'text-muted-foreground hover:text-foreground',
                    )}
                  >
                    <cat.icon className="h-4 w-4" />
                    {cat.label}
                  </button>
                ))}
              </div>

              {/* SDK grid */}
              <div className="grid grid-cols-5 gap-2">
                {filteredSdks.map((sdk) => (
                  <button
                    key={sdk.id}
                    onClick={() => setSelectedSdk(sdk.id)}
                    className={cn(
                      'relative flex flex-col items-center gap-2 rounded-lg border p-3 transition-all',
                      state.selectedSdk === sdk.id
                        ? 'border-[#f02e65] bg-[#f02e65]/5'
                        : 'border-border hover:border-muted-foreground/50',
                    )}
                  >
                    {state.selectedSdk === sdk.id && (
                      <div className="absolute right-1.5 top-1.5">
                        <Check className="h-3.5 w-3.5 text-[#f02e65]" />
                      </div>
                    )}
                    <div className="flex h-8 w-8 items-center justify-center rounded-md bg-muted text-xs font-medium">
                      {sdk.name.charAt(0)}
                    </div>
                    <span className="text-xs font-medium">{sdk.name}</span>
                  </button>
                ))}
              </div>
            </div>

            {/* Advanced options */}
            <Collapsible open={showAdvanced} onOpenChange={setShowAdvanced}>
              <CollapsibleTrigger className="flex w-full items-center justify-between py-2 text-sm text-muted-foreground hover:text-foreground">
                <span>Advanced options</span>
                <ChevronDown
                  className={cn(
                    'h-4 w-4 transition-transform',
                    showAdvanced && 'rotate-180',
                  )}
                />
              </CollapsibleTrigger>
              <CollapsibleContent className="space-y-4 pt-2">
                {/* Starter template */}
                <div className="space-y-2">
                  <Label className="text-sm font-medium">
                    Starter template
                  </Label>
                  <div className="grid gap-2">
                    {starterTemplates.map((template) => (
                      <button
                        key={template.id}
                        onClick={() => setSelectedTemplate(template.id)}
                        className={cn(
                          'flex items-start gap-3 rounded-lg border p-3 text-left transition-all',
                          state.selectedTemplate === template.id
                            ? 'border-[#f02e65] bg-[#f02e65]/5'
                            : 'border-border hover:border-muted-foreground/50',
                        )}
                      >
                        <div
                          className={cn(
                            'mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full border',
                            state.selectedTemplate === template.id
                              ? 'border-[#f02e65] bg-[#f02e65]'
                              : 'border-muted-foreground/30',
                          )}
                        >
                          {state.selectedTemplate === template.id && (
                            <Check className="h-3 w-3 text-white" />
                          )}
                        </div>
                        <div className="flex-1">
                          <p className="text-sm font-medium">{template.name}</p>
                          <p className="mt-0.5 text-xs text-muted-foreground">
                            {template.description}
                          </p>
                          {template.features.length > 0 && (
                            <div className="mt-2 flex flex-wrap gap-1">
                              {template.features.map((feature) => (
                                <span
                                  key={feature}
                                  className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium text-muted-foreground"
                                >
                                  {feature}
                                </span>
                              ))}
                            </div>
                          )}
                        </div>
                      </button>
                    ))}
                  </div>
                </div>
              </CollapsibleContent>
            </Collapsible>
          </div>

          {/* Actions */}
          <div className="mt-10 flex items-center justify-between border-t border-border pt-6">
            <button
              onClick={() => setPhase('welcome')}
              className="text-sm text-muted-foreground hover:text-foreground"
            >
              Cancel
            </button>
            <Button
              onClick={handleCreate}
              disabled={!canCreate}
              className="h-10 gap-2 bg-[#f02e65] text-white hover:bg-[#f02e65]/90"
            >
              Create project
              <ArrowRight className="h-4 w-4" />
            </Button>
          </div>
        </motion.div>
      </main>
    </div>
  )
}
