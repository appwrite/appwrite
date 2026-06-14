---
layout: post
title: "Introducing Roles: Enhanced collaboration and security in Appwrite"
description: Roles is a new addition to the Appwrite Console that has been anticipated for a long time by the community. This feature will make permission handling a whole lot easier.
date: 2024-09-25
lastUpdated: 2026-05-22
cover: /images/blog/new-roles/cover.avif
timeToRead: 8
author: eldad-fux
category: product, announcement
featured: false
faqs:
  - question: "What roles can I assign to team members in the Appwrite Console?"
    answer: "Appwrite offers five built-in organization roles: Owner, Developer, Editor, Analyst, and Billing. Each role grants a different scope of access, from full administrative control to read-only or billing-only access. Custom roles for the Enterprise plan are coming soon."
  - question: "What is the difference between an Owner and a Developer in Appwrite?"
    answer: "Owners have full control including team management, billing, and project creation. Developers have access to all development resources like databases, functions, and storage but cannot manage the team or billing. Use Developer for engineers who need to build but should not change membership or financial settings."
  - question: "Can I give a team member read-only access in Appwrite?"
    answer: "Yes. The Analyst role provides read-only access to all resources in the project, ideal for stakeholders who need to view data, analytics, or reports without making changes. They can browse the console without any risk of modifying state."
  - question: "What is the principle of least privilege?"
    answer: "Least privilege means giving users only the permissions they need to do their job and nothing more. Applied to a backend platform, it means using purpose-built roles like Editor or Analyst for non-engineers rather than handing everyone Owner access. This limits the blast radius if a credential is compromised."
  - question: "Are custom roles available in Appwrite?"
    answer: "Custom Roles are coming soon to the Enterprise plan and will let you fine-tune permissions to match your team structure. Once available, if the built-in roles do not match your access policy, you can define exactly which actions each role can perform."
  - question: "How is organization-level access different from project-level permissions in Appwrite?"
    answer: "Organization roles control what users can do in the Appwrite Console, like creating projects or managing billing. Resource-level permissions on databases, files, and other [Appwrite resources](/docs/products/databases) control what end users of your application can read or write. They serve different purposes and are configured separately."
---

We’re excited to announce a new feature available in the Pro and Enterprise plans: **Roles**. This enhancement is designed to bring granular permissions to the Appwrite Console, improving both team collaboration and security across your projects.

# The benefit of multiple roles

Previously, you could only invite team members to your organization with one role that gave equal access to all members, regardless of their function. Needless to say, this is not an ideal situation, especially as your team expands, bringing more complexities in managing permissions. With Roles, you can now assign specific permissions to each member of your team, ensuring everyone has the right level of access to their responsibilities. This update provides more control over who can access, modify, and manage different parts of your organization’s projects, making it a key addition to growing teams and larger projects. Managing access to your Appwrite Console has never been easier. 

# Overview of the new Roles in Appwrite

Before this feature update, you only had one role:

- **Owner**: Full control over all aspects of the console, including team management, billing, and development resources. Only Owners can create new projects.

But now, alongside the existing owner role, we’ve added four new roles that you can assign to your team members:
- **Developer**: Developers have access to all development resources but cannot manage the team or billing settings, making this ideal for members focusing solely on coding and technical tasks.
- **Editor**: Editors can modify most resources but cannot alter key backend elements such as collections, buckets, and topics. This is perfect for team members who handle content updates or file uploads without needing to adjust infrastructure.
- **Analyst**: Analysts have read-only access to all resources, ideal for team members who need to view data, analytics, or reports without making changes.
- **Billing**: This role is strictly for billing-related actions, with access limited to billing details only, keeping financial data secure without touching other areas of your projects.

# Invite more members for free
The new feature is now available on both the Pro and Enterprise plans, with unlimited members on every paid project after Appwrite moved to per-project pricing.

# How to add new members with roles

To add new members with a specific Role to your organization, go to your organization overview and click `Invite` on the right. 

![Console invite](/images/blog/new-roles/console2.avif)

A modal will pop up, allowing you to invite new members and assign them one of the new roles.

![Console invite](/images/blog/new-roles/console.avif)

# What’s next: Custom Roles

For teams with more specific needs, we’re excited to share that **Custom Roles** will soon be available for the Enterprise plan. These roles will allow you to fine-tune permissions, creating tailored access levels based on your unique team structure and workflow.

# More flexibility

Whether you’re working with developers, content editors, analysts, or billing teams, with Roles, you can now assign predefined permissions tailored to different needs. This keeps your workflows smooth while safeguarding critical areas of your project.

Learn more about **Roles** in our [documentation](https://appwrite.io/docs).
