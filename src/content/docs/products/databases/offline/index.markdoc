---
layout: article
title: Offline sync
description: Enable offline synchronization of data between your apps and Appwrite Databases.
---

Offline synchronization (or offline sync) is a mechanism that allows apps to store and update data locally when a user is offline (i.e., loses internet connectivity), and then synchronize that data with an Appwrite database once the user is back online.

This capability is crucial for building resilient and responsive applications, especially in environments with unreliable or intermittent internet connectivity. Suppose you are driving from one city to another and lose internet connectivitity while passing through a rural area, locally-downloaded maps in your GPS app would ensure that you do not get lost. Another example could be that you are waiting in queue at a supermarket and there is a network outage; an offline-synchronized databases with inventory data would prevent the point-of-sale (POS) systems from failing, ensuring you and your fellow customers can buy groceries.

Some real-world scenarios where offline sync is useful are:

- Journaling and note-taking apps
- Warehouse inventory management systems
- Medical data entry tools
- Airline check-in management apps
- GPS navigation software

# Integrate offline sync in your apps

{% only_light %}
{% cards %}

{% cards_item href="/integrations/replication-rxdb" title="RxDB" image="/images/docs/databases/offline/logos/rxdb.svg" %}
{% /cards_item %}

{% /cards %}
{% /only_light %}

{% only_dark %}
{% cards %}

{% cards_item href="/integrations/replication-rxdb" title="RxDB" image="/images/docs/databases/offline/logos/dark/rxdb.svg" %}
{% /cards_item %}

{% /cards %}
{% /only_dark %}

# How does offline sync work?

The process of implementing offline sync in Appwrite-powered apps (and in general) is as follows:

1. **Local data storage:** When a user opens your app, the app downloads relevant data from the server and saves it locally on their device via local-first data stores like IndexedDB, LocalStorage, SQLite, or RxDB.

2. **Working offline**: While offline, users can either read previously synced data or make changes (create, update, or delete data) in the local data store.

3. **Detecting connectivity**: The app monitors network status. As soon as connectivity is restored, a sync operation is triggered between the local data store and the Appwrite database.

5. **Two-way synchronization**: Local changes are *"pushed"* to the Appwrite database and new changes from the database are *"pulled"* into the local store. This process is called **push-pull replication**.

6. **Conflict resolution**: If the same data was changed both locally and on the server, the system must prioritise one of the two operations. Various strategies can be implemented to mitigate this issue, such as *last write wins* or *manual user conflict resolution*.