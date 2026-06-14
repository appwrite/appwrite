---
layout: post
title: Build and deploy a personal portfolio on Appwrite Sites
description: Learn how to configure the Appwrite Sites portfolio template and deploy your changes via the GitHub integration.
date: 2025-05-28
cover: /images/blog/portfolio-template-sites/cover.avif
timeToRead: 7
author: aditya-oberai
category: tutorial
featured: false
callToAction: true
lastUpdated: 2025-10-14
faqs:
  - question: "What's the easiest way to host my developer portfolio for free?"
    answer: "Appwrite Sites is one of the simplest and developer-friendly ways to host a portfolio for free. It gives you instant static site hosting, GitHub integration for automatic deployment, and built-in SSL, all without needing to manage servers or set up custom CI/CD."
  - question: "Can I connect my custom domain to Appwrite Sites?"
    answer: "Yes. Once your portfolio is deployed, you can go to your Site's Settings, Domains in Appwrite and add your custom domain (like yourname.dev). Appwrite automatically provisions SSL certificates, so your site stays secure and uses HTTPS by default."
  - question: "Can I update my portfolio automatically whenever I push to GitHub?"
    answer: "Yes. Once your GitHub repo is linked, every git push to your production branch triggers an automatic rebuild and deployment on Appwrite. It's similar to Vercel or Netlify, but fully integrated into the Appwrite ecosystem."
  - question: "What's a good alternative to Vercel or Netlify for hosting personal projects?"
    answer: "If you're looking for an open-source, privacy-friendly alternative, Appwrite Sites is a solid choice. It offers the same ease of use, Git-based deployments, custom domains, and build logs, while also giving you access to Appwrite's full backend stack, including authentication, databases, storage, and serverless functions. Perfect if you plan to grow your personal project into something bigger later."
  - question: "How do I add analytics to my portfolio site?"
    answer: "You can integrate analytics tools directly into your portfolio's code. Since Appwrite Sites supports frameworks like Next.js or React, simply paste your tracking snippet (from tools like Plausible, Umami, or Google Analytics) inside your app's layout or <head> component. Once deployed, Appwrite will serve your site with those scripts included, allowing you to track visits and engagement automatically."
---

Today, a personal portfolio website is no less than real estate on the internet for developers. It's your digital home address, where people can discover who you are, what you do, how they can contact you, and so much more in between. However, many of us don't have our portfolio websites ready despite their importance because, even with modern vibe coding tools, a clean and straightforward portfolio is much harder to design than expected.

That's why, as part of the Sites templates, Appwrite offers a portfolio template that you can deploy in just a few short steps.

# Overview of the portfolio template

The Appwrite portfolio template is a personal portfolio app for developers. Built with Next.js and styled with Tailwind CSS, it features several pages:

- Landing page with an about section and project listing
- Individual project pages
- Contact me page with an email form (currently just logs form data on the console)

![Deployed site](/images/blog/portfolio-template-sites/deployed.avif)

# Deploy the portfolio template on Appwrite

Firstly, you must head to Appwrite Cloud and [create an account](https://cloud.appwrite.io/console/register) if you haven't already (or [self-host Appwrite 1.7](https://appwrite.io/docs/advanced/self-hosting)). Next, create your first project, which will lead you to the project overview page.

![Get started](/images/blog/portfolio-template-sites/get-started.avif)

Head to the **Sites** page from the left sidebar, click on the **Create site** button, and select the **Clone a template** option. This will take you to the Appwrite Sites templates listing, where you should select **Portfolio** under the **Use case** category on the left sidebar. This will show you numerous templates, some developed by our team and some by our partners, from which you must click on the `Portfolio template` option.

![Site templates](/images/blog/portfolio-template-sites/templates.avif)

After selecting the template, connect a GitHub repository now (you can do this later, too). Leave the production branch and root directory as is, update the domain name if you want, and click the **Deploy** button. You can watch the deployment logs as Appwrite builds your site.

![Deployment logs](/images/blog/portfolio-template-sites/deployment-logs.avif)

After your site has been successfully deployed, Appwrite will show you a **Congratulations** page. You can view the site by clicking the **Visit site** button, or view the site configuration (deployments, logs, domains, usage, and settings) by clicking the **Go to dashboard** button.

![Congratulations](/images/blog/portfolio-template-sites/congrats.avif)

# Making changes and deploying them to the site

To learn how to make changes in the portfolio site, let's update the contact form action to email you whenever someone submits a message. For this demo, we shall use Resend, a popular API service for sending emails. 

First, create an account on [Resend](https://resend.com/), then go to the **API Keys** tab in the left sidebar to create a new API key. Save this API key for later usage.

![Resend API key](/images/blog/portfolio-template-sites/resend-api-key.avif)

> Note: While this isn't mandatory for the demo, for production apps, you should add and verify a [domain](https://resend.com/docs/dashboard/domains/introduction) to send emails via Resend.

Next, you must update the environment variables of our Appwrite Site. Head back to your Appwrite project, visit **Sites** from the left sidebar, and click on your portfolio site. Head to the **Settings** tab of your site, scroll down to the **Environment variables** section, and create the following environment variables:

- `RESEND_API_KEY`: The Resend API key you created earlier
- `EMAIL_ADDRESS`: The email address you want to receive contact form messages on

![Site environment variables](/images/blog/portfolio-template-sites/env-vars.avif)

Lastly, clone the repository Appwrite created for your portfolio site. Enter the directory, and install Resend's Node.js library by running the following command:

```js
npm install resend
```

Then, head to the `src/actions/contact.ts` file and update it to the following:

```js
'use server'
import { Resend } from "resend"

export const submitContactForm = async (formData: FormData) => {
  try {
    const rawFormData = {
      name: formData.get('name'),
      email: formData.get('email'),
      subject: formData.get('subject'),
      message: formData.get('message'),
    }

    console.log(rawFormData);

    const resendApiKey = process.env.RESEND_API_KEY;
    const emailAddress = process.env.EMAIL_ADDRESS;
    if (!resendApiKey || !emailAddress) {
      throw new Error('Missing environment variables for Resend API key or email address');
    }

    const resend = new Resend(resendApiKey);

    await resend.emails.send({
      from: 'Acme <onboarding@resend.dev>', // You can switch this out with an email of your domain once added to Resend
      to: [emailAddress],
      subject: `New message from ${rawFormData.name}`,
      text: `Name: ${rawFormData.name}\n\nEmail: ${rawFormData.email}\n\nSubject: ${rawFormData.subject}\n\nMessage: ${rawFormData.message}`
    });
  } catch (error) {
    console.error('Error sending email:', error);
    throw new Error('Failed to send contact form. Please try again later.');
  }
}
```

You can now commit these changes and push them to GitHub, automatically deploying the updated site to Appwrite.

# Next steps

And with that, the personal portfolio template is deployed to Appwrite Sites. You can explore other templates or deploy any other websites you'd like. 

For more information about Appwrite Sites:

- [Appwrite Sites product docs](/docs/products/sites)
- [Quick start to deploy web app](/docs/products/sites/quick-start)
- [Deploy Appwrite Site templates](/docs/products/sites/templates)
- [Appwrite Discord server](/discord)
