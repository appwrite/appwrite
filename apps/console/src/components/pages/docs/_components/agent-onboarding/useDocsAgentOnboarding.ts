import { useCallback, useEffect, useMemo, useState } from 'react'
import { useDocsProject } from '@/components/pages/docs/project-context/DocsProjectContext'
import {
  DEFAULT_DOCS_ONBOARDING_AGENT_ID,
  getDocsOnboardingAgent,
  isDocsOnboardingAgentId,
  type DocsOnboardingAgentId,
} from '@/lib/docs/agent-onboarding'
import {
  buildConnectMcpPrompt,
  DOCS_CONNECT_PROMPT_INTRO,
  getAgentSetupUrl,
} from '@/lib/mcp-adoption'
import { AGENT_SETUP_ORIGIN } from '@/lib/seo/agent-setup'

const AGENT_STORAGE_KEY = 'docs:agent'

/** The reader's agent, remembered across docs pages. */
export function useDocsOnboardingAgent() {
  const [agentId, setAgentIdState] = useState<DocsOnboardingAgentId>(
    DEFAULT_DOCS_ONBOARDING_AGENT_ID,
  )

  useEffect(() => {
    try {
      const stored = window.localStorage.getItem(AGENT_STORAGE_KEY)
      if (isDocsOnboardingAgentId(stored)) setAgentIdState(stored)
    } catch {
      // Storage unavailable: keep the default agent.
    }
  }, [])

  const setAgentId = useCallback((next: DocsOnboardingAgentId) => {
    setAgentIdState(next)
    try {
      window.localStorage.setItem(AGENT_STORAGE_KEY, next)
    } catch {
      // Storage unavailable: the choice lasts for this page only.
    }
  }, [])

  return { agentId, agent: getDocsOnboardingAgent(agentId), setAgentId }
}

/**
 * The setup prompt for the reader's agent. It points at `/setup.md` on the
 * host serving the docs and names the reader's project when they picked one.
 */
export function useDocsAgentPrompt() {
  const { project } = useDocsProject()
  // Server render and first client render share the canonical URL; the host
  // swaps in after mount so preview deployments hand out their own guide.
  const [origin, setOrigin] = useState<string>(AGENT_SETUP_ORIGIN)

  useEffect(() => {
    setOrigin(window.location.origin)
  }, [])

  const prompt = useMemo(
    () =>
      buildConnectMcpPrompt({
        origin,
        intro: DOCS_CONNECT_PROMPT_INTRO,
        ...(project
          ? {
              projectId: project.id,
              projectName: project.name,
              endpoint: project.endpoint,
            }
          : {}),
      }),
    [origin, project],
  )

  return {
    prompt,
    project,
    intro: DOCS_CONNECT_PROMPT_INTRO,
    setupUrl: getAgentSetupUrl(origin),
  }
}
