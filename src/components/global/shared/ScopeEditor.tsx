import { useMemo, useState, useRef, useEffect, useLayoutEffect } from 'react'
import { Checkbox } from '@/components/ui/checkbox'
import { Button } from '@/components/ui/button'
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from '@/components/ui/accordion'
import { Badge } from '@/components/ui/badge'
import { Separator } from '@/components/ui/separator'
import { cn } from '@/lib/utils'
import { getBaseEndpoint } from '@/lib/appwrite/sdk'
import {
  Users,
  Database,
  Zap,
  HardDrive,
  MessageSquare,
  Globe,
  MoreHorizontal,
  Minus,
} from 'lucide-react'

// Scope catalog with all available scopes
interface ScopeDefinition {
  scope: string
  description: string
  category: string
  icon: typeof Users
  legacyScopes?: string[] // Legacy scope variants (e.g., collections.read for tables.read)
}

const SCOPE_CATALOG: ScopeDefinition[] = [
  // Auth
  {
    scope: 'sessions.write',
    description: "Access to create, update and delete your project's sessions",
    category: 'Auth',
    icon: Users,
  },
  {
    scope: 'users.read',
    description: "Access to read your project's users",
    category: 'Auth',
    icon: Users,
  },
  {
    scope: 'users.write',
    description: "Access to create, update, and delete your project's users",
    category: 'Auth',
    icon: Users,
  },
  {
    scope: 'teams.read',
    description: "Access to read your project's teams",
    category: 'Auth',
    icon: Users,
  },
  {
    scope: 'teams.write',
    description: "Access to create, update, and delete your project's teams",
    category: 'Auth',
    icon: Users,
  },
  // Database
  {
    scope: 'databases.read',
    description: "Access to read your project's databases",
    category: 'Database',
    icon: Database,
  },
  {
    scope: 'databases.write',
    description:
      "Access to create, update, and delete your project's databases",
    category: 'Database',
    icon: Database,
  },
  {
    scope: 'tables.read',
    description: "Access to read your project's database tables",
    category: 'Database',
    icon: Database,
    legacyScopes: ['collections.read'],
  },
  {
    scope: 'tables.write',
    description:
      "Access to create, update, and delete your project's database tables",
    category: 'Database',
    icon: Database,
    legacyScopes: ['collections.write'],
  },
  {
    scope: 'columns.read',
    description: "Access to read your project's database table's columns",
    category: 'Database',
    icon: Database,
    legacyScopes: ['attributes.read'],
  },
  {
    scope: 'columns.write',
    description:
      "Access to create, update, and delete your project's database table's columns",
    category: 'Database',
    icon: Database,
    legacyScopes: ['attributes.write'],
  },
  {
    scope: 'indexes.read',
    description: "Access to read your project's database table's indexes",
    category: 'Database',
    icon: Database,
  },
  {
    scope: 'indexes.write',
    description:
      "Access to create, update, and delete your project's database table's indexes",
    category: 'Database',
    icon: Database,
  },
  {
    scope: 'rows.read',
    description: "Access to read your project's database rows",
    category: 'Database',
    icon: Database,
    legacyScopes: ['documents.read'],
  },
  {
    scope: 'rows.write',
    description:
      "Access to create, update, and delete your project's database rows",
    category: 'Database',
    icon: Database,
    legacyScopes: ['documents.write'],
  },
  // Cloud-only backup scopes
  {
    scope: 'policies.read',
    description: 'Access to read your database backup policies',
    category: 'Database',
    icon: Database,
  },
  {
    scope: 'policies.write',
    description: 'Access to create, update and delete your backup policies',
    category: 'Database',
    icon: Database,
  },
  {
    scope: 'archives.read',
    description: 'Access to read your database backup archives',
    category: 'Database',
    icon: Database,
  },
  {
    scope: 'archives.write',
    description: 'Access to create and delete your backup archives',
    category: 'Database',
    icon: Database,
  },
  {
    scope: 'restorations.read',
    description: 'Access to read your backup restorations',
    category: 'Database',
    icon: Database,
  },
  {
    scope: 'restorations.write',
    description: 'Access to create and delete your backup restorations',
    category: 'Database',
    icon: Database,
  },
  // Functions
  {
    scope: 'functions.read',
    description: "Access to read your project's functions and code deployments",
    category: 'Functions',
    icon: Zap,
  },
  {
    scope: 'functions.write',
    description:
      "Access to create, update, and delete your project's functions and code deployments",
    category: 'Functions',
    icon: Zap,
  },
  {
    scope: 'execution.read',
    description: "Access to read your project's execution logs",
    category: 'Functions',
    icon: Zap,
  },
  {
    scope: 'execution.write',
    description: "Access to execute your project's functions",
    category: 'Functions',
    icon: Zap,
  },
  // Storage
  {
    scope: 'files.read',
    description:
      "Access to read your project's storage files and preview images",
    category: 'Storage',
    icon: HardDrive,
  },
  {
    scope: 'files.write',
    description:
      "Access to create, update, and delete your project's storage files",
    category: 'Storage',
    icon: HardDrive,
  },
  {
    scope: 'buckets.read',
    description: "Access to read your project's storage buckets",
    category: 'Storage',
    icon: HardDrive,
  },
  {
    scope: 'buckets.write',
    description:
      "Access to create, update, and delete your project's storage buckets",
    category: 'Storage',
    icon: HardDrive,
  },
  // Messaging
  {
    scope: 'targets.read',
    description: "Access to read your project's messaging targets",
    category: 'Messaging',
    icon: MessageSquare,
  },
  {
    scope: 'targets.write',
    description:
      "Access to create, update, and delete your project's messaging targets",
    category: 'Messaging',
    icon: MessageSquare,
  },
  {
    scope: 'providers.read',
    description: "Access to read your project's messaging providers",
    category: 'Messaging',
    icon: MessageSquare,
  },
  {
    scope: 'providers.write',
    description:
      "Access to create, update, and delete your project's messaging providers",
    category: 'Messaging',
    icon: MessageSquare,
  },
  {
    scope: 'messages.read',
    description: "Access to read your project's messages",
    category: 'Messaging',
    icon: MessageSquare,
  },
  {
    scope: 'messages.write',
    description: "Access to create, update, and delete your project's messages",
    category: 'Messaging',
    icon: MessageSquare,
  },
  {
    scope: 'topics.read',
    description: "Access to read your project's messaging topics",
    category: 'Messaging',
    icon: MessageSquare,
  },
  {
    scope: 'topics.write',
    description:
      "Access to create, update, and delete your project's messaging topics",
    category: 'Messaging',
    icon: MessageSquare,
  },
  {
    scope: 'subscribers.read',
    description: "Access to read your project's messaging topic subscribers",
    category: 'Messaging',
    icon: MessageSquare,
  },
  {
    scope: 'subscribers.write',
    description:
      "Access to create, update, and delete your project's messaging topic subscribers",
    category: 'Messaging',
    icon: MessageSquare,
  },
  // Sites
  {
    scope: 'sites.read',
    description: "Access to read your project's sites and deployments",
    category: 'Sites',
    icon: Globe,
  },
  {
    scope: 'sites.write',
    description:
      "Access to create, update, and delete your project's sites and deployments",
    category: 'Sites',
    icon: Globe,
  },
  {
    scope: 'log.read',
    description: "Access to read your sites's logs",
    category: 'Sites',
    icon: Globe,
  },
  {
    scope: 'log.write',
    description: "Access to delete your site's logs",
    category: 'Sites',
    icon: Globe,
  },
  // Other
  {
    scope: 'locale.read',
    description: "Access to access your project's Locale service",
    category: 'Other',
    icon: MoreHorizontal,
  },
  {
    scope: 'avatars.read',
    description: "Access to access your project's Avatars service",
    category: 'Other',
    icon: MoreHorizontal,
  },
  {
    scope: 'health.read',
    description: "Access to read your project's health status",
    category: 'Other',
    icon: MoreHorizontal,
  },
  {
    scope: 'migrations.read',
    description: "Access to read your project's migration status",
    category: 'Other',
    icon: MoreHorizontal,
  },
  {
    scope: 'migrations.write',
    description: 'Access to create migrations',
    category: 'Other',
    icon: MoreHorizontal,
  },
  {
    scope: 'tokens.read',
    description: "Access to read your project's file tokens",
    category: 'Other',
    icon: MoreHorizontal,
  },
  {
    scope: 'tokens.write',
    description: 'Access to create file tokens',
    category: 'Other',
    icon: MoreHorizontal,
  },
]

// Category order
const CATEGORY_ORDER = [
  'Auth',
  'Database',
  'Functions',
  'Storage',
  'Messaging',
  'Sites',
  'Other',
]

// Legacy scope mapping (for backward compatibility)
const LEGACY_SCOPE_MAP: Record<string, string> = {
  'collections.read': 'tables.read',
  'collections.write': 'tables.write',
  'attributes.read': 'columns.read',
  'attributes.write': 'columns.write',
  'documents.read': 'rows.read',
  'documents.write': 'rows.write',
}

// Helper to check if environment is cloud (uses centralized SDK endpoint)
function isCloudEnvironment(): boolean {
  try {
    return getBaseEndpoint().includes('cloud.appwrite.io')
  } catch {
    return false
  }
}

// Helper to get all scope variants (newer + legacy)
function getScopeVariants(scope: string): string[] {
  const variants = [scope]
  const scopeDef = SCOPE_CATALOG.find((s) => s.scope === scope)
  if (scopeDef?.legacyScopes) {
    variants.push(...scopeDef.legacyScopes)
  }
  return variants
}

// Helper to get all available scopes (including variants)
export function getAllAvailableScopes(): string[] {
  const isCloud = isCloudEnvironment()
  const backupScopeList = [
    'policies.read',
    'policies.write',
    'archives.read',
    'archives.write',
    'restorations.read',
    'restorations.write',
  ]

  const allVariants = new Set<string>()
  SCOPE_CATALOG.forEach((scopeDef) => {
    // Filter out backup scopes if not cloud
    if (backupScopeList.includes(scopeDef.scope) && !isCloud) {
      return
    }
    const variants = getScopeVariants(scopeDef.scope)
    variants.forEach((v) => allVariants.add(v))
  })
  return Array.from(allVariants)
}

// Helper to convert legacy scopes to newer format for display
function normalizeScopeForDisplay(scope: string): string {
  return LEGACY_SCOPE_MAP[scope] || scope
}

// Helper to check if scope should be displayed (filter out legacy scopes)
function shouldDisplayScope(scope: string): boolean {
  return !Object.keys(LEGACY_SCOPE_MAP).includes(scope)
}

interface ScopeEditorProps {
  value: string[]
  onChange: (scopes: string[]) => void
  disabled?: boolean
}

export function ScopeEditor({
  value,
  onChange,
  disabled = false,
}: ScopeEditorProps) {
  const isCloud = isCloudEnvironment()
  const [openCategories, setOpenCategories] = useState<string[]>([])
  const openCategoriesRef = useRef<string[]>([])
  const previousValueRef = useRef<string[]>(value)
  const isUserInteractionRef = useRef(false)

  // Keep ref in sync with state
  useEffect(() => {
    openCategoriesRef.current = openCategories
  }, [openCategories])

  // Reset accordion state when value changes externally (not from user interaction)
  useEffect(() => {
    // Check if value changed externally (not from our internal handlers)
    const valueChanged =
      JSON.stringify(previousValueRef.current) !== JSON.stringify(value)
    if (valueChanged && !isUserInteractionRef.current) {
      // Value changed externally, reset accordion to all closed
      setOpenCategories([])
      openCategoriesRef.current = []
    }
    // Reset the flag after processing
    if (isUserInteractionRef.current) {
      isUserInteractionRef.current = false
    }
    previousValueRef.current = value
  }, [value])

  // Filter scopes catalog - exclude legacy scopes from display
  const availableScopes = useMemo(() => {
    return SCOPE_CATALOG.filter((scopeDef) => {
      // Filter out legacy scopes from display (they're handled for compatibility)
      return shouldDisplayScope(scopeDef.scope)
    })
  }, [])

  // Convert value (which may contain legacy scopes) to display format
  const displayScopes = useMemo(() => {
    const normalized = new Set<string>()
    value.forEach((scope) => {
      const normalizedScope = normalizeScopeForDisplay(scope)
      if (shouldDisplayScope(scope)) {
        normalized.add(normalizedScope)
      } else {
        // If it's a legacy scope, add its newer equivalent
        normalized.add(normalizedScope)
      }
    })
    return Array.from(normalized)
  }, [value])

  // Group scopes by category
  const scopesByCategory = useMemo(() => {
    const grouped: Record<string, ScopeDefinition[]> = {}
    CATEGORY_ORDER.forEach((category) => {
      grouped[category] = []
    })

    const backupScopeList = [
      'policies.read',
      'policies.write',
      'archives.read',
      'archives.write',
      'restorations.read',
      'restorations.write',
    ]

    // Separate backup scopes from regular scopes
    const backupScopes: ScopeDefinition[] = []
    const regularScopes: ScopeDefinition[] = []

    availableScopes.forEach((scopeDef) => {
      if (backupScopeList.includes(scopeDef.scope)) {
        // Only include backup scopes if cloud
        if (isCloud) {
          backupScopes.push(scopeDef)
        }
      } else {
        regularScopes.push(scopeDef)
      }
    })

    // Group regular scopes
    regularScopes.forEach((scopeDef) => {
      if (grouped[scopeDef.category]) {
        grouped[scopeDef.category].push(scopeDef)
      }
    })

    // For Database category, insert backup scopes after databases.write
    if (grouped.Database && backupScopes.length > 0) {
      const databasesWriteIndex = grouped.Database.findIndex(
        (s) => s.scope === 'databases.write',
      )

      if (databasesWriteIndex >= 0) {
        // Insert backup scopes after databases.write
        grouped.Database.splice(databasesWriteIndex + 1, 0, ...backupScopes)
      }
    }

    return grouped
  }, [availableScopes, isCloud])

  // Handle scope toggle
  const handleScopeToggle = (scope: string, checked: boolean) => {
    isUserInteractionRef.current = true
    const variants = getScopeVariants(scope)
    let newScopes: string[]

    if (checked) {
      // Add scope and its variants
      newScopes = [...new Set([...value, ...variants])]
    } else {
      // Remove scope and its variants
      newScopes = value.filter((s) => !variants.includes(s))
    }

    onChange(newScopes)
  }

  // Handle category toggle
  const handleCategoryToggle = (category: string, checked: boolean) => {
    isUserInteractionRef.current = true
    const categoryScopes = scopesByCategory[category] || []
    const allVariants = new Set<string>()

    categoryScopes.forEach((scopeDef) => {
      const variants = getScopeVariants(scopeDef.scope)
      variants.forEach((v) => allVariants.add(v))
    })

    let newScopes: string[]

    if (checked) {
      // Add all scopes in category
      newScopes = [...new Set([...value, ...Array.from(allVariants)])]
    } else {
      // Remove all scopes in category
      newScopes = value.filter((s) => !allVariants.has(s))
    }

    onChange(newScopes)
  }

  // Track if we're in the middle of a select/deselect all operation
  const isSelectingAllRef = useRef(false)

  // Handle select all
  const handleSelectAll = (e: React.MouseEvent<HTMLButtonElement>) => {
    e.preventDefault()
    e.stopPropagation()
    isSelectingAllRef.current = true
    isUserInteractionRef.current = true
    const allVariants = new Set<string>()
    availableScopes.forEach((scopeDef) => {
      const variants = getScopeVariants(scopeDef.scope)
      variants.forEach((v) => allVariants.add(v))
    })
    onChange(Array.from(allVariants))
    // Reset flag after state update
    requestAnimationFrame(() => {
      isSelectingAllRef.current = false
    })
  }

  // Handle deselect all
  const handleDeselectAll = (e: React.MouseEvent<HTMLButtonElement>) => {
    e.preventDefault()
    e.stopPropagation()
    isSelectingAllRef.current = true
    isUserInteractionRef.current = true
    onChange([])
    // Reset flag after state update
    requestAnimationFrame(() => {
      isSelectingAllRef.current = false
    })
  }

  // Custom onValueChange that prevents unwanted opens during select/deselect all
  const handleAccordionChange = (newValue: string[]) => {
    // Don't allow accordion to change if we're in the middle of select/deselect all
    if (isSelectingAllRef.current) {
      return
    }
    setOpenCategories(newValue)
    openCategoriesRef.current = newValue
  }

  // Preserve accordion state when scopes change externally
  useLayoutEffect(() => {
    if (isSelectingAllRef.current) {
      // Restore the previous accordion state
      setOpenCategories(openCategoriesRef.current)
    }
  }, [value])

  // Get category selection state
  const getCategoryState = (
    category: string,
  ): 'checked' | 'unchecked' | 'indeterminate' => {
    const categoryScopes = scopesByCategory[category] || []
    if (categoryScopes.length === 0) return 'unchecked'

    const selectedCount = categoryScopes.filter((scopeDef) =>
      displayScopes.includes(scopeDef.scope),
    ).length

    if (selectedCount === 0) return 'unchecked'
    if (selectedCount === categoryScopes.length) return 'checked'
    return 'indeterminate'
  }

  // Get selected count for category
  const getCategorySelectedCount = (category: string): number => {
    const categoryScopes = scopesByCategory[category] || []
    return categoryScopes.filter((scopeDef) =>
      displayScopes.includes(scopeDef.scope),
    ).length
  }

  return (
    <div className="space-y-4">
      {/* Action Buttons */}
      <div className="flex items-center justify-end gap-1.5">
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-7 px-2.5 text-[12px]"
          onClick={handleSelectAll}
          disabled={disabled}
        >
          Select all
        </Button>
        <Separator orientation="vertical" className="h-3" />
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="h-7 px-2.5 text-[12px]"
          onClick={handleDeselectAll}
          disabled={disabled}
        >
          Deselect all
        </Button>
      </div>

      {/* Scope Categories */}
      <Accordion
        type="multiple"
        value={openCategories}
        onValueChange={handleAccordionChange}
        className="w-full"
      >
        {CATEGORY_ORDER.map((category, categoryIndex) => {
          const categoryScopes = scopesByCategory[category] || []
          if (categoryScopes.length === 0) return null

          const Icon = categoryScopes[0]?.icon || MoreHorizontal
          const selectedCount = getCategorySelectedCount(category)
          const categoryState = getCategoryState(category)
          const isLastCategory = categoryIndex === CATEGORY_ORDER.length - 1

          return (
            <AccordionItem
              key={category}
              value={category}
              className={cn('border-b', isLastCategory && 'border-b-0')}
            >
              <AccordionTrigger
                className="hover:no-underline"
                onClick={(e) => {
                  // Prevent accordion toggle when clicking checkbox
                  if (
                    (e.target as HTMLElement).closest('[data-slot="checkbox"]')
                  ) {
                    e.stopPropagation()
                    return
                  }
                }}
              >
                <div className="flex flex-1 items-center justify-between pr-4">
                  <div className="flex items-center gap-3">
                    <div className="relative">
                      <Checkbox
                        checked={categoryState === 'checked'}
                        onCheckedChange={(checked) => {
                          handleCategoryToggle(category, checked === true)
                        }}
                        onClick={(e) => e.stopPropagation()}
                        disabled={disabled}
                        className={cn(
                          categoryState === 'indeterminate' &&
                            'bg-primary border-primary',
                        )}
                      />
                      {categoryState === 'indeterminate' && (
                        <Minus className="absolute left-0.5 top-0.5 h-3 w-3 text-primary-foreground pointer-events-none" />
                      )}
                    </div>
                    <Icon className="h-4 w-4 text-muted-foreground" />
                    <span className="text-[13px] font-medium text-foreground">
                      {category}
                    </span>
                  </div>
                  <Badge variant="secondary" className="text-[12px]">
                    {selectedCount} {selectedCount === 1 ? 'Scope' : 'Scopes'}
                  </Badge>
                </div>
              </AccordionTrigger>
              <AccordionContent>
                <div className="space-y-2 pt-2">
                  {categoryScopes.map((scopeDef) => {
                    const isSelected = displayScopes.includes(scopeDef.scope)
                    return (
                      <label
                        key={scopeDef.scope}
                        className={cn(
                          'flex items-start gap-3 rounded-md px-3 py-2.5 transition-colors',
                          'hover:bg-accent/50 cursor-pointer',
                          disabled && 'cursor-not-allowed opacity-50',
                        )}
                      >
                        <Checkbox
                          checked={isSelected}
                          onCheckedChange={(checked) => {
                            handleScopeToggle(scopeDef.scope, checked === true)
                          }}
                          disabled={disabled}
                          className="mt-0.5"
                        />
                        <div className="flex-1 space-y-0.5">
                          <div className="text-[13px] font-mono text-foreground">
                            {scopeDef.scope}
                          </div>
                          <div className="text-[12px] text-muted-foreground">
                            {scopeDef.description}
                          </div>
                        </div>
                      </label>
                    )
                  })}
                </div>
              </AccordionContent>
            </AccordionItem>
          )
        })}
      </Accordion>
    </div>
  )
}
