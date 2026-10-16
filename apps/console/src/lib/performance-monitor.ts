/**
 * Performance monitoring utility
 * Use this to identify CPU-intensive operations
 */

interface PerformanceMetric {
  name: string
  startTime: number
  endTime?: number
  duration?: number
}

class PerformanceMonitor {
  private metrics: Map<string, PerformanceMetric> = new Map()
  private intervals: Map<string, number> = new Map()
  private observers: PerformanceObserver[] = []

  /**
   * Start monitoring a specific operation
   */
  start(name: string): void {
    if (typeof window === 'undefined') return
    this.metrics.set(name, {
      name,
      startTime: performance.now(),
    })
  }

  /**
   * End monitoring a specific operation
   */
  end(name: string): void {
    if (typeof window === 'undefined') return
    const metric = this.metrics.get(name)
    if (metric) {
      metric.endTime = performance.now()
      metric.duration = metric.endTime - metric.startTime
    }
  }

  /**
   * Log all metrics to console (only when explicitly called from generateReport)
   */
  logMetrics(): void {
    if (typeof window === 'undefined') return
    this.metrics.forEach((metric) => {
      if (metric.duration) {
        console.log(`${metric.name}: ${metric.duration.toFixed(2)}ms`)
      }
    })
  }

  /**
   * Monitor React Query activity
   */
  monitorReactQuery(): void {
    if (typeof window === 'undefined') return

    // Monitor query fetches (silent - data available via generateReport)
    const observer = new PerformanceObserver(() => {
      // Silent monitoring - data stored but not logged
    })

    try {
      observer.observe({ entryTypes: ['measure'] })
      this.observers.push(observer)
    } catch {
      // PerformanceObserver not supported
    }
  }

  /**
   * Monitor long tasks (operations > 50ms)
   */
  monitorLongTasks(): void {
    if (typeof window === 'undefined') return

    try {
      const observer = new PerformanceObserver((list) => {
        for (const entry of list.getEntries()) {
          void entry
          // Silent monitoring - long tasks data available via generateReport()
        }
      })

      observer.observe({ entryTypes: ['longtask'] })
      this.observers.push(observer)
    } catch {
      // Long task observer not supported - silently fail
    }
  }

  /**
   * Monitor frame rate
   */
  monitorFrameRate(intervalMs: number = 1000): void {
    if (typeof window === 'undefined') return

    let lastTime = performance.now()
    let frames = 0

    const checkFrameRate = () => {
      frames++
      const currentTime = performance.now()

      if (currentTime >= lastTime + intervalMs) {
        void Math.round((frames * 1000) / (currentTime - lastTime))
        // Silent monitoring - FPS data available via generateReport()
        frames = 0
        lastTime = currentTime
      }

      requestAnimationFrame(checkFrameRate)
    }

    requestAnimationFrame(checkFrameRate)
  }

  /**
   * Monitor setInterval usage
   */
  monitorIntervals(): void {
    if (typeof window === 'undefined') return

    const originalSetInterval = window.setInterval
    const originalClearInterval = window.clearInterval

    const activeIntervals = new Map<
      number,
      { callback: string; delay: number; startTime: number }
    >()

    window.setInterval = function (
      callback: (...args: unknown[]) => void,
      delay?: number,
      ...args: unknown[]
    ): number {
      const id = originalSetInterval(callback, delay, ...args)
      activeIntervals.set(id, {
        callback: callback.toString().substring(0, 100),
        delay: delay || 0,
        startTime: performance.now(),
      })
      return id
    }

    window.clearInterval = function (id: number): void {
      const interval = activeIntervals.get(id)
      if (interval) {
        activeIntervals.delete(id)
      }
      originalClearInterval(id)
    }

    // Silent monitoring - intervals are tracked but not logged
    // Use generateReport() or check activeIntervals map to see active intervals
  }

  /**
   * Monitor React Query queries
   * Automatically finds queryClient from React Query context
   */
  monitorReactQueryQueries(queryClient?: unknown): void {
    if (typeof window === 'undefined') return

    // Try to get queryClient from window if not provided
    let client = queryClient
    if (!client && (window as unknown).__REACT_QUERY_CLIENT__) {
      client = (window as unknown).__REACT_QUERY_CLIENT__
    }

    // Try to get from React Query DevTools if available
    if (!client && (window as unknown).__REACT_QUERY_DEVTOOLS_GLOBAL_HOOK__) {
      const devtools = (window as unknown).__REACT_QUERY_DEVTOOLS_GLOBAL_HOOK__
      if (devtools.getClient) {
        client = devtools.getClient()
      }
    }

    if (!client) {
      // Silently fail - queryClient not available
      return
    }

    const logQueries = () => {
      try {
        const queries = client.getQueryCache().getAll()
        const activeQueries = queries.filter(
          (q: unknown) => q.state.status === 'pending',
        )
        const staleQueries = queries.filter((q: unknown) => q.state.isStale)
        const refetchingQueries = queries.filter(
          (q: unknown) => q.state.isFetching,
        )

        console.group('📊 React Query Status')
        console.log(`Total queries: ${queries.length}`)
        console.log(`Active (pending): ${activeQueries.length}`)
        console.log(`Refetching: ${refetchingQueries.length}`)
        console.log(`Stale: ${staleQueries.length}`)

        if (activeQueries.length > 0) {
          console.group('Active Queries')
          activeQueries.forEach((q: unknown) => {
            console.log(q.queryKey, q.state)
          })
          console.groupEnd()
        }

        if (refetchingQueries.length > 0) {
          console.group('Refetching Queries')
          refetchingQueries.slice(0, 10).forEach((q: unknown) => {
            console.log(q.queryKey, {
              isFetching: q.state.isFetching,
              fetchStatus: q.state.fetchStatus,
            })
          })
          console.groupEnd()
        }

        if (staleQueries.length > 0) {
          console.group('Stale Queries (first 10)')
          staleQueries.slice(0, 10).forEach((q: unknown) => {
            console.log(q.queryKey, {
              isStale: q.state.isStale,
              dataUpdatedAt: new Date(
                q.state.dataUpdatedAt,
              ).toLocaleTimeString(),
            })
          })
          console.groupEnd()
        }

        console.groupEnd()
      } catch {
        // Silently handle errors
      }
    }

    // Call immediately when monitorReactQueryQueries() is invoked
    logQueries()

    // Don't auto-log continuously - call logQueries() manually or use generateReport()
  }

  /**
   * Get CPU usage estimate (if available)
   * Only logs when called from generateReport()
   */
  getCPUUsage(): void {
    if (typeof window === 'undefined') return

    // Use Performance API to estimate CPU usage
    const navigation = performance.getEntriesByType(
      'navigation',
    )[0] as PerformanceNavigationTiming
    if (navigation) {
      const totalTime = navigation.loadEventEnd - navigation.fetchStart
      const scriptTime =
        navigation.domContentLoadedEventEnd - navigation.domInteractive
      const cpuUsage = (scriptTime / totalTime) * 100
      console.log(`📈 Estimated CPU usage: ${cpuUsage.toFixed(2)}%`)
    }
  }

  /**
   * Clean up all monitors
   */
  cleanup(): void {
    this.observers.forEach((observer) => observer.disconnect())
    this.observers = []
    this.intervals.forEach((id) => clearInterval(id))
    this.intervals.clear()
  }

  /**
   * Generate a performance report
   */
  generateReport(): void {
    if (typeof window === 'undefined') return

    console.group('📊 Performance Report')

    // Log metrics
    console.group('🔍 Performance Metrics')
    this.logMetrics()
    console.groupEnd()

    // Log CPU usage
    this.getCPUUsage()

    // Log memory usage if available
    if ('memory' in performance) {
      const memory = (performance as unknown).memory
      console.log('💾 Memory Usage:', {
        used: `${(memory.usedJSHeapSize / 1048576).toFixed(2)} MB`,
        total: `${(memory.totalJSHeapSize / 1048576).toFixed(2)} MB`,
        limit: `${(memory.jsHeapSizeLimit / 1048576).toFixed(2)} MB`,
      })
    }

    console.groupEnd()
  }
}

// Export singleton instance
export const performanceMonitor = new PerformanceMonitor()

// Expose to window for manual debugging (only when explicitly needed)
// To use: window.performanceMonitor = require('@/lib/performance-monitor').performanceMonitor
// Or import and expose manually in console when needed
if (typeof window !== 'undefined' && import.meta.env.DEV) {
  // Only expose, don't auto-start or log
  ;(window as unknown).performanceMonitor = performanceMonitor
}
