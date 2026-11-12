---
layout: article
title: HIPAA
description: Learn about Appwrite Cloud's measures to achieve HIPAA compliance.
---

Appwrite is compliant with HIPAA (Health Insurance Portability and Accountability Act) regulations.
HIPAA is an important regulation that protects patients' health data from being disclosed without consent or knowledge.

If you're building apps that handle information that is considered [PHI (Personal Health Information)](https://privacyruleandresearch.nih.gov/pr_07.asp)
for an U.S. user base, data must be stored in a HIPAA-compliant environment.

To attain HIPAA compliance, we've taken extensive measures, ensuring that our practices align with the highest data protection standards.
We have implemented robust measures to safeguard personal information, updating our policies, procedures, and infrastructure to meet the strict requirements of HIPAA regulations.

- A strict data backup schedule.
- An extended business continuity plan.
- Data retention rights for individuals as outlined in our [Privacy Policy](https://appwrite.io/privacy).
- Intrusion detection and penetration testing.
- Encryption of data transmitted between Appwrite and users using Transport Layer Security (TLS) and HTTP Strict Transport Security,
ensuring confidentiality both at rest and during transmission.
- Access to environments containing customer data is strictly controlled,
requiring authentication and authorization through multi-factor authentication (MFA).

Appwrite safeguards personal information to the same extent it protects its own, complying with relevant privacy laws and regulations in the jurisdictions where its services are offered.

## Appwrite as a Business Associate

Appwrite serves as a business associate to customers that meet the definition of a [covered entity](https://www.hhs.gov/hipaa/for-professionals/covered-entities/index.html) under HIPAA, such as health plans and healthcare providers. A business associate performs certain functions or services that involve the use or disclosure of PHI on behalf of a covered entity. Covered entities may be required to enter into a Business Associate Agreement (BAA) with business associates to meet their HIPAA requirements.

For Enterprise customers subject to HIPAA and processing PHI within their applications, Appwrite will sign a BAA. To request Appwrite's BAA, please [contact us](/contact-us).

For Pro customers, the BAA is a click-through agreement that is not signed. An organization owner can enable it directly from the Appwrite Console: open your organization's **Settings** tab, find the **BAA** section, select **Enable BAA**, then review the agreement and select **Accept & Enable**. The BAA is a paid add-on billed to your organization and prorated for your current billing cycle. You can review the agreement on the [Business Associate Agreement](/legal/baa) page.

## Data retention

Appwrite gives you full control over your data lifecycle. By default, Appwrite stores user and project data until you explicitly delete it. There's no automatic purging or TTL unless you configure it that way in your application logic or functions.

If you're handling PHI (Protected Health Information), you can implement custom data retention policies using Appwrite Functions or database triggers to meet HIPAA requirements.

## Log access and retrieval

Appwrite provides access to different types of logs depending on the context:

- **API usage logs**: These are turned off by default, we can give you samples of the data on requests to help debug and troubleshoot issues. If you'd like to have those turned on constantly and transmitted to you or stored on a bucket, this is a separate addon we can provide.

- **Function logs**: Each serverless function or hosted sites includes stdout and stderr logs you can access per execution. Those are retained for different periods per plan.

- **Audit logs**: For users or teams with compliance needs, we provide structured audit logs covering authentication events, permission changes, and other relevant activities directly on your console, under an activity tab in the different products the platform offers. Those are retained for different periods per plan.

Please note that while Appwrite Cloud serves as a HIPAA-compliant platform to handle data,
it is the responsibility of developers to ensure that their application is also compliant with HIPAA regulations.
