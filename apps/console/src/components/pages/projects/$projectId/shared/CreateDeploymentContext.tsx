/**
 * Context for opening Create Deployment modals (Git, CLI, Manual) from the header
 * dropdown or from empty states in the Deployments view.
 */

import { createContext, useContext, useCallback, useState } from 'react'

export interface CreateDeploymentContextValue {
  openGitModal: () => void
  openCliModal: () => void
  openManualModal: () => void
}

const CreateDeploymentContext =
  createContext<CreateDeploymentContextValue | null>(null)

export function useCreateDeployment() {
  const ctx = useContext(CreateDeploymentContext)
  return ctx
}

export interface CreateDeploymentProviderProps {
  children: React.ReactNode
  onOpenGit: () => void
  onOpenCli: () => void
  onOpenManual: () => void
}

export function CreateDeploymentProvider({
  children,
  onOpenGit,
  onOpenCli,
  onOpenManual,
}: CreateDeploymentProviderProps) {
  const value: CreateDeploymentContextValue = {
    openGitModal: onOpenGit,
    openCliModal: onOpenCli,
    openManualModal: onOpenManual,
  }
  return (
    <CreateDeploymentContext.Provider value={value}>
      {children}
    </CreateDeploymentContext.Provider>
  )
}
