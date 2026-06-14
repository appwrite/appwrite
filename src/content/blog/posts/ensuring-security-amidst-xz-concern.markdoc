---
layout: post
title: Ensuring security amidst the XZ Utils backdoor concern
description: Learn about Appwrite's response to XZ Utils backdoor concerns and how to safeguard your Appwrite projects.
date: 2024-04-02
cover: /images/blog/ensuring-security-amidst-xz-concern/cover.avif
timeToRead: 7
author: jake-barnby
category: security
featured: false
faqs:
  - question: "What was the XZ Utils backdoor?"
    answer: "In early 2024, a malicious backdoor was found in XZ Utils versions 5.6.0 and 5.6.1, a compression tool shipped with many Linux distributions. The backdoor could be used to compromise encrypted SSH sessions on systems running affected beta and test versions of distros like Red Hat and Debian, putting any server using SSH at risk."
  - question: "Is Appwrite affected by the XZ Utils backdoor?"
    answer: "No, Appwrite's services are not affected. The vulnerable versions of XZ Utils shipped only in beta and test versions of certain Linux distributions, and Appwrite does not use those. Appwrite Cloud infrastructure has also been audited and locked down to confirm there is no exposure."
  - question: "What should I do if I self-host Appwrite on a Linux server?"
    answer: "Check whether your operating system has XZ Utils versions 5.6.0 or 5.6.1 installed. If so, downgrade to a safe version or remove the tool. Then audit your system logs for unusual activity, tighten firewall rules to limit inbound and outbound traffic, and apply security patches from your OS vendor as soon as they ship."
  - question: "Do Appwrite Cloud users need to do anything?"
    answer: "No actions are required from Appwrite Cloud users. The Appwrite team verified that no containers in the cloud environment carried the affected XZ Utils versions, and SSH access to the infrastructure was further restricted to reduce attack surface."
  - question: "How can I keep my self-hosted Appwrite instance secure in general?"
    answer: "Keep your host OS patched, apply Appwrite updates promptly, restrict SSH access with key-based auth and firewalls, rotate API keys, enable monitoring on your servers, and audit who has access to the project Console. The same hardening practices that protect any production server apply to a self-hosted Appwrite instance."
  - question: "Where can I report security concerns to Appwrite?"
    answer: "Reach out via the [contact page](/contact-us) or the [Appwrite Discord](/discord) for security questions. For responsible disclosure of suspected vulnerabilities, follow the security policy published in the Appwrite GitHub repository so the team can triage and respond appropriately."
---

In the light of recent unsettling revelations regarding a backdoor discovered in the widely-used XZ Utils,
a compression tool used in Linux environments, including Red Hat and Debian systems, the cyber-security landscape has been abuzz with concern.
This discovery had a large potential impact on encrypted SSH connections, a backbone of secure communications in the tech world.

At Appwrite, ensuring the security and trust of our developers and users is paramount.
We understand the concerns that arise from such vulnerabilities and their potential implications.

It's crucial for the Appwrite community to know that Appwrite's services **remain unaffected** by the XZ Utils backdoor.
This issue affected beta and test versions of Red Hat and Debian distributions, which Appwrite **does not use**.

# What does this mean for self-hosting Appwrite?

For our valued users who prefer the self-hosted route, leveraging Appwrite on affected operating systems (OS),
we understand your concerns. Here are our recommendations to ensure your self-hosted Appwrite instances remain secure:

- Immediate Update/Removal: The first and foremost step is to check if you have the affect versions (`5.6.0`,` 5.6.1`) of the XZ Utils installed. If so, downgrade to a safe version or remove the utility altogether.
- Enhanced Monitoring: Keep a keen eye on network traffic and system logs for any unusual activity.
- Employ Firewalls: Employ stringent firewall rules to limit inbound and outbound connections to the bare minimum required for your operations. This reduces the attack surface significantly.
- Regular System Audits: Conduct thorough audits of your systems to ensure no unauthorized modifications have been made to the OS or installed utilities.
- Stay Informed: Follow updates from your OS's security advisory to apply security patches as soon as they are released.

# Does this affect Appwrite Cloud developers?

Appwrite Cloud users can rest assured that our cloud infrastructure is secure and unaffected by the XZ Utils backdoor.
The Appwrite team has taken necessary measures to ensure that containers in our cloud environment do not have the affected versions of the XZ Utils installed.
We also took further steps to restrict SSH access to our cloud infrastructure to reduce attack surfaces further.
No actions are required from Appwrite Cloud developers at this time.

In a world where cyber threats are evolving at an alarming pace,
the Appwrite team is committed to ensuring the security and reliability of Appwrite Cloud's infrastructure,
so you can build applications with peace of mind.
The team will continue to monitor the situation closely, take necessary actions to mitigate any potential risks,
and communicate any updates transparently to the community.

For any further questions or concerns, please reach out through [email](/contact-us) or on [Discord](https://appwrite.io/discord).
We're here to support you every step of the way.
