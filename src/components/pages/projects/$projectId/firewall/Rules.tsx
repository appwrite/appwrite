import { useState, useEffect, useMemo } from 'react'
import {
  Shield,
  ShieldCheck,
  ShieldX,
  ShieldAlert,
  MoreHorizontal,
  Plus,
  Edit,
  Trash2,
  Copy,
  Check,
  CheckCircle2,
  XCircle,
  ArrowUp,
  ArrowDown,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import { Switch } from '@/components/ui/switch'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Skeleton } from '@/components/ui/skeleton'
import { mockFirewallRules, type FirewallRule } from '@/lib/utils/mock-data'

interface RulesTabProps {
  projectId: string
  searchValue: string
}

export function RulesTab({ searchValue }: RulesTabProps) {
  const [rules, setRules] = useState<FirewallRule[]>([])
  const [isLoading, setIsLoading] = useState(true)
  const [creatingRule, setCreatingRule] = useState(false)
  const [editingRule, setEditingRule] = useState<FirewallRule | null>(null)
  const [deletingRule, setDeletingRule] = useState<FirewallRule | null>(null)
  const [copiedRuleId, setCopiedRuleId] = useState<string | null>(null)

  // Form state
  const [formData, setFormData] = useState({
    name: '',
    description: '',
    action: 'block' as 'block' | 'allow' | 'challenge',
    priority: 100,
    enabled: true,
    conditions: {
      ipAddress: '',
      userAgent: '',
      path: '',
      method: '',
      country: '',
    },
    rateLimit: {
      enabled: false,
      requests: 100,
      window: 60, // seconds
    },
  })

  useEffect(() => {
    // Simulate loading
    setTimeout(() => {
      setRules(mockFirewallRules)
      setIsLoading(false)
    }, 500)
  }, [])

  // Listen for create rule event
  useEffect(() => {
    const handleCreateRule = () => {
      setFormData({
        name: '',
        description: '',
        action: 'block',
        priority: 100,
        enabled: true,
        conditions: {
          ipAddress: '',
          userAgent: '',
          path: '',
          method: '',
          country: '',
        },
        rateLimit: {
          enabled: false,
          requests: 100,
          window: 60,
        },
      })
      setCreatingRule(true)
    }

    window.addEventListener('firewall-create-rule', handleCreateRule)
    return () => {
      window.removeEventListener('firewall-create-rule', handleCreateRule)
    }
  }, [])

  const filteredRules = useMemo(() => {
    if (!searchValue.trim()) return rules

    const searchLower = searchValue.toLowerCase()
    return rules.filter(
      (rule) =>
        rule.name.toLowerCase().includes(searchLower) ||
        rule.description?.toLowerCase().includes(searchLower) ||
        rule.conditions.ipAddress?.toLowerCase().includes(searchLower) ||
        rule.conditions.path?.toLowerCase().includes(searchLower),
    )
  }, [rules, searchValue])

  const handleCreateRule = () => {
    const newRule: FirewallRule = {
      $id: `rule_${Date.now()}`,
      name: formData.name,
      description: formData.description,
      action: formData.action,
      priority: formData.priority,
      enabled: formData.enabled,
      conditions: formData.conditions,
      rateLimit: formData.rateLimit.enabled ? formData.rateLimit : undefined,
      createdAt: new Date().toISOString(),
      updatedAt: new Date().toISOString(),
      stats: {
        totalRequests: 0,
        blocked: 0,
        allowed: 0,
        challenged: 0,
        lastTriggered: null,
      },
    }

    setRules([...rules, newRule].sort((a, b) => b.priority - a.priority))
    setCreatingRule(false)
    setFormData({
      name: '',
      description: '',
      action: 'block',
      priority: 100,
      enabled: true,
      conditions: {
        ipAddress: '',
        userAgent: '',
        path: '',
        method: '',
        country: '',
      },
      rateLimit: {
        enabled: false,
        requests: 100,
        window: 60,
      },
    })
  }

  const handleUpdateRule = () => {
    if (!editingRule) return

    const updatedRules = rules.map((rule) =>
      rule.$id === editingRule.$id
        ? {
            ...rule,
            name: formData.name,
            description: formData.description,
            action: formData.action,
            priority: formData.priority,
            enabled: formData.enabled,
            conditions: formData.conditions,
            rateLimit: formData.rateLimit.enabled
              ? formData.rateLimit
              : undefined,
            updatedAt: new Date().toISOString(),
          }
        : rule,
    )

    setRules(updatedRules.sort((a, b) => b.priority - a.priority))
    setEditingRule(null)
    setFormData({
      name: '',
      description: '',
      action: 'block',
      priority: 100,
      enabled: true,
      conditions: {
        ipAddress: '',
        userAgent: '',
        path: '',
        method: '',
        country: '',
      },
      rateLimit: {
        enabled: false,
        requests: 100,
        window: 60,
      },
    })
  }

  const handleDeleteRule = () => {
    if (!deletingRule) return
    setRules(rules.filter((rule) => rule.$id !== deletingRule.$id))
    setDeletingRule(null)
  }

  const handleEditRule = (rule: FirewallRule) => {
    setEditingRule(rule)
    setFormData({
      name: rule.name,
      description: rule.description || '',
      action: rule.action,
      priority: rule.priority,
      enabled: rule.enabled,
      conditions: {
        ipAddress: rule.conditions.ipAddress || '',
        userAgent: rule.conditions.userAgent || '',
        path: rule.conditions.path || '',
        method: rule.conditions.method || '',
        country: rule.conditions.country || '',
      },
      rateLimit: rule.rateLimit || {
        enabled: false,
        requests: 100,
        window: 60,
      },
    })
  }

  const handleToggleEnabled = (rule: FirewallRule) => {
    setRules(
      rules.map((r) =>
        r.$id === rule.$id
          ? { ...r, enabled: !r.enabled, updatedAt: new Date().toISOString() }
          : r,
      ),
    )
  }

  const handlePriorityChange = (
    rule: FirewallRule,
    direction: 'up' | 'down',
  ) => {
    const currentIndex = rules.findIndex((r) => r.$id === rule.$id)
    if (currentIndex === -1) return

    const newPriority =
      direction === 'up' ? rule.priority + 10 : Math.max(0, rule.priority - 10)

    setRules(
      rules
        .map((r) =>
          r.$id === rule.$id
            ? {
                ...r,
                priority: newPriority,
                updatedAt: new Date().toISOString(),
              }
            : r,
        )
        .sort((a, b) => b.priority - a.priority),
    )
  }

  const copyRuleId = (ruleId: string) => {
    navigator.clipboard.writeText(ruleId)
    setCopiedRuleId(ruleId)
    setTimeout(() => setCopiedRuleId(null), 2000)
  }

  const getActionIcon = (action: string) => {
    switch (action) {
      case 'block':
        return <ShieldX className="h-4 w-4 text-red-500" />
      case 'allow':
        return <ShieldCheck className="h-4 w-4 text-emerald-500" />
      case 'challenge':
        return <ShieldAlert className="h-4 w-4 text-amber-500" />
      default:
        return <Shield className="h-4 w-4 text-muted-foreground" />
    }
  }

  const getActionBadge = (action: string) => {
    switch (action) {
      case 'block':
        return (
          <Badge variant="destructive" className="text-[11px]">
            Block
          </Badge>
        )
      case 'allow':
        return (
          <Badge variant="default" className="bg-emerald-500 text-[11px]">
            Allow
          </Badge>
        )
      case 'challenge':
        return (
          <Badge variant="secondary" className="bg-amber-500 text-[11px]">
            Challenge
          </Badge>
        )
      default:
        return (
          <Badge variant="outline" className="text-[11px]">
            {action}
          </Badge>
        )
    }
  }

  if (isLoading) {
    return (
      <div className="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6">
        <div className="rounded-xl border border-border bg-card/50">
          <div className="divide-y divide-border">
            {Array.from({ length: 5 }).map((_, i) => (
              <div key={i} className="p-4">
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-3">
                    <Skeleton className="h-5 w-5 rounded" />
                    <div className="space-y-2">
                      <Skeleton className="h-4 w-48" />
                      <Skeleton className="h-3 w-64" />
                    </div>
                  </div>
                  <Skeleton className="h-8 w-8 rounded" />
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>
    )
  }

  return (
    <div className="mx-auto w-full max-w-7xl px-4 py-6 sm:px-6">
      {filteredRules.length === 0 ? (
        <div>
          <EmptyState
            icon={Shield}
            title={searchValue ? undefined : 'No firewall rules'}
            description={
              searchValue
                ? undefined
                : 'Create your first firewall rule to protect your project from malicious requests.'
            }
            isEmpty={!searchValue}
            hasFilters={!!searchValue}
            variant="card"
            iconSize="md"
          />
          {!searchValue && (
            <div className="mt-4 flex justify-center">
              <Button
                variant="brandCta"
                onClick={() => {
                  if (typeof window !== 'undefined') {
                    const event = new CustomEvent('firewall-create-rule')
                    window.dispatchEvent(event)
                  }
                }}
                size="sm"
                className="h-8 gap-1.5 text-[13px] font-medium"
              >
                <Plus className="h-3.5 w-3.5" />
                Create rule
              </Button>
            </div>
          )}
        </div>
      ) : (
        <div className="rounded-xl border border-border bg-card/50">
          <Table>
            <TableHeader>
              <TableRow className="hover:bg-transparent border-b border-border">
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[50px]">
                  Status
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  Rule
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  Action
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  Priority
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  Conditions
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  Stats
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                  Last Triggered
                </TableHead>
                <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider w-[50px]"></TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {filteredRules.map((rule) => (
                <TableRow key={rule.$id}>
                  <TableCell className="px-4 py-3">
                    <div className="flex items-center">
                      {rule.enabled ? (
                        <CheckCircle2 className="h-4 w-4 text-emerald-500" />
                      ) : (
                        <XCircle className="h-4 w-4 text-muted-foreground" />
                      )}
                    </div>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <div className="flex flex-col gap-1">
                      <div className="flex items-center gap-2">
                        {getActionIcon(rule.action)}
                        <span className="text-[13px] font-medium text-foreground">
                          {rule.name}
                        </span>
                      </div>
                      {rule.description && (
                        <span className="text-[12px] text-muted-foreground">
                          {rule.description}
                        </span>
                      )}
                    </div>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    {getActionBadge(rule.action)}
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <div className="flex items-center gap-1">
                      <span className="text-[12px] font-mono text-muted-foreground">
                        {rule.priority}
                      </span>
                      <div className="flex flex-col">
                        <button
                          onClick={() => handlePriorityChange(rule, 'up')}
                          className="rounded p-0.5 text-muted-foreground hover:bg-accent hover:text-foreground"
                        >
                          <ArrowUp className="h-3 w-3" />
                        </button>
                        <button
                          onClick={() => handlePriorityChange(rule, 'down')}
                          className="rounded p-0.5 text-muted-foreground hover:bg-accent hover:text-foreground"
                        >
                          <ArrowDown className="h-3 w-3" />
                        </button>
                      </div>
                    </div>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <div className="flex flex-col gap-0.5">
                      {rule.conditions.ipAddress && (
                        <span className="text-[11px] text-muted-foreground">
                          IP: {rule.conditions.ipAddress}
                        </span>
                      )}
                      {rule.conditions.path && (
                        <span className="text-[11px] text-muted-foreground">
                          Path: {rule.conditions.path}
                        </span>
                      )}
                      {rule.conditions.country && (
                        <span className="text-[11px] text-muted-foreground">
                          Country: {rule.conditions.country}
                        </span>
                      )}
                      {rule.rateLimit && (
                        <span className="text-[11px] text-muted-foreground">
                          Rate limit: {rule.rateLimit.requests}/
                          {rule.rateLimit.window}s
                        </span>
                      )}
                    </div>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <div className="flex flex-col gap-0.5">
                      <span className="text-[12px] text-foreground">
                        {rule.stats.totalRequests.toLocaleString()} total
                      </span>
                      {rule.stats.blocked > 0 && (
                        <span className="text-[11px] text-red-500">
                          {rule.stats.blocked.toLocaleString()} blocked
                        </span>
                      )}
                      {rule.stats.allowed > 0 && (
                        <span className="text-[11px] text-emerald-500">
                          {rule.stats.allowed.toLocaleString()} allowed
                        </span>
                      )}
                    </div>
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    {rule.stats.lastTriggered ? (
                      <DateTooltip
                        date={rule.stats.lastTriggered}
                        className="text-[12px] text-muted-foreground"
                      />
                    ) : (
                      <span className="text-[12px] text-muted-foreground">
                        Never
                      </span>
                    )}
                  </TableCell>
                  <TableCell className="px-4 py-3">
                    <DropdownMenu>
                      <DropdownMenuTrigger asChild>
                        <Button
                          variant="ghost"
                          size="sm"
                          className="h-8 w-8 p-0"
                        >
                          <MoreHorizontal className="h-4 w-4" />
                        </Button>
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="end">
                        <DropdownMenuItem onClick={() => handleEditRule(rule)}>
                          <Edit className="mr-1.5 h-3.5 w-3.5" />
                          Update
                        </DropdownMenuItem>
                        <DropdownMenuItem
                          onClick={() => handleToggleEnabled(rule)}
                        >
                          {rule.enabled ? (
                            <>
                              <XCircle className="mr-1.5 h-3.5 w-3.5" />
                              Disable
                            </>
                          ) : (
                            <>
                              <CheckCircle2 className="mr-1.5 h-3.5 w-3.5" />
                              Enable
                            </>
                          )}
                        </DropdownMenuItem>
                        <DropdownMenuItem onClick={() => copyRuleId(rule.$id)}>
                          {copiedRuleId === rule.$id ? (
                            <>
                              <Check className="mr-1.5 h-3.5 w-3.5 text-emerald-500" />
                              Copied
                            </>
                          ) : (
                            <>
                              <Copy className="mr-1.5 h-3.5 w-3.5" />
                              Copy ID
                            </>
                          )}
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                          onClick={() => setDeletingRule(rule)}
                          className="text-red-500 focus:text-red-500"
                        >
                          <Trash2 className="mr-1.5 h-3.5 w-3.5" />
                          Delete
                        </DropdownMenuItem>
                      </DropdownMenuContent>
                    </DropdownMenu>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      )}

      {/* Create Rule Dialog */}
      <Dialog open={creatingRule} onOpenChange={setCreatingRule}>
        <DialogContent className="sm:max-w-2xl p-0 max-h-[90dvh] overflow-y-auto">
          <DialogHeader className="px-6 pt-6 text-left">
            <DialogTitle>Create firewall rule</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Define a new rule to protect your project from malicious requests.
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />

          <div className="px-6 pb-4 pt-0 space-y-4">
            <div className="space-y-2">
              <Label htmlFor="name">Rule name</Label>
              <Input
                id="name"
                value={formData.name}
                onChange={(e) =>
                  setFormData({ ...formData, name: e.target.value })
                }
                placeholder="e.g., Block suspicious IPs"
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="description">Description</Label>
              <Textarea
                id="description"
                value={formData.description}
                onChange={(e) =>
                  setFormData({ ...formData, description: e.target.value })
                }
                placeholder="Optional description of what this rule does"
                rows={2}
              />
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="action">Action</Label>
                <Select
                  value={formData.action}
                  onValueChange={(value: 'block' | 'allow' | 'challenge') =>
                    setFormData({ ...formData, action: value })
                  }
                >
                  <SelectTrigger id="action">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="block">Block</SelectItem>
                    <SelectItem value="allow">Allow</SelectItem>
                    <SelectItem value="challenge">Challenge</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-2">
                <Label htmlFor="priority">Priority</Label>
                <Input
                  id="priority"
                  type="number"
                  value={formData.priority}
                  onChange={(e) =>
                    setFormData({
                      ...formData,
                      priority: parseInt(e.target.value) || 0,
                    })
                  }
                  min={0}
                  max={1000}
                />
              </div>
            </div>

            <div className="space-y-4 border-t border-border pt-4">
              <h4 className="text-[13px] font-medium text-foreground">
                Conditions
              </h4>

              <div className="space-y-2">
                <Label htmlFor="ipAddress">IP Address (optional)</Label>
                <Input
                  id="ipAddress"
                  value={formData.conditions.ipAddress}
                  onChange={(e) =>
                    setFormData({
                      ...formData,
                      conditions: {
                        ...formData.conditions,
                        ipAddress: e.target.value,
                      },
                    })
                  }
                  placeholder="e.g., 192.168.1.1 or 192.168.1.0/24"
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="path">Path (optional)</Label>
                <Input
                  id="path"
                  value={formData.conditions.path}
                  onChange={(e) =>
                    setFormData({
                      ...formData,
                      conditions: {
                        ...formData.conditions,
                        path: e.target.value,
                      },
                    })
                  }
                  placeholder="e.g., /api/admin/*"
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="method">HTTP Method (optional)</Label>
                <Select
                  value={formData.conditions.method}
                  onValueChange={(value) =>
                    setFormData({
                      ...formData,
                      conditions: { ...formData.conditions, method: value },
                    })
                  }
                >
                  <SelectTrigger id="method">
                    <SelectValue placeholder="Any method" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="">Any method</SelectItem>
                    <SelectItem value="GET">GET</SelectItem>
                    <SelectItem value="POST">POST</SelectItem>
                    <SelectItem value="PUT">PUT</SelectItem>
                    <SelectItem value="DELETE">DELETE</SelectItem>
                    <SelectItem value="PATCH">PATCH</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-2">
                <Label htmlFor="country">Country (optional)</Label>
                <Input
                  id="country"
                  value={formData.conditions.country}
                  onChange={(e) =>
                    setFormData({
                      ...formData,
                      conditions: {
                        ...formData.conditions,
                        country: e.target.value,
                      },
                    })
                  }
                  placeholder="e.g., US, GB, CN (ISO 3166-1 alpha-2)"
                />
              </div>
            </div>

            <div className="space-y-4 border-t border-border pt-4">
              <div className="flex items-center justify-between">
                <div>
                  <h4 className="text-[13px] font-medium text-foreground">
                    Rate limiting
                  </h4>
                  <p className="text-[12px] text-muted-foreground">
                    Limit requests per time window
                  </p>
                </div>
                <Switch
                  checked={formData.rateLimit.enabled}
                  onCheckedChange={(checked) =>
                    setFormData({
                      ...formData,
                      rateLimit: { ...formData.rateLimit, enabled: checked },
                    })
                  }
                />
              </div>

              {formData.rateLimit.enabled && (
                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label htmlFor="rateLimitRequests">Requests</Label>
                    <Input
                      id="rateLimitRequests"
                      type="number"
                      value={formData.rateLimit.requests}
                      onChange={(e) =>
                        setFormData({
                          ...formData,
                          rateLimit: {
                            ...formData.rateLimit,
                            requests: parseInt(e.target.value) || 0,
                          },
                        })
                      }
                      min={1}
                    />
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="rateLimitWindow">Window (seconds)</Label>
                    <Input
                      id="rateLimitWindow"
                      type="number"
                      value={formData.rateLimit.window}
                      onChange={(e) =>
                        setFormData({
                          ...formData,
                          rateLimit: {
                            ...formData.rateLimit,
                            window: parseInt(e.target.value) || 60,
                          },
                        })
                      }
                      min={1}
                    />
                  </div>
                </div>
              )}
            </div>

            <div className="flex items-center justify-between border-t border-border pt-4">
              <div>
                <Label htmlFor="enabled">Enabled</Label>
                <p className="text-[12px] text-muted-foreground">
                  Rule will be active immediately
                </p>
              </div>
              <Switch
                id="enabled"
                checked={formData.enabled}
                onCheckedChange={(checked) =>
                  setFormData({ ...formData, enabled: checked })
                }
              />
            </div>
          </div>

          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button variant="outline" onClick={() => setCreatingRule(false)}>
              Cancel
            </Button>
            <Button
              variant="brandCta"
              onClick={handleCreateRule}
              disabled={!formData.name.trim()}
              className="gap-1.5"
            >
              <Plus className="h-4 w-4" />
              Create rule
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {/* Update Rule Dialog */}
      <Dialog
        open={editingRule !== null}
        onOpenChange={(open) => !open && setEditingRule(null)}
      >
        <DialogContent className="sm:max-w-2xl p-0 max-h-[90dvh] overflow-y-auto">
          <DialogHeader className="px-6 pt-6 text-left">
            <DialogTitle>Update firewall rule</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Modify the rule configuration and conditions.
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />

          <div className="px-6 pb-4 pt-0 space-y-4">
            {/* Same form fields as create dialog */}
            <div className="space-y-2">
              <Label htmlFor="edit-name">Rule name</Label>
              <Input
                id="edit-name"
                value={formData.name}
                onChange={(e) =>
                  setFormData({ ...formData, name: e.target.value })
                }
                placeholder="e.g., Block suspicious IPs"
              />
            </div>

            <div className="space-y-2">
              <Label htmlFor="edit-description">Description</Label>
              <Textarea
                id="edit-description"
                value={formData.description}
                onChange={(e) =>
                  setFormData({ ...formData, description: e.target.value })
                }
                placeholder="Optional description of what this rule does"
                rows={2}
              />
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-2">
                <Label htmlFor="edit-action">Action</Label>
                <Select
                  value={formData.action}
                  onValueChange={(value: 'block' | 'allow' | 'challenge') =>
                    setFormData({ ...formData, action: value })
                  }
                >
                  <SelectTrigger id="edit-action">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="block">Block</SelectItem>
                    <SelectItem value="allow">Allow</SelectItem>
                    <SelectItem value="challenge">Challenge</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-2">
                <Label htmlFor="edit-priority">Priority</Label>
                <Input
                  id="edit-priority"
                  type="number"
                  value={formData.priority}
                  onChange={(e) =>
                    setFormData({
                      ...formData,
                      priority: parseInt(e.target.value) || 0,
                    })
                  }
                  min={0}
                  max={1000}
                />
              </div>
            </div>

            <div className="space-y-4 border-t border-border pt-4">
              <h4 className="text-[13px] font-medium text-foreground">
                Conditions
              </h4>

              <div className="space-y-2">
                <Label htmlFor="edit-ipAddress">IP Address (optional)</Label>
                <Input
                  id="edit-ipAddress"
                  value={formData.conditions.ipAddress}
                  onChange={(e) =>
                    setFormData({
                      ...formData,
                      conditions: {
                        ...formData.conditions,
                        ipAddress: e.target.value,
                      },
                    })
                  }
                  placeholder="e.g., 192.168.1.1 or 192.168.1.0/24"
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="edit-path">Path (optional)</Label>
                <Input
                  id="edit-path"
                  value={formData.conditions.path}
                  onChange={(e) =>
                    setFormData({
                      ...formData,
                      conditions: {
                        ...formData.conditions,
                        path: e.target.value,
                      },
                    })
                  }
                  placeholder="e.g., /api/admin/*"
                />
              </div>

              <div className="space-y-2">
                <Label htmlFor="edit-method">HTTP Method (optional)</Label>
                <Select
                  value={formData.conditions.method}
                  onValueChange={(value) =>
                    setFormData({
                      ...formData,
                      conditions: { ...formData.conditions, method: value },
                    })
                  }
                >
                  <SelectTrigger id="edit-method">
                    <SelectValue placeholder="Any method" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="">Any method</SelectItem>
                    <SelectItem value="GET">GET</SelectItem>
                    <SelectItem value="POST">POST</SelectItem>
                    <SelectItem value="PUT">PUT</SelectItem>
                    <SelectItem value="DELETE">DELETE</SelectItem>
                    <SelectItem value="PATCH">PATCH</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              <div className="space-y-2">
                <Label htmlFor="edit-country">Country (optional)</Label>
                <Input
                  id="edit-country"
                  value={formData.conditions.country}
                  onChange={(e) =>
                    setFormData({
                      ...formData,
                      conditions: {
                        ...formData.conditions,
                        country: e.target.value,
                      },
                    })
                  }
                  placeholder="e.g., US, GB, CN (ISO 3166-1 alpha-2)"
                />
              </div>
            </div>

            <div className="space-y-4 border-t border-border pt-4">
              <div className="flex items-center justify-between">
                <div>
                  <h4 className="text-[13px] font-medium text-foreground">
                    Rate limiting
                  </h4>
                  <p className="text-[12px] text-muted-foreground">
                    Limit requests per time window
                  </p>
                </div>
                <Switch
                  checked={formData.rateLimit.enabled}
                  onCheckedChange={(checked) =>
                    setFormData({
                      ...formData,
                      rateLimit: { ...formData.rateLimit, enabled: checked },
                    })
                  }
                />
              </div>

              {formData.rateLimit.enabled && (
                <div className="grid grid-cols-2 gap-4">
                  <div className="space-y-2">
                    <Label htmlFor="edit-rateLimitRequests">Requests</Label>
                    <Input
                      id="edit-rateLimitRequests"
                      type="number"
                      value={formData.rateLimit.requests}
                      onChange={(e) =>
                        setFormData({
                          ...formData,
                          rateLimit: {
                            ...formData.rateLimit,
                            requests: parseInt(e.target.value) || 0,
                          },
                        })
                      }
                      min={1}
                    />
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="edit-rateLimitWindow">
                      Window (seconds)
                    </Label>
                    <Input
                      id="edit-rateLimitWindow"
                      type="number"
                      value={formData.rateLimit.window}
                      onChange={(e) =>
                        setFormData({
                          ...formData,
                          rateLimit: {
                            ...formData.rateLimit,
                            window: parseInt(e.target.value) || 60,
                          },
                        })
                      }
                      min={1}
                    />
                  </div>
                </div>
              )}
            </div>

            <div className="flex items-center justify-between border-t border-border pt-4">
              <div>
                <Label htmlFor="edit-enabled">Enabled</Label>
                <p className="text-[12px] text-muted-foreground">
                  Rule will be active immediately
                </p>
              </div>
              <Switch
                id="edit-enabled"
                checked={formData.enabled}
                onCheckedChange={(checked) =>
                  setFormData({ ...formData, enabled: checked })
                }
              />
            </div>
          </div>

          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button variant="outline" onClick={() => setEditingRule(null)}>
              Cancel
            </Button>
            <Button
              variant="brandCta"
              onClick={handleUpdateRule}
              disabled={!formData.name.trim()}
            >
              Update rule
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      {/* Delete Rule Dialog */}
      <Dialog
        open={deletingRule !== null}
        onOpenChange={(open) => !open && setDeletingRule(null)}
      >
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-left">
            <DialogTitle>Delete firewall rule</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Are you sure you want to delete "{deletingRule?.name}"? This
              action cannot be undone.
            </DialogDescription>
          </DialogHeader>

          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button variant="outline" onClick={() => setDeletingRule(null)}>
              Cancel
            </Button>
            <Button variant="destructive" onClick={handleDeleteRule}>
              Delete
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}
