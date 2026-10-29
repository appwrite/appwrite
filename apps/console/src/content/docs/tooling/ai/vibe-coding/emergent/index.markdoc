---
layout: article
title: Emergent
description: Learn how to connect the Appwrite MCP server to Emergent for AI-assisted development with access to the Appwrite API and documentation.
---

{% section #add-mcp-server step=1 title="Add the MCP server" %}

To connect the Appwrite MCP server to Emergent:

1. On the homepage, click **Advanced Controls**.
2. Click **Select MCP Tools**.
3. Click **New MCP Server**.
4. Enter a name for your server (e.g., `appwrite`).
5. Paste the following JSON configuration:

```json
{
  "mcpServers": {
    "appwrite": {
      "type": "http",
      "url": "https://mcp.appwrite.io/"
    }
  }
}
```

The server uses OAuth for authentication. When Emergent first connects to the server, complete the sign-in flow in your browser to sign in to your Appwrite account and authorize access.

{% /section %}

{% section #test-the-integration step=2 title="Test the integration" %}

Once connected, you can use natural language to interact with Appwrite. Try prompts like:

**Example prompts:**

- `Create a new user in my Appwrite project`
- `List all databases in my project`
- `Show me the tables in my database`
- `How do I set up real-time subscriptions in Appwrite?`
- `Show me how to authenticate users with OAuth`
- `How do I implement file uploads with Appwrite Storage?`

{% /section %}
