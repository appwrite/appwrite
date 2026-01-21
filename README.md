# v4

## Performance Monitoring

The app includes a built-in performance monitoring utility for debugging CPU usage and performance issues. The monitor is available in development mode but runs silently by default.

### Usage

In the browser console (development mode only):

```javascript
// Monitor all active setInterval calls
window.performanceMonitor.monitorIntervals()

// Monitor React Query activity (auto-detects queryClient)
window.performanceMonitor.monitorReactQueryQueries()

// Monitor frame rate
window.performanceMonitor.monitorFrameRate()

// Generate a full performance report
window.performanceMonitor.generateReport()
```

### When to Use

Use the performance monitor when:
- CPU usage is unexpectedly high
- The app feels sluggish
- You need to identify what's causing performance issues
- Debugging specific performance problems

The monitor tracks intervals, React Query queries, frame rate, and memory usage without impacting performance when not actively monitoring.

For detailed debugging instructions, see [PERFORMANCE_DEBUG.md](./PERFORMANCE_DEBUG.md).
