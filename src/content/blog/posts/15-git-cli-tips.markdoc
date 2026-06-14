---
layout: post
title: 15 Git command line tips every developer should know
description: Learn how to use Git in the CLI to make your workflow smoother, faster, and flexible.
date: 2024-11-06
cover: /images/blog/15-git-cli-tips/cover.avif
timeToRead: 6
author: ebenezer-don
category: tutorial
featured: false
callToAction: true
faqs:
  - question: "Is it worth learning the Git CLI if I already use a Git GUI?"
    answer: "Yes. GUIs are fine for everyday commits, but the moment something goes wrong (merge conflicts, detached HEAD, lost commits), the CLI is where the answers live. Most online help, scripts, and CI configs assume CLI knowledge, so learning the basics pays off quickly."
  - question: "What is the difference between git add and git commit?"
    answer: "`git add` stages changes (tells Git which modifications to include in the next commit), while `git commit` actually records the staged snapshot to your local history. Staging gives you the chance to group related changes together and exclude work in progress. You can also stage parts of a file with `git add -p`."
  - question: "How do I undo my last commit without losing the changes?"
    answer: "Run `git reset --soft HEAD~1`. That moves the branch pointer back one commit but keeps all your changes staged so you can re-commit them. Use `--mixed` (the default) to unstage too, or `--hard` to discard the changes entirely (only when you are sure)."
  - question: "What does git stash do?"
    answer: "`git stash` saves your uncommitted changes onto a hidden stack and reverts your working directory to a clean state. You can then switch branches, pull, or rebase, and reapply your work later with `git stash pop`. It is handy when you need to quickly jump to another task without committing half-finished work."
  - question: "How do git fetch and git pull differ?"
    answer: "`git fetch` downloads new commits from the remote but does not change your working branch, so you can inspect them before merging. `git pull` is `git fetch` plus an immediate merge (or rebase, with `--rebase`) into your current branch. Fetch is safer when you are not sure what changed upstream."
  - question: "Can I use Git to deploy Appwrite Functions?"
    answer: "Yes. [Appwrite Functions](/docs/products/functions) support Git integration so a push to a configured branch triggers a new deployment automatically. You connect a GitHub repo to your function, choose a branch and root directory, and every commit there builds and ships a new version."
---

While the command line interface can seem intimidating on the surface, it's actually a very useful tool that gives you control over your code in ways that GUIs often don't. If you can get comfortable with even a few git commands, you'll find yourself being more productive.

In this guide, we'll cover 15 essential git commands you should know as a developer, however, if you're looking for more advanced commands, you can check out these [10 new Git commands you should start using today](https://appwrite.io/blog/post/10-git-commands-you-should-start-using?dofollow=true).


# 1. git init - start a new repository

`git init` is where it all begins. This command initializes a new Git repository in the current directory, preparing it for version control. It's foundational and something you'll use every time you start a new project locally. To use it, run the following command in your terminal:

```bash
git init
```

In my experience, it's helpful to run `git init` even for smaller or personal projects because having version control from the start keeps things organized, regardless of project size.

# 2. git clone - copy an existing repository

When you're joining an existing project or working on something hosted remotely, `git clone` is the command you'll use to bring a copy of the repository to your local environment. This command connects you to the project's history and files right away.

```bash
git clone https://github.com/user/repo.git
```

`git clone` has saved me time countless times by keeping the setup process simple. It's an easy way to jump into collaboration, letting you focus on coding rather than setup.


# 3. git add - stage your work

Adding files to the staging area is one of the first steps in committing changes. `git add <file>` stages specific files, while `git add -A` stages all modified changes. Knowing how to stage changes properly is a habit that keeps commits clear and manageable.

```bash
# Add a specific file
git add index.js
```

```bash
# Add all files with changes
git add -A
```

This is essential to avoid accidental commits or messy histories. The -A option has always been useful for quickly adding everything, though it's best to be selective in more complex projects.

# 4. git commit - create a snapshot of your changes

Every Git user needs to get comfortable with `git commit -m`. This command creates a snapshot of your current staged changes with a message, making it easier to understand project history.

```bash
git commit -m "Implement user login feature"
```

A clear commit message saves so much time in the long run. It's easy to forget to describe a commit accurately, but I've found that being clear here can prevent future headaches when you're trying to track down issues.

# 5. git add [-p] - stage changes in parts

Sometimes you only want to commit specific changes from a file. `git add -p` (patch) lets you review and add individual changes in parts, making it easier to keep each commit focused on a single task.

```bash
git add -p
```

This command changed my workflow by allowing me to keep commits clean and organized. I recommend getting comfortable with it because it's invaluable when working on multiple fixes or features simultaneously.


# 6. git status - check your workspace's current state

`git status` gives you a quick look at your working directory. It shows what's staged, modified, and untracked. This command is essential to avoid committing changes you didn't intend to.

```bash
git status
```

This is a command you might find yourself using often, as it always provides a clear snapshot of where things stand before making further commits or staging changes. It's the best way to ensure no accidental changes are committed.

# 7. git log - review commit history

`git log` provides a detailed commit history, showing all commits, authors, and timestamps. Using `git log --oneline` is also helpful when you want a more concise view of the commit history, with each commit condensed to a single line.

```bash
# Full commit history

git log

# Condensed history

git log --oneline
```

This command helps track project history, and --oneline is great for quickly reviewing recent work. It's ideal for project tracking, and it's always there when you need a more thorough look.

# 8. git diff - view changes between commits or states

`git diff` is invaluable for viewing changes between your working directory and the last commit. It helps you double-check modifications before committing, ensuring everything's in order.

```bash
git diff
```

I often use this before making a commit. It's saved me from including incomplete code more times than I can count, especially on larger tasks.


# 9. git branch - list, create, and delete branches

Branching is essential for working on separate features or tasks. `git branch` helps you list existing branches, create new ones, and delete old ones. Proper branch management keeps projects organized and prevents issues with parallel work.

```bash
# List all branches

git branch

# Create a new branch

git branch feature-login

# Delete a branch

git branch -d feature-login
```

In my experience, naming branches clearly and using `git branch` frequently keeps everything manageable, especially in collaborative projects.

# 10. git checkout - switch or create new branches

Switching branches is another everyday task in Git. `git checkout <branch>` lets you move between branches, while `git checkout -b <new-branch>` creates a new branch and switches to it immediately.

```bash
# Switch to an existing branch

git checkout feature-login

# Create and switch to a new branch

git checkout -b feature-signup

```

This is one of those commands I use almost daily, and it's crucial when handling multiple feature requests. It keeps development focused without overlapping work.

# 11. git remote add origin - link local and remote repositories

When setting up a new repository, linking it to a remote is often one of the first tasks. `git remote add origin` connects your local repo to a remote, making it ready for collaborative work.

```bash
git remote add origin https://github.com/user/repo.git

```

This command can seem trivial, but getting it right initially makes the rest of the remote operations straightforward. I always double-check the URL to avoid future push and pull errors.

# 12. git pull and git push - sync local and remote changes

`git pull` and `git push` are core to any Git workflow that involves a remote repository. `git pull` brings in changes from the remote to your local branch, while `git push` sends your local commits to the remote branch.

```bash
# Pull changes from remote

git pull origin main

# Push changes to remote

git push origin main
```

Mastering pull and push is essential for collaboration. Knowing when and how to use each is key, especially in larger projects where syncing matters.

# 13. git reset <commit> - undo recent commits

Whether due to mistakes or updates, there are times when you need to backtrack. `git reset` helps you undo commits by moving the HEAD pointer to a specific commit. It's a straightforward command that's useful when mistakes need correcting.

```bash
# Undo to the previous commit

git reset HEAD~1
```

The reset command is good for clearing out commits that you consider mistakes. Although, you should be careful when using it, because it alters the commit history.

# 14. git stash - temporarily save changes without committing

In fast-paced work environments, you may need to switch tasks quickly. `git stash` lets you save your current work without committing, making it easy to come back and pick up where you left off.

```bash
git stash
```

For additional flexibility, if you have untracked files you'd like to stash as well, use:

```bash
git stash -u
```

The `git stash` command is practical in real-world scenarios. I've found it helpful for clearing the slate temporarily, especially when juggling multiple responsibilities.

# 15. git reflog - access historical changes and recover lost commits

`git reflog` is often overlooked but very useful for accessing the full history of Git commands. It can be a lifesaver when trying to recover lost work or troubleshoot complex issues.

```bash
git reflog
```

If something goes wrong, `reflog` can provide a trail to recover lost changes, making it worth knowing even if it's not used daily.

# Conclusion

These 15 Git command line tips lay a strong foundation for both solo projects and team-based workflows. Mastering these basics ensures that you'll work efficiently, minimize errors, and maintain a clean project history.
Over time, as you get comfortable with each command, your confidence in managing code will grow, and you'll be able to easily add these [10 new Git commands](https://appwrite.io/blog/post/10-git-commands-you-should-start-using?dofollow=true) to your workflow.

Git's power is in its flexibility, and learning the command line lets you tap into that fully. Practice them, apply them, and let them become second nature for a smoother development experience.

# More resources

- [How to implement Sign in with GitHub](https://appwrite.io/blog/post/implement-sign-in-with-github)
- [10 new Git commands you should start using today](https://appwrite.io/blog/post/10-git-commands-you-should-start-using?dofollow=true)
- [Building a currency converter API with Deno 2 and Appwrite](https://appwrite.io/blog/post/build-a-currency-converter-with-deno)