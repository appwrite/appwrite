---
layout: post
title: "Budget caps: How to stop unexpected cloud bills before they happen"
description: Learn how to set budget caps in your Appwrite console to avoid unexpected cloud bills.
date: 2025-01-23
cover: /images/blog/budget-caps-stop-unexpected-cloud-bills/cover.avif
timeToRead: 3
author: ebenezer-don
category: tutorial
featured: false
faqs:
  - question: "What is a budget cap?"
    answer: "A budget cap is a hard spending limit on your cloud account. Unlike a billing alert that just sends an email, a cap stops new charges once you hit your limit. This protects you from surprise bills when something goes wrong overnight."
  - question: "How do I set a budget cap in Appwrite?"
    answer: "Open the Billing tab in your Appwrite Cloud console, click View estimated usage to see your typical consumption, then enable the budget cap toggle and pick a monthly limit. You can also configure alerts at 25%, 50%, 75%, and 100% of your cap."
  - question: "Does Firebase have hard budget caps?"
    answer: "No. Firebase offers budget alerts, which send notifications when you hit thresholds, but they don't stop charges. That's why stories like the $70k Firebase bill keep happening: by the time someone sees the alert, the damage is already done."
  - question: "What happens when I hit my Appwrite budget cap?"
    answer: "New paid usage stops until you explicitly raise the cap or move to a higher plan. Your project's existing data stays intact, but billable resources stop accruing new charges. This is the safety net you want for unexpected traffic spikes or bugs."
  - question: "How do I handle a legitimate traffic spike with a budget cap in place?"
    answer: "The percentage-based alerts give you a heads-up before you hit the cap, so you can review your metrics and raise the cap if the growth is legitimate. If the spike looks abnormal, the cap protects you while you investigate the cause."
  - question: "Are budget caps available on all Appwrite plans?"
    answer: "Yes. Budget caps are available across Appwrite Cloud plans. Check the [Appwrite docs](/docs/advanced/platform/billing) for the latest configuration steps and limits per plan."
---

You might have come across the recent post about a Firebase user who got hit with a $70k bill. This has caused another round of debates about cloud billing practices and who's responsible when things go wrong. These stories keep happening because most cloud providers only offer alerts, not hard stops.

![Firebase bill post](/images/blog/budget-caps-stop-unexpected-cloud-bills/Image1-post.avif)

In Appwrite, we believe you should have real control over your cloud spending. And this is why we took a different approach. In this article, we'll learn how Appwrite Cloud prevents this kind of situation, and why budget caps matter when it comes to cloud billing.

# What happened with Firebase?

A Firebase user recently got an alert that changed their day. They owed Google $70k after their app accidentally stored 1 petabyte of data in Google Cloud Storage. The initial bill was $61,200, but it got even worse by the time their developer could step in and downgrade the project, as they had already accumulated around $7k in additional charges.

# The problem with just having alerts

Firebase does have budget alerts, which will send you an email when you hit certain spending thresholds. But there's a fundamental problem with relying only on alerts: they don't actually stop the charges.

Think about what happens if you're:

- Traveling when the alert comes in
- Unable to access your computer right away
- Simply miss the email

By the time you see that alert and can do something about it, you might already be thousands of dollars in debt. That's why we took a different approach with Appwrite.

# How Appwrite's budget caps work

We built budget caps into Appwrite because we believe you should have real control over your cloud spending. And setting a budget cap in your project is quite straightforward.

## 1. Understanding your usage

In your Appwrite console, you can set a budget cap for an organization by opening the **Billing** tab.

![Billing tab in Appwrite console](/images/blog/budget-caps-stop-unexpected-cloud-bills/Image2-billing-tab.avif)

Before setting any caps, you can click on **View estimated usage** to get a clear picture of your typical consumption patterns. This helps you set a cap that makes sense for your app.

![Usage statistics](/images/blog/budget-caps-stop-unexpected-cloud-bills/Image3-usage.avif)

## 2. Setting your cap

Once you know your usage patterns, go back to the **Billing** tab and scroll down to the **budget cap** section, then toggle the **Enable budget cap** switch. Here you can set your monthly budget limit. With your budget cap set, once you hit the limit, no more charges can go through until you explicitly allow them.

![Budget cap settings](/images/blog/budget-caps-stop-unexpected-cloud-bills/Image4-budget-cap.avif)

## 3. Layered protection with alerts

While the hard cap is your ultimate protection, we also give you graduated alerts to help manage your spending proactively. You can set notifications for when you use:

- 25% of your budget
- 50% of your budget
- 75% of your budget
- 100% of your budget

![Billing alerts settings](/images/blog/budget-caps-stop-unexpected-cloud-bills/Image5-billing-alerts.avif)

These percentage-based alerts help you track spending patterns and catch unusual activity early. If you notice you're hitting 50% faster than usual, you can investigate before it becomes a problem.

# Handling growth and traffic spikes

One question that often gets asked is how to handle sudden traffic spikes. What if your app gets featured somewhere and usage jumps? The combination of alerts and caps handles this really well:

1. The percentage-based alerts warn you early if usage is climbing unusually fast
2. You have time to review your metrics and understand what's happening
3. If it's legitimate growth, you can adjust your cap before it becomes an issue
4. If it's unexpected behavior, the cap protects you while you investigate

# Building with confidence

Stories about unexpected cloud bills keep popping up, and they all share a common thread; by the time someone notices, it's too late. A simple configuration mistake or unexpected behavior should not generate a five-figure bill overnight. We built budget caps in Appwrite because we believe you should be able to focus on building your app without worrying about surprise bills.

A few minutes spent setting up these safeguards now means you never have to write one of those "How I Got a $70k Cloud Bill" posts. That's the kind of peace of mind every developer deserves.

For more details about our billing features or if you need help setting up your budget caps, check out our [documentation](https://appwrite.io/docs/advanced/platform/pro#budget-cap) or reach out to our team. We're always here to help!

We also made a YouTube video on this topic which you can check out [here](https://youtu.be/WsNTnBWGCQ0).

# More resources

- [Appwrite Billing](https://appwrite.io/docs/advanced/platform/billing?doFollow=true)
- [Don't make this mistake, set budget caps (YouTube)](https://youtu.be/WsNTnBWGCQ0?doFollow=true)
- [How to optimize your Appwrite project for cost and performance](https://appwrite.io/blog/post/how-to-optimize-your-appwrite-project?doFollow=true)
