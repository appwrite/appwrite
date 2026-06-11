---
layout: article
title: Scaling
description: Learn how to scale your self-hosted Appwrite instance horizontally and vertically to handle increased load.
---

Appwrite is built with scalability in mind. Appwrite can scale both horizontally and vertically.

Each Appwrite instance is composed of many containers, each with its unique job. Appwrite's functions and worker containers are stateless. To scale them, all you need is to replicate them and set up a load balancer to distribute their load.

If you decide to set up a load balancer to scale a container, make sure **all** communication are routed through the load balancer and not directly to the replicated containers. You can configure communicating between Appwrite containers using Docker environment variables.

Two Appwrite containers are stateful. The database (MariaDB or MongoDB, depending on your installation) and Redis containers are used for storing data, cache and pub/sub messaging, and usage stats, respectively. To scale these containers, set up a standard cluster (same as you would with any other app using these technologies) according to your needs and performance.

# Performance considerations {% #performance-considerations %}

You may want to adjust the `_APP_WORKER_PER_CORE` environment variable to optimize worker processes per CPU core based on your hardware:

```bash
_APP_WORKER_PER_CORE=<number_of_workers>
```

This setting affects the API, Realtime, and Executor containers and can be tuned according to your specific hardware specifications and workload requirements. The default value is 6.

# Resource management considerations {% #resource-management %}

When scaling Docker containers, consider implementing resource limits and monitoring:

## Log rotation
Consider configuring log rotation to prevent disk space issues:
```yaml
x-logging: &x-logging
  logging:
    driver: 'json-file'
    options:
      max-file: '<number_of_files>'
      max-size: '<size_limit>'
```

## Redis memory management
You may want to set memory limits for Redis to prevent out-of-memory issues:

# Monitoring considerations {% #monitoring %}

- **Health checks**: Consider implementing health check endpoints for services
- **Resource monitoring**: Monitor CPU, memory, and disk usage as needed
- **Log aggregation**: Centralized logging can help with debugging scaled deployments
- **Alert thresholds**: Consider setting alerts for high resource usage


