// This file is kept for backwards compatibility
// The main project layout is now handled by the route at src/routes/_public/project.$projectId.tsx
// The org overview is handled by src/routes/_public/organizations.$orgId.tsx

import { Navigate } from '@tanstack/react-router'

// Redirect to root which will handle org selection
export function ConsoleLayout() {
  return <Navigate to="/" />
}
