---
layout: article
title: Web application firewall (WAF)
description: Appwrite's Web Application Firewall (WAF) provides enterprise-grade protection against web vulnerabilities like SQL injection, XSS, and DDoS attacks.
---

The Web Application Firewall (WAF) is a critical feature of the Appwrite Network, designed to protect applications from common web vulnerabilities and attacks. Available exclusively to enterprise customers, WAF can be configured through your Appwrite success manager to meet the specific security needs of your application.

{% info title="Availability" %}
The WAF feature is available exclusively to enterprise customers as part of the Appwrite enterprise offering. Setup and configuration are managed through your dedicated Appwrite success manager, who ensures that the WAF aligns with your application's requirements and evolves with emerging security threats.
{% /info %}

The WAF functions as a protective barrier at the application layer (Layer 7) of the OSI model, where it inspects and filters HTTP/HTTPS traffic in real-time. It is specifically designed to safeguard your application by analyzing request headers, payloads, and query strings to identify malicious patterns or anomalies. The WAF intercepts traffic before it reaches your application, blocking threats such as:

- SQL injection: Malicious input targeting database queries is identified and neutralized by inspecting payloads and request parameters.
- Cross-site scripting (XSS): WAF detects and blocks scripts attempting to manipulate or steal client-side data through injection into the DOM or other contexts.
- Cross-site request forgery (CSRF): By analyzing session headers and request origins, the WAF ensures only legitimate actions are processed.
- DDoS attacks: Although primarily focused on Layer 7 attacks, the WAF works in tandem with other mitigation systems to handle floods of application-level requests.

# Design {% #design %}

1. **Traffic inspection**
   The WAF analyzes all incoming HTTP/HTTPS requests in real-time, matching them against a dynamic set of security rules.  

2. **Threat mitigation**
   Suspicious requests are blocked before they reach your application, ensuring uninterrupted service and secure user interactions.  

3. **Custom rules**
   Enterprise customers can work with their success manager to define custom rules tailored to their specific application requirements.  

# Key features {% #key-features %}

- Real-time protection: Continuous monitoring and blocking of malicious traffic.  
- Customizable rulesets: Configure WAF policies to align with your application's unique architecture.  
- Compliance support: Meet security standards and regulatory requirements by safeguarding sensitive data.  
- Seamless integration: Fully integrated into the Appwrite network, ensuring optimal performance and low-latency security enforcement.  

# Getting started {% #getting-started %}

If you're an enterprise customer, reach out to your success manager to enable WAF for your applications. They'll guide you through the setup process, help configure custom rules, and ensure your application is fully protected.  

For more information on upgrading to the enterprise plan, [contact sales](https://appwrite.io/contact-us/enterprise).

## Configuration {% #configuration %}

Appwrite's WAF is designed to adapt to the specific needs of your applications. Tailored rules and actions can be implemented to address evolving security threats and traffic patterns effectively.

### Rules {% #rules %}

The WAF supports the creation of fine-grained rules using logical expressions. These rules define how incoming traffic is inspected and handled.

1. **Expression-based rules**  
   Use logical expressions to create granular filters for incoming requests. These expressions operate on HTTP request fields, headers, cookies, and metadata.  

   Examples of configurable expressions:  
   - **URL matching**: Block or allow traffic based on URL patterns (e.g., `/v1/functions/*`).  
   - **Request method**: Filter traffic based on HTTP methods (e.g., `GET`, `POST`, `PUT`).  
   - **Header inspection**: Inspect headers like `User-Agent`, `Authorization`, or `X-Forwarded-For`.  
   - **Cookie validation**: Check for specific cookies or validate their values to identify bot or malicious traffic.  
   - **Rate limiting**: Match requests based on IP or session-level rate thresholds.  
   - **Geolocation rules**: Allow or block traffic based on the geographic location of the user.  

2. **Regular expressions (Regex)**  
   Use regex patterns for more advanced filtering, such as matching complex URL structures, payloads, or specific query parameters.  

3. **IP Filtering**  
   Define rules to block, allow, or challenge traffic from specific IP ranges or CIDR blocks.  

### Actions {% #actions %}

Based on the defined rules, the WAF can perform various actions to handle traffic appropriately.  

1. **Allow**  
   Allows the request to pass through to the backend without modification.  

2. **Block**  
   Rejects the request entirely, responding with an appropriate HTTP status code (e.g., `403 Forbidden`).  

3. **Challenge**  
   Issues a challenge (e.g., CAPTCHA) to validate whether the request originates from a legitimate user.  

4. **Redirect**  
   Redirects the request to a specified URL. Useful for handling deprecated endpoints or re-routing unauthorized traffic.  

5. **Log**  
   Records the request in detailed logs for further analysis without altering the request flow.  

6. **Rate limiting**  
   Throttles traffic from specific IPs or sessions that exceed defined thresholds, reducing the risk of abuse or overload.  

7. **Modify request**  
   Dynamically alters request components, such as headers, query parameters, or cookies.  

### Examples {% #examples %}

#### Blocking malicious bots
- **Expression**: Match requests with suspicious `User-Agent` strings or invalid headers.  
- **Action**: Block or issue a CAPTCHA challenge to confirm legitimate traffic.  

#### Preventing SQL injection
- **Expression**: Match payloads or query strings containing SQL keywords like `SELECT`, `DROP`, or `--`.  
- **Action**: Block the request and log it for analysis.  

#### Rate limiting by endpoint
- **Expression**: Limit requests to sensitive endpoints (e.g., `/login`) to prevent abuse.  
- **Action**: Throttle or temporarily block repeated requests from the same IP.  

#### Geo-based blocking
- **Expression**: Match requests originating from specific regions or countries.  
- **Action**: Block or redirect users to a region-specific notice page.  

#### Header enforcement
- **Expression**: Check for required headers (e.g., `Authorization`) on API requests.  
- **Action**: Block requests missing critical headers.  