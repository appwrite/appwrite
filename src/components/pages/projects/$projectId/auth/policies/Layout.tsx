import { useMemo, useState, type ReactNode } from 'react'
import { Link, useNavigate } from '@tanstack/react-router'
import { Clock, KeyRound, Mail, Search, Users, UsersRound, X } from 'lucide-react'
import { cn } from '@/lib/utils'
import { Input } from '@/components/ui/input'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'

export type PoliciesSubTab =
  | 'users'
  | 'sessions'
  | 'emails'
  | 'memberships'
  | 'passwords'

type PoliciesLayoutProps = {
  projectId: string
  activeSubTab: PoliciesSubTab
  children: ReactNode
}

const NAV_ITEMS: Array<{
  id: PoliciesSubTab
  label: string
  to:
    | '/projects/$projectId/auth/policies/users'
    | '/projects/$projectId/auth/policies/sessions'
    | '/projects/$projectId/auth/policies/emails'
    | '/projects/$projectId/auth/policies/memberships'
    | '/projects/$projectId/auth/policies/passwords'
  icon: typeof Users
  keywords: string[]
}> = [
  {
    id: 'sessions',
    label: 'Sessions',
    to: '/projects/$projectId/auth/policies/sessions',
    icon: Clock,
    keywords: ['session', 'length', 'limit', 'invalidation', 'alerts'],
  },
  {
    id: 'users',
    label: 'Users',
    to: '/projects/$projectId/auth/policies/users',
    icon: Users,
    keywords: ['users', 'limit', 'maximum', 'count'],
  },
  {
    id: 'emails',
    label: 'Emails',
    to: '/projects/$projectId/auth/policies/emails',
    icon: Mail,
    keywords: [
      'email',
      'free',
      'gmail',
      'disposable',
      'mailinator',
      'alias',
      'signup',
    ],
  },
  {
    id: 'memberships',
    label: 'Memberships',
    to: '/projects/$projectId/auth/policies/memberships',
    icon: UsersRound,
    keywords: [
      'membership',
      'memberships',
      'privacy',
      'team',
      'mfa',
      'name',
      'hidden',
    ],
  },
  {
    id: 'passwords',
    label: 'Passwords',
    to: '/projects/$projectId/auth/policies/passwords',
    icon: KeyRound,
    keywords: ['password', 'history', 'dictionary', 'personal data'],
  },
]

export function PoliciesLayout({
  projectId,
  activeSubTab,
  children,
}: PoliciesLayoutProps) {
  const navigate = useNavigate()
  const [navSearch, setNavSearch] = useState('')

  const navItems = useMemo(() => NAV_ITEMS, [])

  const filteredNavItems = useMemo(() => {
    const q = navSearch.trim().toLowerCase()
    if (!q) return navItems
    return navItems.filter((item) => {
      const matchLabel = item.label.toLowerCase().includes(q)
      const matchKeyword = item.keywords.some((k) => k.includes(q))
      return matchLabel || matchKeyword
    })
  }, [navItems, navSearch])

  const activeLabel =
    navItems.find((item) => item.id === activeSubTab)?.label ?? 'Sessions'

  return (
    <div className="flex flex-col gap-4 lg:flex-row lg:gap-8">
      <div className="lg:hidden" aria-label="Policies section">
        <Select
          value={activeSubTab}
          onValueChange={(value) => {
            const item = navItems.find((n) => n.id === value)
            if (item) {
              navigate({
                to: item.to,
                params: { projectId },
              })
            }
          }}
        >
          <SelectTrigger size="sm" className="w-full h-9 text-[13px]">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {navItems.map((item) => (
              <SelectItem
                key={item.id}
                value={item.id}
                className="text-[13px]"
              >
                <span className="flex items-center gap-2">
                  <item.icon className="h-4 w-4 shrink-0" />
                  {item.label}
                </span>
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </div>

      <nav
        className="hidden lg:flex sticky top-4 w-48 shrink-0 flex-col gap-2 self-start"
        aria-label="Policies navigation"
      >
        <div className="relative w-full mb-2">
          <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground pointer-events-none" />
          <Input
            placeholder="Search policies..."
            value={navSearch}
            onChange={(e) => setNavSearch(e.target.value)}
            className={cn(
              'h-9 w-full rounded-md border border-border bg-accent/50 pl-10 pr-4 text-[13px] text-foreground placeholder:text-muted-foreground outline-none transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50',
              navSearch && 'pr-9',
            )}
          />
          {navSearch && (
            <button
              type="button"
              onClick={() => setNavSearch('')}
              className="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground rounded p-0.5"
              aria-label="Clear search"
            >
              <X className="h-3.5 w-3.5" />
            </button>
          )}
        </div>
        {filteredNavItems.length === 0 ? (
          <p className="px-3 py-2 text-[12px] text-muted-foreground">
            No matching policies
          </p>
        ) : (
          filteredNavItems.map((item) => {
            const Icon = item.icon
            return (
              <Link
                key={item.id}
                to={item.to}
                params={{ projectId }}
                className={cn(
                  'flex items-center gap-2 rounded-md px-3 py-2 text-[13px] font-medium transition-colors',
                  activeSubTab === item.id
                    ? 'bg-accent text-foreground'
                    : 'text-muted-foreground hover:bg-accent/50 hover:text-foreground',
                )}
              >
                <Icon className="h-4 w-4 shrink-0" />
                {item.label}
              </Link>
            )
          })
        )}
      </nav>

      <div className="min-w-0 flex-1">
        <h2 className="sr-only">{activeLabel} policies</h2>
        {children}
      </div>
    </div>
  )
}
