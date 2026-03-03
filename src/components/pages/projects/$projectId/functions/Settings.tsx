import { useState, useEffect, useMemo } from 'react'
import { useParams, useNavigate } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import {
  useProjectFunction,
  useProjectRuntimes,
  useFunctionSpecifications,
  useDeleteFunction,
} from '@/lib/react-query/hooks'
import { sdk } from '@/lib/appwrite/sdk'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import type { Models } from '@appwrite.io/console'
import { toast } from 'sonner'
import { GitSettingsCard } from './GitSettingsCard'
import { CronScheduleEditor } from './CronScheduleEditor'
import { RuntimeIcon } from '@/components/global/shared/RuntimeIcon'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  hasUnavailableSpecifications,
  isSpecificationAllowedInPlan,
} from '@/lib/specifications'
import { Trash2, Plus, X } from 'lucide-react'
import { EventEditorModal } from '@/components/global/shared/EventEditor'

const CONTACT_SALES_URL =
  import.meta.env.VITE_CONTACT_SALES_URL ||
  'https://appwrite.io/contact-us/enterprise'

export function View() {
  const { projectId, functionId } = useParams({ strict: false })
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  const { data: func, isLoading: funcLoading } = useProjectFunction(
    projectId,
    functionId,
  )
  const { data: runtimesData } = useProjectRuntimes(projectId)
  const { data: specificationsData } = useFunctionSpecifications(projectId)
  const deleteFunctionMutation = useDeleteFunction(projectId)

  const [name, setName] = useState('')
  const [runtime, setRuntime] = useState('')
  const [entrypoint, setEntrypoint] = useState('')
  const [commands, setCommands] = useState('')
  const [timeout, setTimeout] = useState(15)
  const [logging, setLogging] = useState(true)
  const [schedule, setSchedule] = useState('')
  const [events, setEvents] = useState<string[]>([])
  const [specification, setSpecification] = useState('')
  const [enabled, setEnabled] = useState(false)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [eventDialogOpen, setEventDialogOpen] = useState(false)

  // Initialize state from function data
  useEffect(() => {
    if (func) {
      setName(func.name || '')
      setRuntime(func.runtime || '')
      setEntrypoint(func.entrypoint || '')
      setCommands(func.commands || '')
      setTimeout(func.timeout || 15)
      setLogging(func.logging ?? true)
      setSchedule(func.schedule || '')
      setEvents(func.events || [])
      setSpecification(func.specification || '')
      setEnabled(func.enabled !== false) // Default to true if not specified
    }
  }, [func])

  // Available runtimes for dropdown
  const runtimes = useMemo(() => {
    return runtimesData?.runtimes || []
  }, [runtimesData])

  // Available specifications for dropdown
  const specifications = useMemo(() => {
    return specificationsData?.specifications || []
  }, [specificationsData])

  // Update function mutation
  const updateFunctionMutation = useMutation({
    mutationFn: async (updates: Partial<Models.Function>) => {
      if (!projectId || !functionId || !func)
        throw new Error('Project ID, Function ID, and Function are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.functions.update({
        functionId,
        name: func.name,
        runtime: func.runtime as unknown,
        execute: func.execute || undefined,
        events: func.events || undefined,
        schedule: func.schedule || undefined,
        timeout: func.timeout || undefined,
        enabled: func.enabled ?? undefined,
        logging: func.logging ?? undefined,
        entrypoint: func.entrypoint || undefined,
        commands: func.commands || undefined,
        scopes: func.scopes || undefined,
        ...updates,
      })
    },
    onSuccess: () => {
      toast.success('Function updated successfully')
      queryClient.invalidateQueries({
        queryKey: ['function', 'project', projectId, functionId],
      })
      queryClient.invalidateQueries({
        queryKey: ['functions', 'project', projectId],
      })
    },
    onError: (error: unknown) => {
      toast.error(error.message || 'Failed to update function')
    },
  })

  const handleSaveName = () => {
    if (!name.trim()) {
      toast.error('Function name is required')
      return
    }
    updateFunctionMutation.mutate({ name })
  }

  const handleSaveRuntime = () => {
    if (!runtime || !entrypoint.trim()) {
      toast.error('Runtime and entrypoint are required')
      return
    }
    if (timeout < 1 || timeout > 900) {
      toast.error('Timeout must be between 1 and 900 seconds')
      return
    }
    updateFunctionMutation.mutate({
      runtime,
      entrypoint,
      specification: specification || undefined,
      timeout,
    })
  }

  const handleSaveCommands = () => {
    updateFunctionMutation.mutate({ commands })
  }

  const handleToggleLogging = (enabled: boolean) => {
    setLogging(enabled)
    updateFunctionMutation.mutate({ logging: enabled })
  }

  // Update enabled mutation
  const updateEnabledMutation = useMutation({
    mutationFn: async (enabled: boolean) => {
      if (!projectId || !functionId || !func)
        throw new Error('Project ID, Function ID, and Function are required')
      const projectSdk = sdk.forProject(projectId)
      return await projectSdk.functions.update({
        functionId,
        name: func.name,
        runtime: func.runtime as unknown,
        execute: func.execute || undefined,
        events: func.events || undefined,
        schedule: func.schedule || undefined,
        timeout: func.timeout || undefined,
        enabled,
        logging: func.logging || undefined,
        entrypoint: func.entrypoint || undefined,
        commands: func.commands || undefined,
        scopes: func.scopes || undefined,
      })
    },
    onSuccess: () => {
      toast.success(`Function has been ${enabled ? 'enabled' : 'disabled'}`)
      queryClient.invalidateQueries({
        queryKey: ['function', 'project', projectId, functionId],
      })
      queryClient.invalidateQueries({
        queryKey: ['functions', 'project', projectId],
      })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
      // Revert to original value on error
      if (func) {
        setEnabled(func.enabled !== false)
      }
    },
  })

  const handleEnabledToggle = (checked: boolean) => {
    setEnabled(checked)
  }

  const handleSaveSchedule = () => {
    updateFunctionMutation.mutate({ schedule: schedule || undefined })
  }

  const handleSaveEvents = () => {
    if (events.length > 100) {
      toast.error('Maximum 100 events allowed')
      return
    }
    updateFunctionMutation.mutate({ events })
  }

  const handleEventCreated = (eventString: string) => {
    const trimmed = eventString.trim()
    if (!trimmed || events.includes(trimmed) || events.length >= 100) return
    setEvents([...events, trimmed])
    setEventDialogOpen(false)
  }

  const handleRemoveEvent = (event: string) => {
    setEvents(events.filter((e) => e !== event))
  }

  const handleDeleteFunction = () => {
    if (!functionId) return
    deleteFunctionMutation.mutate(functionId, {
      onSuccess: () => {
        toast.success('Function deleted successfully')
        navigate({
          to: '/projects/$projectId/functions',
          params: { projectId: projectId! },
        })
      },
      onError: (error: unknown) => {
        toast.error(error.message || 'Failed to delete function')
      },
    })
    setDeleteDialogOpen(false)
  }

  // Check if arrays are equal
  const arraysEqual = (a: string[], b: string[]) => {
    if (a.length !== b.length) return false
    return a.every((val, idx) => val === b[idx])
  }

  if (funcLoading) {
    return (
      <div className="rounded-lg border border-border bg-card py-12 text-center">
        <p className="text-[13px] text-muted-foreground">Loading settings...</p>
      </div>
    )
  }

  return (
    <div className="flex-1">
      <div className="mx-auto w-full max-w-7xl px-4 pb-4 sm:px-6 sm:pb-6 pt-4 sm:pt-6">
        <div className="space-y-6">
          {/* Name Card */}
          {func && (
            <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
              <div className="px-6 py-4">
                <h3 className="text-[15px] font-semibold text-foreground">
                  Name
                </h3>
                <p className="text-[13px] text-muted-foreground mt-2">
                  Function name used for identification
                </p>
              </div>
              <div className="border-t border-border" />
              <div className="px-6 py-4">
                <Input
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  placeholder="Enter function name"
                  className="h-9 max-w-sm border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                />
              </div>
              <div className="px-6 py-4 border-t border-border bg-muted/30">
                <Button
                  size="sm"
                  className="h-9 text-[13px]"
                  disabled={
                    name === func?.name ||
                    !name.trim() ||
                    updateFunctionMutation.isPending
                  }
                  onClick={handleSaveName}
                >
                  Update
                </Button>
              </div>
            </div>
          )}

          {/* Function Information */}
          {func && (
            <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
              <div className="px-6 py-4">
                <h3 className="text-[15px] font-semibold text-foreground">
                  {func.name}
                </h3>
              </div>
              <div className="border-t border-border" />
              <div className="px-6 py-4">
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-3">
                    <Switch
                      id="toggle"
                      checked={enabled ?? false}
                      onCheckedChange={handleEnabledToggle}
                      disabled={updateEnabledMutation.isPending}
                    />
                    <Label
                      htmlFor="toggle"
                      className="text-[13px] text-foreground"
                    >
                      {enabled ? 'Enabled' : 'Disabled'}
                    </Label>
                  </div>
                </div>
                <div className="mt-4 space-y-1">
                  <p className="text-[13px] text-muted-foreground">
                    Function ID:{' '}
                    <span className="ml-1.5">
                      <CopyableId id={func.$id} size="sm" />
                    </span>
                  </p>
                  <p className="text-[13px] text-muted-foreground">
                    Created:{' '}
                    <DateTooltip
                      date={new Date(func.$createdAt)}
                      showFormattedDate
                      className="text-foreground"
                    />
                  </p>
                  <p className="text-[13px] text-muted-foreground">
                    Last updated:{' '}
                    <DateTooltip
                      date={new Date(func.$updatedAt || func.$createdAt)}
                      showFormattedDate
                      className="text-foreground"
                    />
                  </p>
                </div>
              </div>
              <div className="px-6 py-4 border-t border-border bg-muted/30">
                <Button
                  size="sm"
                  className="h-9 text-[13px]"
                  disabled={
                    enabled === (func.enabled !== false) ||
                    updateEnabledMutation.isPending
                  }
                  onClick={() => {
                    if (enabled !== (func.enabled !== false)) {
                      updateEnabledMutation.mutate(enabled)
                    }
                  }}
                >
                  Update
                </Button>
              </div>
            </div>
          )}

          {/* Build Commands Card */}
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                Build Commands
              </h3>
              <p className="text-[13px] text-muted-foreground mt-2">
                Commands to run during function build
              </p>
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4">
              <Input
                value={commands}
                onChange={(e) => setCommands(e.target.value)}
                placeholder="npm install"
                className="h-9 font-mono text-[13px]"
              />
            </div>
            <div className="px-6 py-4 border-t border-border bg-muted/30">
              <Button
                size="sm"
                className="h-9 text-[13px]"
                disabled={
                  commands === func?.commands ||
                  updateFunctionMutation.isPending
                }
                onClick={handleSaveCommands}
              >
                Update
              </Button>
            </div>
          </div>

          {/* Runtime Card */}
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                Runtime
              </h3>
              <p className="text-[13px] text-muted-foreground mt-2">
                Configure runtime execution settings for your function
              </p>
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4">
              <div className="space-y-3">
                <div>
                  <Label htmlFor="runtime" className="text-[13px]">
                    Runtime
                  </Label>
                  {runtimes.length > 0 ? (
                    <Select value={runtime} onValueChange={setRuntime}>
                      <SelectTrigger
                        id="runtime"
                        className="mt-2 h-9 border-border bg-background text-[13px]"
                      >
                        <SelectValue placeholder="Select runtime" />
                      </SelectTrigger>
                      <SelectContent>
                        {runtimes.map((rt) => (
                          <SelectItem key={rt.$id} value={rt.$id}>
                            {rt.name} {rt.version}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  ) : (
                    <Input
                      id="runtime"
                      value={runtime}
                      onChange={(e) => setRuntime(e.target.value)}
                      placeholder="Runtime ID"
                      className="mt-2 h-9 border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                    />
                  )}
                </div>
                <div>
                  <Label htmlFor="entrypoint" className="text-[13px]">
                    Entrypoint
                  </Label>
                  <Input
                    id="entrypoint"
                    value={entrypoint}
                    onChange={(e) => setEntrypoint(e.target.value)}
                    placeholder="src/index.js"
                    className="mt-2 h-9 font-mono border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                  />
                  <p className="mt-1 text-[12px] text-muted-foreground">
                    Path to your function's entry point
                  </p>
                </div>
                {specifications.length > 0 && (
                  <div>
                    <Label htmlFor="specification" className="text-[13px]">
                      Compute
                    </Label>
                    <Select
                      value={specification || undefined}
                      onValueChange={setSpecification}
                    >
                      <SelectTrigger
                        id="specification"
                        className="mt-2 h-9 border-border bg-background text-[13px]"
                      >
                        <SelectValue placeholder="Select specification" />
                      </SelectTrigger>
                      <SelectContent>
                        {specifications
                          .filter(
                            (spec) => spec.slug && spec.slug.trim() !== '',
                          )
                          .map((spec) => (
                            <SelectItem
                              key={spec.slug}
                              value={spec.slug}
                              disabled={!isSpecificationAllowedInPlan(spec)}
                            >
                              {spec.cpus} CPU, {spec.memory}MB RAM
                            </SelectItem>
                          ))}
                      </SelectContent>
                    </Select>
                    <p className="mt-1 text-[12px] text-muted-foreground">
                      Select the runtime specification for your function
                    </p>
                    {hasUnavailableSpecifications(specifications) && (
                      <div className="mt-3 rounded-lg border border-border bg-muted/30 px-3 py-2.5">
                        <p className="text-[12px] text-muted-foreground">
                          Need more resources?{' '}
                          <a
                            href="#"
                            className="font-medium text-foreground underline hover:no-underline"
                            onClick={(e) => {
                              e.preventDefault()
                              // TODO: Navigate to upgrade or contact sales
                            }}
                          >
                            Upgrade your plan
                          </a>{' '}
                          or{' '}
                          <a
                            href={CONTACT_SALES_URL}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="font-medium text-foreground underline hover:no-underline"
                          >
                            contact sales
                          </a>{' '}
                          to unlock additional specifications.
                        </p>
                      </div>
                    )}
                  </div>
                )}
                <div>
                  <Label htmlFor="timeout" className="text-[13px]">
                    Timeout
                  </Label>
                  <Input
                    id="timeout"
                    type="number"
                    min={1}
                    max={900}
                    value={timeout}
                    onChange={(e) => setTimeout(Number(e.target.value))}
                    className="mt-2 h-9 max-w-sm border-border bg-background text-[13px] text-foreground placeholder:text-muted-foreground focus:border-border focus:ring-0"
                  />
                  <p className="mt-1 text-[12px] text-muted-foreground">
                    Maximum execution time in seconds (1-900)
                  </p>
                </div>
              </div>
            </div>
            <div className="px-6 py-4 border-t border-border bg-muted/30">
              <Button
                size="sm"
                className="h-9 text-[13px]"
                disabled={
                  (runtime === func?.runtime &&
                    entrypoint === func?.entrypoint &&
                    specification === func?.specification &&
                    timeout === func?.timeout) ||
                  !runtime ||
                  !entrypoint.trim() ||
                  updateFunctionMutation.isPending
                }
                onClick={handleSaveRuntime}
              >
                Update
              </Button>
            </div>
          </div>

          {/* Cron Schedule Card */}
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                Schedule
              </h3>
              <p className="text-[13px] text-muted-foreground mt-2">
                Schedule your function to run automatically using cron
                expressions
              </p>
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4">
              <CronScheduleEditor
                value={schedule}
                onChange={setSchedule}
                disabled={updateFunctionMutation.isPending}
              />
            </div>
            <div className="px-6 py-4 border-t border-border bg-muted/30">
              <Button
                size="sm"
                className="h-9 text-[13px]"
                disabled={
                  schedule === func?.schedule ||
                  updateFunctionMutation.isPending
                }
                onClick={handleSaveSchedule}
              >
                Update
              </Button>
            </div>
          </div>

          {/* Git Repository Settings Card */}
          {func && <GitSettingsCard func={func} />}

          {/* Events Card */}
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                Events
              </h3>
              <p className="text-[13px] text-muted-foreground mt-2">
                Set the events that will trigger your function. Maximum 100
                events allowed.
              </p>
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4">
              <div className="space-y-3">
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  className="h-9 text-[13px]"
                  onClick={() => setEventDialogOpen(true)}
                  disabled={events.length >= 100}
                >
                  <Plus className="mr-1.5 h-4 w-4" />
                  Add event
                </Button>
                {events.length > 0 && (
                  <div className="space-y-2">
                    {events.map((event) => (
                      <div
                        key={event}
                        className="flex items-center justify-between rounded-md border border-border bg-background px-3 py-2"
                      >
                        <span className="text-[13px] font-mono">{event}</span>
                        <Button
                          variant="ghost"
                          size="sm"
                          className="h-7 w-7 p-0"
                          onClick={() => handleRemoveEvent(event)}
                        >
                          <X className="h-4 w-4" />
                        </Button>
                      </div>
                    ))}
                  </div>
                )}
                {events.length === 0 && (
                  <p className="text-[13px] text-muted-foreground">
                    No events configured
                  </p>
                )}
                <EventEditorModal
                  open={eventDialogOpen}
                  onOpenChange={setEventDialogOpen}
                  onCreated={handleEventCreated}
                  description="Set the events that will trigger your function. Maximum 100 events allowed."
                  projectId={projectId}
                />
              </div>
            </div>
            <div className="px-6 py-4 border-t border-border bg-muted/30">
              <Button
                size="sm"
                className="h-9 text-[13px]"
                disabled={
                  arraysEqual(events, func?.events || []) ||
                  updateFunctionMutation.isPending
                }
                onClick={handleSaveEvents}
              >
                Update
              </Button>
            </div>
          </div>

          {/* Logging Card */}
          <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                Logging
              </h3>
              <p className="text-[13px] text-muted-foreground mt-2">
                Enable logging for function executions
              </p>
            </div>
            <div className="border-t border-border" />
            <div className="px-6 py-4">
              <div className="flex items-center justify-between">
                <div>
                  <Label htmlFor="logging" className="text-[13px]">
                    Enable logging
                  </Label>
                  <p className="text-[12px] text-muted-foreground">
                    Log function execution output
                  </p>
                </div>
                <Switch
                  id="logging"
                  checked={logging}
                  onCheckedChange={handleToggleLogging}
                  disabled={updateFunctionMutation.isPending}
                />
              </div>
            </div>
          </div>

          {/* Delete Card */}
          <div className="rounded-xl border border-destructive/50 bg-card/50 overflow-hidden">
            <div className="px-6 py-4">
              <h3 className="text-[15px] font-semibold text-foreground">
                Delete Function
              </h3>
            </div>
            <div className="border-t border-destructive/20" />
            <div className="px-6 py-4">
              <p className="text-[13px] text-muted-foreground">
                Permanently delete this function and all its data. This action
                cannot be undone.
              </p>

              {/* Function Info Summary */}
              {func && (
                <div className="flex items-center gap-3 mt-4">
                  <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
                    {func.runtime ? (
                      <RuntimeIcon runtime={func.runtime} className="h-5 w-5" />
                    ) : (
                      <Trash2 className="h-5 w-5 text-muted-foreground" />
                    )}
                  </div>
                  <div className="flex-1 min-w-0">
                    <p className="text-[14px] font-medium text-foreground truncate">
                      {func.name || 'Unnamed Function'}
                    </p>
                    <p className="text-[12px] text-muted-foreground">
                      {func.runtime || 'No runtime'}
                    </p>
                  </div>
                </div>
              )}
            </div>
            <div className="px-6 py-4 border-t border-destructive/20 bg-destructive/5">
              <Dialog
                open={deleteDialogOpen}
                onOpenChange={setDeleteDialogOpen}
              >
                <DialogTrigger asChild>
                  <Button
                    variant="destructive"
                    size="sm"
                    className="h-9 text-[13px]"
                    disabled={deleteFunctionMutation.isPending}
                  >
                    <Trash2 className="mr-1.5 h-4 w-4" />
                    Delete function
                  </Button>
                </DialogTrigger>
                <DialogContent className="sm:max-w-md p-0">
                  <DialogHeader className="px-6 pt-6 text-left">
                    <DialogTitle>Delete Function</DialogTitle>
                    <DialogDescription className="text-[13px] mt-2">
                      Are you sure you want to delete{' '}
                      {func && (
                        <span className="font-medium text-foreground">
                          {func.name || 'this function'}
                        </span>
                      )}{' '}
                      and all its data? This action cannot be undone.
                    </DialogDescription>
                  </DialogHeader>
                  <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <Button
                      variant="outline"
                      onClick={() => setDeleteDialogOpen(false)}
                      disabled={deleteFunctionMutation.isPending}
                    >
                      Cancel
                    </Button>
                    <Button
                      variant="destructive"
                      onClick={handleDeleteFunction}
                      disabled={deleteFunctionMutation.isPending}
                    >
                      Delete
                    </Button>
                  </div>
                </DialogContent>
              </Dialog>
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
