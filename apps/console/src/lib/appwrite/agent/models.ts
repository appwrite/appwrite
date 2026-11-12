/**
 * Agent response models, copied from the generated SDK on the cloud branch that
 * carries the agent endpoints. Delete with `service.ts`.
 */

/**
 * Agent Conversation
 */
export type AgentConversation = {
  /**
   * Conversation ID.
   */
  $id: string
  /**
   * Conversation creation time.
   */
  $createdAt: string
  /**
   * Conversation update time.
   */
  $updatedAt: string
  /**
   * Creator user ID.
   */
  userId: string
  /**
   * Owning team ID.
   */
  teamId: string
  /**
   * Conversation title.
   */
  title: string
  /**
   * Conversation status.
   */
  status: string
  /**
   * Conversation lock state.
   */
  lockState: string
  /**
   * Conversation lock owner.
   */
  lockBy: string
  /**
   * Conversation lock time.
   */
  lockAt: string
  /**
   * Conversation lock lease expiry.
   */
  lockLeaseAt: string
  /**
   * Conversation lock reason.
   */
  lockReason: string
  /**
   * Active message ID.
   */
  activeMessageId: string
  /**
   * Last agent message ID.
   */
  lastAgentMessageId: string
  /**
   * Stop request time.
   */
  stopRequestedAt: string
  /**
   * Context version.
   */
  contextVersion: number
  /**
   * Model identifier.
   */
  modelName: string
  /**
   * Model temperature.
   */
  modelTemp: number
  /**
   * Optional custom LLM model ID (agentModels). Empty uses the Appwrite default.
   */
  modelId: string
  /**
   * Automation ID that created this conversation, if any.
   */
  automationId: string
}
/**
 * Agent Message
 */
export type AgentMessage = {
  /**
   * Message ID.
   */
  $id: string
  /**
   * Message creation time.
   */
  $createdAt: string
  /**
   * Message update time.
   */
  $updatedAt: string
  /**
   * Conversation ID.
   */
  conversationId: string
  /**
   * Parent message ID.
   */
  parentMessageId: string
  /**
   * Message role.
   */
  role: string
  /**
   * Message status.
   */
  status: string
  /**
   * Run ID.
   */
  runId: string
  /**
   * Message content type.
   */
  contentType: string
  /**
   * Message content text.
   */
  contentText: string
  /**
   * Optional current team ID.
   */
  contextTeamId: string
  /**
   * Optional current project ID.
   */
  contextProjectId: string
  /**
   * Optional current organization ID.
   */
  contextOrganizationId: string
  /**
   * Optional current page path.
   */
  contextPagePath: string
  /**
   * Optional current page title.
   */
  contextPageTitle: string
  /**
   * Optional current page URL.
   */
  contextPageUrl: string
  /**
   * Optional attachment file IDs stored in attachements bucket.
   */
  attachments: string[]
  /**
   * Tool calls executed while generating this message.
   */
  tools: AgentTool[]
  /**
   * Supervisor-selected agent for this turn.
   */
  routeAgent: string
  /**
   * Supervisor next hop for this turn.
   */
  routeNext: string
  /**
   * Supervisor routing reason.
   */
  routeReason: string
  /**
   * Ordered turn timeline (route, subagent, tool, status events) for UI rendering.
   */
  timeline: Record<string, unknown>[]
  /**
   * Edited source message ID.
   */
  editedFromMessageId: string
  /**
   * Retry source message ID.
   */
  retryFromMessageId: string
  /**
   * Failure code when status is failed (for example `llm_failed`).
   */
  errorCode: string
  /**
   * Human-readable failure detail when status is failed.
   */
  errorMessage: string
  /**
   * User feedback score for the agent message. `1` for thumbs up, `-1` for thumbs down, `0` when unset.
   */
  score: number
}
/**
 * Agent Tool
 */
export type AgentTool = {
  /**
   * Tool call document ID.
   */
  $id: string
  /**
   * Tool call creation time.
   */
  $createdAt: string
  /**
   * Tool call update time.
   */
  $updatedAt: string
  /**
   * Conversation ID.
   */
  conversationId: string
  /**
   * Agent message ID this tool call belongs to.
   */
  messageId: string
  /**
   * Run ID.
   */
  runId: string
  /**
   * Provider tool call ID.
   */
  toolCallId: string
  /**
   * Tool name.
   */
  name: string
  /**
   * Subagent that invoked the tool.
   */
  agent: string
  /**
   * Execution status.
   */
  status: string
  /**
   * Tool arguments as JSON string.
   */
  argumentsJson: string
  /**
   * Tool result as text.
   */
  resultText: string
  /**
   * Tool result JSON if available.
   */
  resultJson: string
  /**
   * Tool execution error when status is error.
   */
  errorMessage: string
}
/**
 * Agent MCP Connection
 */
export type AgentMcpConnection = {
  /**
   * MCP connection ID (engine connection id).
   */
  $id: string
  /**
   * Connection creation time.
   */
  $createdAt: string
  /**
   * Connection update time.
   */
  $updatedAt: string
  /**
   * Creator user ID.
   */
  userId: string
  /**
   * Owning team ID.
   */
  teamId: string
  /**
   * Display name.
   */
  name: string
  /**
   * MCP server URL.
   */
  url: string
  /**
   * Optional description.
   */
  description: string
  /**
   * Whether the connection is sent on agent turns.
   */
  enabled: boolean
  /**
   * Whether OAuth tokens are stored for this connection.
   */
  hasTokens: boolean
  /**
   * Whether OAuth client info is stored for this connection.
   */
  hasClientInfo: boolean
  /**
   * Optional connection status.
   */
  status: string
}
/**
 * Agent Memory
 */
export type AgentMemory = {
  /**
   * Memory ID.
   */
  $id: string
  /**
   * Memory creation time.
   */
  $createdAt: string
  /**
   * Memory update time.
   */
  $updatedAt: string
  /**
   * Ownership scope: team.
   */
  scope: string
  /**
   * Owner ID for the scope (team ID).
   */
  ownerId: string
  /**
   * Stable memory key within the scope.
   */
  key: string
  /**
   * Memory content (preference, instruction, or fact).
   */
  content: string
  /**
   * Memory category: preference, instruction, or fact.
   */
  category: string
  /**
   * Injection priority. Higher values are kept first when the token budget is tight.
   */
  priority: number
  /**
   * Memory status: active or archived.
   */
  status: string
  /**
   * Who wrote the memory: user, agent, or system.
   */
  source: string
  /**
   * User ID that created or last wrote the memory.
   */
  createdBy: string
  /**
   * Optional expiry time. Null means permanent.
   */
  expiresAt: string
}
/**
 * Agent Model
 */
export type AgentModel = {
  /**
   * Model ID.
   */
  $id: string
  /**
   * Model creation time.
   */
  $createdAt: string
  /**
   * Model update time.
   */
  $updatedAt: string
  /**
   * Creator user ID.
   */
  userId: string
  /**
   * Owning team ID.
   */
  teamId: string
  /**
   * Display name.
   */
  name: string
  /**
   * LLM provider identifier.
   */
  provider: string
  /**
   * Provider model identifier.
   */
  model: string
  /**
   * Optional custom base URL for the provider API.
   */
  baseUrl: string
  /**
   * Whether this model can be selected on conversations.
   */
  enabled: boolean
  /**
   * Whether an API key is stored for this model.
   */
  hasApiKey: boolean
  /**
   * Last 4 characters of the stored API key.
   */
  hint: string
  /**
   * Optional model status.
   */
  status: string
}
/**
 * Agent Automation
 */
export type AgentAutomation = {
  /**
   * Automation ID.
   */
  $id: string
  /**
   * Automation creation time.
   */
  $createdAt: string
  /**
   * Automation update time.
   */
  $updatedAt: string
  /**
   * Creator user ID.
   */
  userId: string
  /**
   * Owning team ID.
   */
  teamId: string
  /**
   * Display name.
   */
  name: string
  /**
   * Prefix used for conversation titles created by this automation.
   */
  titlePrefix: string
  /**
   * First user message sent on every run.
   */
  prompt: string
  /**
   * Optional custom LLM model ID.
   */
  modelId: string
  /**
   * Model temperature.
   */
  modelTemp: number
  /**
   * Optional team ID context.
   */
  contextTeamId: string
  /**
   * Optional project ID context.
   */
  contextProjectId: string
  /**
   * Optional organization ID context.
   */
  contextOrganizationId: string
  /**
   * Optional console page path context.
   */
  contextPagePath: string
  /**
   * Optional console page title context.
   */
  contextPageTitle: string
  /**
   * Optional console page URL context.
   */
  contextPageUrl: string
  /**
   * Attachment file IDs sent with each run.
   */
  attachments: string[]
  /**
   * Cron expression controlling when this automation runs.
   */
  schedule: string
  /**
   * Whether this automation is scheduled to run.
   */
  enabled: boolean
  /**
   * Last time this automation fired.
   */
  lastRunAt: string
  /**
   * Search index field.
   */
  search: string
}
/**
 * Agent conversations list
 */
export type AgentConversationList = {
  /**
   * Total number of conversations that matched your query.
   */
  total: number
  /**
   * List of conversations.
   */
  conversations: AgentConversation[]
}
/**
 * Agent messages list
 */
export type AgentMessageList = {
  /**
   * Total number of messages that matched your query.
   */
  total: number
  /**
   * List of messages.
   */
  messages: AgentMessage[]
}
/**
 * Agent MCP connections list
 */
export type AgentMcpConnectionList = {
  /**
   * Total number of mcps that matched your query.
   */
  total: number
  /**
   * List of mcps.
   */
  mcps: AgentMcpConnection[]
}
/**
 * Agent memories list
 */
export type AgentMemoryList = {
  /**
   * Total number of memories that matched your query.
   */
  total: number
  /**
   * List of memories.
   */
  memories: AgentMemory[]
}
/**
 * Agent models list
 */
export type AgentModelList = {
  /**
   * Total number of models that matched your query.
   */
  total: number
  /**
   * List of models.
   */
  models: AgentModel[]
}
/**
 * Agent automations list
 */
export type AgentAutomationList = {
  /**
   * Total number of automations that matched your query.
   */
  total: number
  /**
   * List of automations.
   */
  automations: AgentAutomation[]
}
