# Agent Instructions

This directory contains instruction files for various automated agents that work with the Appwrite repository.

## Purpose

These instruction files provide standardized guidelines for:
- AI-powered coding agents (e.g., GitHub Copilot)
- Automated workflow agents
- Code review assistants
- Issue triage bots

## Available Instructions

### code-change-agent.md
Instructions for agents that make code changes and create pull requests. Includes:
- Code change best practices
- Testing requirements
- **Screenshot capture guidelines using Playwright**
- PR submission standards

## Using These Instructions

### For Human Contributors
While these instructions are primarily for automated agents, human contributors may find them useful as:
- Guidelines for creating high-quality PRs
- Standards for documenting UI changes
- Best practices for testing

### For Agent Developers
When building or configuring automated agents for this repository:
1. Review the relevant instruction file for the agent's purpose
2. Ensure the agent follows the guidelines, especially screenshot requirements for UI changes
3. Test the agent's behavior against these standards before deployment

## Screenshot Requirements

**All agents making UI changes MUST capture screenshots** to document visual modifications. See `code-change-agent.md` for detailed instructions on:
- When to take screenshots
- How to use Playwright for screenshot capture
- How to include screenshots in PRs

## Contributing

To update or add agent instructions:
1. Create or modify the appropriate `.md` file in this directory
2. Follow the existing format and structure
3. Ensure instructions are clear, actionable, and testable
4. Submit a PR with your changes

## Related Files

- `.github/workflows/issue-triage.md` - Agentic workflow for issue triage
- `.github/PULL_REQUEST_TEMPLATE.md` - PR template that all PRs should follow
- `CONTRIBUTING.md` - General contributing guidelines
