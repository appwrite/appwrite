import { GlobalRegistrator } from '@happy-dom/global-registrator'

type Options = Parameters<typeof GlobalRegistrator.register>[0]
type Timer = ReturnType<typeof setTimeout>
type Callback = (...args: unknown[]) => void

const runtime = {
  setTimeout,
  clearTimeout,
  setInterval,
  clearInterval,
  queueMicrotask,
}

const pendingTimeouts = new Set<Timer>()
const pendingIntervals = new Set<Timer>()

function trackedSetTimeout(
  callback: Callback,
  delay?: number,
  ...args: unknown[]
): Timer {
  const timer = runtime.setTimeout(() => {
    pendingTimeouts.delete(timer)
    callback(...args)
  }, delay)
  pendingTimeouts.add(timer)
  return timer
}

function trackedClearTimeout(timer?: Timer): void {
  if (timer === undefined) return
  pendingTimeouts.delete(timer)
  runtime.clearTimeout(timer)
}

function trackedSetInterval(
  callback: Callback,
  delay?: number,
  ...args: unknown[]
): Timer {
  const timer = runtime.setInterval(callback, delay, ...args)
  pendingIntervals.add(timer)
  return timer
}

function trackedClearInterval(timer?: Timer): void {
  if (timer === undefined) return
  pendingIntervals.delete(timer)
  runtime.clearInterval(timer)
}

function clearPendingTimers(): void {
  for (const timer of pendingTimeouts) runtime.clearTimeout(timer)
  for (const timer of pendingIntervals) runtime.clearInterval(timer)
  pendingTimeouts.clear()
  pendingIntervals.clear()
}

export function registerDom(options?: Options): void {
  GlobalRegistrator.register(options)
  // React DOM keeps the timers it sees on first load for the whole run, and a
  // window stops its own timers once it is unregistered.
  Object.assign(globalThis, {
    setTimeout: trackedSetTimeout,
    clearTimeout: trackedClearTimeout,
    setInterval: trackedSetInterval,
    clearInterval: trackedClearInterval,
    queueMicrotask: runtime.queueMicrotask,
  })
}

export async function unregisterDom(): Promise<void> {
  const [{ cleanup }, { toast }] = await Promise.all([
    import('@testing-library/react/pure'),
    import('sonner'),
  ])
  toast.dismiss()
  cleanup()
  clearPendingTimers()
  await GlobalRegistrator.unregister()
}
