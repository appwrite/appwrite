---
layout: article
title: Messaging
description: Configure Appwrite Messaging providers, topics, and subscribers with Terraform for email, SMS, and push delivery.
---

[Messaging](/docs/products/messaging) integrates email, SMS, and push providers. The Terraform provider exposes **providers** (credentials and channel configuration), **topics** (groupings of subscribers for broadcasts), and **subscribers** (who receives messages on a topic).

See the Terraform Registry for generated schemas: [messaging_provider](https://registry.terraform.io/providers/appwrite/appwrite/latest/docs/resources/messaging_provider), [messaging_topic](https://registry.terraform.io/providers/appwrite/appwrite/latest/docs/resources/messaging_topic), and [messaging_subscriber](https://registry.terraform.io/providers/appwrite/appwrite/latest/docs/resources/messaging_subscriber). The [provider repository](https://github.com/appwrite/terraform-provider-appwrite) lists every `type` and optional field in source; provider-specific arguments apply only to the matching provider (for example Twilio `account_sid`, SMTP `host` and `encryption`, FCM `service_account_json`).

# Resources {% #resources %}

| Resource | Purpose |
|----------|---------|
| `appwrite_messaging_provider` | Register an email, SMS, or push provider (Sendgrid, SMTP, Twilio, FCM, and others) |
| `appwrite_messaging_topic` | Define a topic for targeting subscribers |
| `appwrite_messaging_subscriber` | Attach subscribers to a topic |

The `type` argument on `appwrite_messaging_provider` must be one of the supported provider kinds (for example `sendgrid`, `mailgun`, `smtp`, `resend`, `twilio`, `vonage`, `msg91`, `telesign`, `textmagic`, `apns`, `fcm`).

# Examples {% #examples %}

## Providers {% #providers %}

```hcl
resource "appwrite_messaging_provider" "sendgrid" {
  id         = "sendgrid"
  name       = "sendgrid"
  type       = "sendgrid"
  api_key    = var.sendgrid_api_key
  from_email = "noreply@example.com"
  from_name  = "application"
}

resource "appwrite_messaging_provider" "smtp" {
  id         = "smtp"
  name       = "smtp"
  type       = "smtp"
  host       = "smtp.example.com"
  port       = 587
  username   = "user@example.com"
  password   = var.smtp_password
  encryption = "tls"
  from_email = "noreply@example.com"
}

resource "appwrite_messaging_provider" "twilio" {
  id          = "twilio"
  name        = "twilio"
  type        = "twilio"
  account_sid = var.twilio_account_sid
  auth_token  = var.twilio_auth_token
  from        = "+1234567890"
}

resource "appwrite_messaging_provider" "fcm" {
  id                   = "fcm"
  name                 = "fcm"
  type                 = "fcm"
  service_account_json = file("firebase-service-account.json")
}
```

## Topics {% #topics %}

```hcl
resource "appwrite_messaging_topic" "announcements" {
  id   = "announcements"
  name = "announcements"
}

resource "appwrite_auth_team" "engineering" {
  id   = "engineering"
  name = "engineering"
}

resource "appwrite_messaging_topic" "engineering_alerts" {
  id        = "engineering-alerts"
  name      = "engineering-alerts"
  subscribe = ["team:${appwrite_auth_team.engineering.id}"]
}
```

## Subscribers {% #subscribers %}

Attach a target (a user's email, phone, or push target ID) to a topic so it receives messages broadcast to that topic.

```hcl
resource "appwrite_messaging_subscriber" "user_email" {
  topic_id  = appwrite_messaging_topic.announcements.id
  target_id = "user-email-target-id"
}
```

Topics and subscribers support import by ID where documented; see the Registry for each resource.

# Data sources {% #data-sources %}

The **`appwrite_messaging_topic`** data source reads a topic that already exists by ID. Use it to attach subscribers without taking ownership of the topic itself in Terraform.

```hcl
data "appwrite_messaging_topic" "announcements" {
  id = "announcements"
}

output "topic_name" {
  value = data.appwrite_messaging_topic.announcements.name
}
```

See the [Terraform Registry](https://registry.terraform.io/providers/appwrite/appwrite/latest/docs/data-sources/messaging_topic) for the full attribute list.

# Related {% #related %}

- [Messaging product docs](/docs/products/messaging)
- [Configuration](/docs/tooling/terraform/provider)
