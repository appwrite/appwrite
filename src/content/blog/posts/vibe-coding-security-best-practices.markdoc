---
layout: post
title: 20 security best practices for vibe coding
description: Vibe coding is becoming popular, but it's not always safe. Follow these best practices to secure your apps when using AI-generated code.
date: 2025-04-01
lastUpdated: 2026-06-29
cover: /images/blog/vibe-coding-security-best-practices/cover.avif
timeToRead: 15
author: ebenezer-don
category: tutorial
featured: false
callToAction: true
faqs:
  - question: "What is vibe coding?"
    answer: "Vibe coding is the practice of building software by guiding an AI assistant in natural language rather than typing every line yourself. You describe the feature, review what the AI produces, and iterate. It can be faster, but it pushes responsibility for code quality and security onto the human reviewer."
  - question: "Is AI generated code secure by default?"
    answer: "No. AI models generate plausible code based on training patterns, not verified secure code. They routinely produce insecure authentication, missing input validation, hardcoded secrets, and outdated crypto. Treat every AI suggestion as untrusted input and review it before shipping."
  - question: "What is the single most impactful thing I can do to secure vibe coded apps?"
    answer: "Stop writing your own authentication. Use a managed auth service like [Appwrite Auth](/docs/products/auth), Auth0, or Clerk so password hashing, session handling, MFA, and rate limiting are not your responsibility. Most production breaches start with bespoke auth that an LLM helped draft."
  - question: "How do I store secrets safely when using AI tools?"
    answer: "Never commit secrets to source code. Use environment variables, a secrets manager, or your platform's secret store, and add the relevant files to gitignore. If you suspect a secret was pushed to a repo, rotate it immediately, removing the commit alone is not enough."
  - question: "Should I let AI write database queries?"
    answer: "Only if you read every line. AI models still generate string concatenated SQL that is vulnerable to injection. Prefer parameterized queries, an ORM, or a managed data API like [Appwrite Databases](/docs/products/databases) that does the escaping for you."
  - question: "Do I still need code review and tests if AI wrote the code?"
    answer: "Yes, more than ever. AI generated code looks confident even when it is wrong. Pair it with unit tests, integration tests, type checks, linters, and a human reviewer for security sensitive changes. The faster you generate, the more you need to verify."
---

Vibe coding is changing the way developers build software. Instead of manually writing every function, many developers are beginning to rely on AI-assisted tools to generate code based on natural language instructions. This approach can significantly speed up development and allow teams to create applications faster. But... the convenience of vibe coding comes with significant security risks.

AI-generated code is not inherently secure, and without proper oversight, it can introduce vulnerabilities that lead to data breaches, unauthorized access, and critical system failures.

Security must be a top priority for vibe coders, and as a developer, you must take responsibility for reviewing, testing, and securing AI-generated code. In this article, we'll explore **20 essential security best practices** you must follow as a vibe coder to ensure your applications remain safe.

Which tools you allow into your workflow matters too: permissions, data handling, and how much of the repo an assistant can touch vary widely. For strengths, limits, and fit across common AI IDEs and builders, see [Best vibe coding tools in 2026: comparison and tradeoffs](/blog/post/comparing-vibe-coding-tools).

# 1. Always review and understand AI-generated code

One of the most common mistakes developers make when using AI-generated code is assuming that it is correct and secure by default. AI models do not "think" like humans. They generate code based on patterns from their training data. **They don't have feelings, and therefore, cannot feel responsible for the code they generate.** This means they can produce insecure, inefficient, or completely incorrect solutions that may work at first glance but introduce serious risks.

For example, an AI-generated authentication system might include a password-checking function but fail to enforce proper hashing standards. A vibe coder might copy this code into their project without realizing it stores passwords in plaintext, a serious security vulnerability.

To avoid such issues, always review AI-generated code line by line, understand each function's purpose and ensure it aligns with security best practices. If the AI generates a complex piece of logic that you do not fully understand, take the time to research and test it before integrating it into your application.

# 2. Rely on proven authentication patterns

Authentication is one of the most critical security components of any application. AI-generated code might create a login system that appears functional, but without proper security measures, it could expose user credentials, enable unauthorized access, or fail under real-world attack scenarios.

AI can very easily generate a function that checks passwords against stored values in a database but can also easily fail to implement proper password hashing. If as a developer, you are unaware of best practices, there'll be nothing stopping you from using this insecure function to store passwords in plaintext. If an attacker gains access to the database, they would have full visibility into user credentials, leading to massive security breaches.

Instead of relying on AI-generated authentication logic, go for **widely adopted** authentication solutions and libraries. A straightforward choice is a platform like [Appwrite](https://appwrite.io/), which provides a secure authentication flow out of the box with several authentication patterns including email/password, social login, magic link/passwordless login, and more. For Node.js applications, you can also use **Passport.js** or **NextAuth** to provide secure authentication flows. For web applications, **Auth0** exists as well, and can ensure that authentication is handled correctly with industry-standard security measures like OpenID Connect, and multi-factor authentication (MFA).

You should also ensure that password hashing follows modern best practices. Use **bcrypt** or **Argon2** for hashing rather than outdated methods like MD5 or SHA-1, which are vulnerable to brute-force attacks. When AI generates authentication code, always verify that it follows these principles before deploying it to production.

# 3. Validate and sanitize all user inputs

User input is one of the most common attack vectors in web applications. AI-generated code may not properly validate inputs, leaving applications vulnerable to **SQL injection, cross-site scripting (XSS), and remote code execution (RCE)**. These vulnerabilities allow attackers to manipulate data, execute malicious scripts, or gain unauthorized access.

Consider a scenario where AI generates a search function for a database query:

```js
app.get('/search', async (req, res) => {
  const query = `SELECT * FROM users WHERE name = '${req.query.name}'`
  const result = await db.query(query)
  res.json(result)
})
```

This function directly inserts user input (`req.query.name`) into an SQL query, making it vulnerable to SQL injection. An attacker could send:

```
/search?name=' OR '1'='1
```

This would return all user records, exposing sensitive data. To prevent this, always **validate input** and use **parameterized queries**:

```js
app.get('/search', async (req, res) => {
  const name = req.query.name?.trim()
  if (!name || /[^a-zA-Z0-9 ]/.test(name)) {
    return res.status(400).json({ error: 'Invalid input' })
  }

  const result = await db.query('SELECT * FROM users WHERE name = ?', [name])
  res.json(result)
})
```

Using an **ORM** like Prisma, Sequelize, or TypeORM can further reduce risks by abstracting query handling. Alternatively, [Appwrite's TablesDB API](https://appwrite.io/docs/references/cloud/client-web/tablesDB) provides built-in query filtering, eliminating the need to construct raw SQL queries.

Beyond SQL injection, user inputs should be validated for **length, type, and format**. Use libraries like **Zod (TypeScript)** or **Joi (JavaScript)** to enforce strict validation rules before processing any user data. Sanitization is equally important. Removing or escaping dangerous characters in user inputs prevents attacks like XSS and command injection.

# 4. Store secrets securely and avoid hardcoding credentials

AI-generated code sometimes suggests using API keys, database credentials, or other sensitive information **directly within source files**. **This is a serious security risk.** Hardcoded secrets can be accidentally pushed to public repositories, where attackers can easily retrieve them and exploit access to your systems.

For example, AI-generated code might include:

```js
const apiKey = 'sk_test_1234567890abcdef' // DO NOT DO THIS
fetch(`https://api.example.com/data?key=${apiKey}`)
  .then((response) => response.json())
  .then((data) => console.log(data))
```

Instead of hardcoding secrets, always store them in **environment variables** or a **secrets manager**. Services like **AWS Secrets Manager, Azure Key Vault, and HashiCorp Vault** provide secure storage and controlled access to sensitive credentials. In a Node.js application, use environment variables like this:

```js
const apiKey = process.env.API_KEY
```

Then, store credentials in a `.env` file, which should be **excluded from version control**:

```
API_KEY=sk_test_1234567890abcdef
```

Proper secret management ensures that sensitive data is never exposed in public repositories, reducing the risk of unauthorized access.

# 5. Do not store API keys in env files of frontend frameworks

One of the most common security mistakes developers make is storing API keys, secrets, or sensitive credentials inside environment files of frontend frameworks like Next.js, React, or Vue.js. While environment variables are a good practice for server-side applications, using them incorrectly in frontend projects can expose sensitive credentials to attackers.

## Why frontend environment variables are insecure

In frontend frameworks like React and Vue, environment variables are usually bundled at build time, meaning they are part of the JavaScript files served to users. If a key is included in a .env file like this:

```
REACT_APP_API_KEY=sk_test_1234567890abcdef
```

And referenced in a component:

```js
const apiKey = process.env.REACT_APP_API_KEY
```

This key will be visible in browser developer tools, the network tab, and even the compiled JavaScript files. An attacker can easily obtain it by inspecting the page source or opening the developer console.

This applies to any frontend framework that bundles environment variables into client-side JavaScript, including:

- React (`REACT_APP_*` variables)
- Vue (`VUE_APP_*` variables)
- Next.js (`NEXT_PUBLIC_*` variables)

Any environment variable prefixed with PUBLIC or specifically required for client-side code is not secure and should never contain sensitive data.

## How to securely store API keys in frontend projects

API keys should always be stored on a **backend server**, not in frontend code. Instead of making direct API calls from the frontend, the recommended approach is to **create a backend proxy that securely handles requests** and appends the API key before forwarding the request to the external service.

This way, **the API key is never exposed to the browser**, and attackers cannot extract it from the client-side code.

## Environment variables in server-side frameworks

Some frameworks, like **Next.js and Nuxt.js**, offer built-in support for environment variables that are only accessible on the server. For example, in Next.js, any environment variable **without** the `NEXT_PUBLIC_` prefix is only available on the server:

```
API_KEY=sk_test_1234567890abcdef
```

This allows the API key to be used **only in API routes or server-side functions**, preventing it from being bundled into frontend JavaScript.

# 6. Implement strong authorization and access control

AI-generated code might not enforce proper authorization checks, which can allow users to access resources they shouldn't. A common mistake is assuming that authentication alone is sufficient for security. However, without granular **role-based access control (RBAC)** or **attribute-based access control (ABAC)**, attackers or unauthorized users might exploit missing checks to escalate privileges.

For example, you might use AI to generate an API that allows users to delete a record from your database. Without checking if the logged-in user has the necessary permissions, any user could delete **any** account, leading to a major security issue.

To prevent this, implement **role-based access control (RBAC)** to restrict access to only authorized users.

Every function that modifies or deletes data should enforce strict authorization rules. Always verify user roles and enforce **least privilege access**, ensuring users only have permissions necessary for their role.

If using Appwrite, ensure that your database tables are [configured with the correct permissions](https://appwrite.io/docs/products/databases/permissions) and that you are using the correct roles to restrict access.

# 7. Secure all communications with HTTPS

AI-generated code might not enforce HTTPS, leaving applications vulnerable to **man-in-the-middle (MITM) attacks**, where attackers intercept and modify sensitive data exchanged between clients and servers. If login credentials or API keys are transmitted over HTTP, an attacker monitoring the network can easily steal them.

To enforce HTTPS, configure your web server to **redirect all HTTP traffic to HTTPS** using **HSTS (HTTP Strict Transport Security)** headers.

For Express.js applications, force HTTPS using middleware:

```js
const express = require('express')
const helmet = require('helmet')

const app = express()
app.set('trust proxy', true) // Trust reverse proxy

// Redirect HTTP to HTTPS
app.use((req, res, next) => {
  if (req.protocol !== 'https') {
    return res.redirect(301, `https://${req.headers.host}${req.url}`)
  }
  next()
})

// Enable HSTS
app.use(
  helmet.hsts({
    maxAge: 31536000,
    includeSubDomains: true,
    preload: true,
  }),
)

app.listen(3000)
```

For production, always use **TLS (Transport Layer Security)** certificates from **Let's Encrypt** or another trusted Certificate Authority (CA).

# 8. Regularly scan dependencies for vulnerabilities

AI-generated code often suggests using external libraries without verifying their security. Vulnerable dependencies are one of the most exploited attack vectors in modern applications. Attackers frequently target outdated libraries with known security flaws.

To protect against this, **regularly audit dependencies** using:

- `npm audit` (for JavaScript/Node.js projects)
- `pip-audit` (for Python projects)
- `cargo audit` (for Rust projects)
- `mvn dependency:check` (for Java/Maven projects)

For example, in a Node.js project, running:

```
npm audit fix
```

automatically updates vulnerable dependencies. However, **always test updates thoroughly**, as they may introduce breaking changes. Consider using automated dependency monitoring services like **Snyk** or **Dependabot**, which notify you about security vulnerabilities in your project dependencies.

# 9. Secure API endpoints to prevent unauthorized access

One of the most overlooked risks in AI-generated code is weak API security. AI models might generate API endpoints without proper authentication or authorization, exposing sensitive data and operations to anyone with access to the endpoint. Attackers often scan public-facing APIs for security misconfigurations, making this a critical area to secure.

Always ensure that your API endpoints are protected by authentication and authorization.

Beyond authentication, API security should include:

- **Rate limiting:** Prevent brute-force attacks by restricting the number of requests per minute using middleware like `express-rate-limit`.
- **CORS policies:** Restrict which domains can make API calls to prevent unauthorized cross-origin requests.
- **API gateways:** Use API management platforms like Kong or AWS API Gateway to enforce security at scale.

API security must be a priority from the start, as weak APIs are one of the most common entry points for attackers.

# 10. Implement secure session management

AI-generated authentication flows might create session-handling logic that lacks proper security controls. Poor session management can lead to **session hijacking, session fixation, or replay attacks**, where attackers steal valid session tokens and gain unauthorized access.

A common mistake is using weak session identifiers or storing session tokens in insecure locations, such as local storage in the browser. Attackers can steal these tokens using **XSS (Cross-Site Scripting) attacks**. Instead, sessions should be managed securely:

- **Use HTTP-only cookies for session storage**, which prevents JavaScript from accessing session tokens.
- **Set secure flags on cookies** (`Secure`, `HttpOnly`, and `SameSite=strict`) to prevent cross-site access.
- **Regenerate session IDs after authentication** to prevent session fixation attacks.
- **Set session expiration times** to minimize risk if a token is compromised.

For example, in Express.js with `express-session`:

```js
app.use(
  session({
    secret: process.env.SESSION_SECRET,
    resave: false,
    saveUninitialized: false,
    cookie: {
      httpOnly: true,
      secure: true,
      sameSite: 'strict',
      maxAge: 3600000, // 1 hour
    },
  }),
)
```

Just like we discussed before, an even better approach is to go with well-established solutions for auth and session management. Appwrite helps you with this out of the box. You can also go with other popular solutions that are specifically designed for this purpose.

# 11. Secure file uploads to prevent malware execution

AI-generated file upload handlers may accept any file type without validation, opening the door to **remote code execution (RCE) attacks, malware uploads, and denial-of-service (DoS) exploits**. Attackers can upload malicious scripts disguised as images or PDFs, executing them once they reach the server.

For example, an insecure file upload endpoint might look like this:

```js
app.post('/upload', upload.single('file'), (req, res) => {
  res.send('File uploaded')
})
```

This accepts **any file type**, which is dangerous. To secure file uploads:

- **Limit allowed file types** (e.g., only allow `.jpg`, `.png`, `.pdf`).
- **Scan uploaded files for malware** using antivirus tools like ClamAV.
- **Store files outside the web root directory** to prevent direct access.
- **Rename uploaded files** to prevent executing malicious filenames.

To secure file uploads and prevent malware execution, implement a comprehensive validation strategy. Rather than accepting any file type, explicitly whitelist allowed formats (like JPG, PNG, PDF) and verify file types through MIME checking.

Set reasonable file size limits (e.g., 5MB) to prevent denial-of-service attacks. Consider using libraries like `multer` in Node.js to handle these security controls, and remember to scan uploads for malware using tools like ClamAV before processing them.

If using Appwrite Storage, you can easily configure all these in your [bucket settings](https://appwrite.io/docs/products/storage/buckets).

This approach drastically reduces the risk of attackers uploading malicious scripts disguised as harmless files.

By enforcing strict upload rules, you protect your system from dangerous file exploits.

# 12. Apply the principle of least privilege (PoLP)

AI-generated code, especially with the growing popularity of [MCP (Model Context Protocol)](https://appwrite.io/blog/post/what-is-mcp?doFollow=true), might grant excessive permissions to users, database connections, or cloud services. This violates the **principle of least privilege**, which states that every system component should have **only the minimum level of access necessary to perform its function**.

For example, if an AI-generated database connection uses **a root account with full privileges**, an attacker who gains access to the application could execute **DROP DATABASE** or **modify any table**. Instead, create a dedicated database user with **read-only access** for non-administrative operations.

Apply PoLP principles across:

- **Cloud services:** Use IAM roles to restrict permissions in AWS, GCP, or Azure.
- **API keys:** Limit API scopes to necessary actions only.
- **User roles:** Define admin, editor, and viewer roles with different access levels.

By limiting access, even if an attacker gains access to a compromised system, the damage they can do is minimized.

# 13. Monitor security logs and enable real-time threat detection

One of the biggest risks with AI-generated code is the lack of built-in logging and monitoring. Without proper logging, security incidents go unnoticed until it's too late. AI-generated functions often lack detailed audit trails, making it difficult to investigate breaches, detect unauthorized access, or analyze unusual activity.

For example, if an AI generates an authentication function, it may successfully verify user credentials but fail to log login attempts, failed authentications, or suspicious behavior. Without this data, you won't know if someone is attempting a brute-force attack or if an attacker has successfully exploited a vulnerability.

To mitigate this, implement a comprehensive **logging and monitoring** system.

Beyond logging, enable **real-time threat detection** using **SIEM (Security Information and Event Management)** tools such as Splunk, Datadog, or Elastic Security. These tools analyze logs for suspicious activity, like repeated failed login attempts or access from unusual locations.

Additionally, consider integrating **intrusion detection systems (IDS)** like **OSSEC** or **Wazuh** to monitor system logs and detect threats before they escalate.

# 14. Secure cryptographic operations and avoid AI-suggested weak algorithms

AI-generated code often suggests outdated or insecure cryptographic algorithms due to limitations in training data. For example, an AI might suggest using **MD5** or **SHA-1** for password hashing, both of which are no longer secure against modern brute-force attacks.

To ensure secure cryptographic operations:

- Use **bcrypt** or **Argon2** for password hashing.
- For encryption, use **AES-256-GCM** instead of older ciphers like DES or Blowfish.
- For digital signatures, prefer **ECDSA (Elliptic Curve Digital Signature Algorithm)** over RSA-1024.

Remember that AI-generated code can easily skip fundamental steps like salting passwords, making them vulnerable to rainbow table attacks. Always verify cryptographic implementations and ensure they follow modern security standards.

# 15. Rate-limit API requests to prevent brute-force and DoS attacks

AI-generated API endpoints often lack protection against excessive requests, making them susceptible to brute-force attacks and **Denial of Service (DoS)** attacks. If an attacker floods your login API with thousands of authentication attempts, they may crack weak passwords or overload your server, making the service unavailable to legitimate users.

To prevent this, implement **rate limiting** at the API level using middleware like `express-rate-limit`:

```js
const rateLimit = require('express-rate-limit')

const limiter = rateLimit({
  windowMs: 15 * 60 * 1000, // 15 minutes
  max: 100, // limit each IP to 100 requests per window
  message: 'Too many requests, please try again later',
})

app.use('/login', limiter)

```

This limits users to **100 login attempts per 15 minutes**, significantly reducing the risk of brute-force attacks while ensuring legitimate users can still access the service.

For more advanced protection, use **Web Application Firewalls (WAFs)** like **Cloudflare, AWS WAF, or Imperva**, which provide intelligent traffic filtering and automated threat detection against DoS attacks.

# 16. Implement proper CORS policies to prevent cross-origin exploits

We mentioned CORS briefly, but it's important to address it in detail. Cross-Origin Resource Sharing (CORS) controls which external domains can make requests to your API. AI-generated APIs may not enforce strict CORS policies, leading to **cross-origin request forgery (CSRF) and unauthorized API access**.

For example, a poorly implemented CORS policy might allow requests from **any** domain (`*`):

```js
app.use(cors({ origin: '*' }))
```

This is dangerous because it allows **any website** to make API requests on behalf of an authenticated user, potentially exposing sensitive data.

A better approach is to define **allowed origins explicitly**:

```js
const corsOptions = {
  origin: ['<https://yourtrusteddomain.com>'],
  methods: ['GET', 'POST'],
  allowedHeaders: ['Content-Type', 'Authorization'],
}

app.use(cors(corsOptions))
```

This will help ensure that only trusted websites can interact with your API, reducing the risk of **cross-site request forgery (CSRF) attacks**.

# 17. Secure cloud deployments and lock down publicly exposed services

AI-generated cloud deployment configurations may leave services **publicly accessible**, creating a major security risk. If you use AI-generated Terraform, Kubernetes, or AWS configurations, you must carefully review security settings.

In AWS, GCP, or Azure environments, always:

- **Disable default public access** for storage, databases, and VMs.
- **Enforce IAM roles and least-privilege permissions** for cloud services.
- **Enable multi-factor authentication (MFA)** for all cloud accounts.

# 18. Ensure regular security audits and penetration testing

Vibe coding is not a replacement for **manual security audits and penetration testing**. Even if AI generates seemingly correct code, vulnerabilities can exist in the business logic, integrations, or configurations.

To ensure ongoing security:

- Perform **static analysis** (SAST) using tools like **SonarQube**.
- Conduct **dynamic analysis** (DAST) with penetration testing tools like **Burp Suite**.
- Schedule **manual penetration tests** at least **twice a year** to uncover logic flaws.

Security audits help identify overlooked risks and improve the security posture of vibe coding.

# 19. Educate developers on AI security risks

AI-generated code is **only as secure as the developer using it**. Many developers assume AI-generated code is flawless, but without security knowledge, they might integrate insecure solutions.

If you're reading this as a manager, CTO, or organization leader, you should:

- Train developers in **secure coding principles**.
- Educate teams on **AI-generated code risks** and common mistakes.
- Require **peer reviews** for all code before deployment.

Security awareness is the first defense against AI-related vulnerabilities.

# 20. Use updated AI models and validate their outputs

AI-generated code will continue to improve, but as a developer, you must ensure you use **updated models**. Older AI models may lack awareness of recent security best practices, and might more easily generate outdated or vulnerable code.

Regularly review:

- AI-generated **dependencies** and libraries.
- Security patches and updates for AI-assisted or vibe coding development tools.
- AI-generated security policies and configurations.

By staying up to date, you can use AI safely while mitigating risks.

# Final thoughts

Vibe coding can be a great advantage if done correctly, but AI-generated code **must never be blindly trusted**. Security remains a human responsibility, requiring carefulness, un-rushed review, and adherence to best practices to ensure applications are protected. Many software applications can seriously affect people's lives, and therefore, security should never be taken lightly.

By following these **security principles**, you can take proper advantage of vibe coding and ai-assisted development.
