---
layout: post
title: "Smartbee: Revolutionizing coal mine monitoring"
description: Learn how Smartbee leveraged Appwrite to build a performant and robust system to monitor and aggregate gas level data in real-time for 7 coal mines in Columbia.
date: 2023-12-02
lastUpdated: 2026-06-29
cover: /images/blog/smartbee.avif
timeToRead: 5
author: aditya-oberai
category: customer-stories
faqs:
  - question: "What is Smartbee?"
    answer: "Smartbee is a Colombian company that provides security and communication solutions for coal mines, including IoT-based gas monitoring. Their platform aggregates real-time data on oxygen, methane, and carbon monoxide from sensors across 7 mines, in compliance with Colombian regulations."
  - question: "How does Smartbee use Appwrite?"
    answer: "Each mine runs a local self-hosted Appwrite instance that collects sensor data into [Appwrite Databases](/docs/products/databases), generates CSV reports with [Appwrite Functions](/docs/products/functions), and stores them in [Appwrite Storage](/docs/products/storage). A central Appwrite instance aggregates data from all mines for reporting."
  - question: "Can Appwrite handle IoT workloads?"
    answer: "Yes. Smartbee writes around 35 million sensor rows to Appwrite Databases per month across 70 sensors, with 4 million Function executions for synchronization and report generation. The combination of [Databases](/docs/products/databases), [Functions](/docs/products/functions), and [Storage](/docs/products/storage) covers most IoT data pipelines."
  - question: "Can I self-host Appwrite for offline or edge environments?"
    answer: "Yes. Appwrite is open source and self-hostable. Smartbee runs Appwrite locally inside each coal mine because internet connectivity is unreliable underground. Local data is synced to a central Appwrite server when connectivity is available, using [Appwrite Functions](/docs/products/functions)."
  - question: "How do I sync data between two Appwrite instances?"
    answer: "Use [Appwrite Functions](/docs/products/functions) on a schedule (or triggered by database events) to read from one instance via the server SDK and write to another. Smartbee uses Python-based functions to push data from local mine servers to a central aggregation server every 10 seconds when online."
  - question: "Why did Smartbee choose Appwrite over Firebase or Supabase?"
    answer: "Firebase is not self-hostable, which made it unusable for environments with poor connectivity. Supabase was hard to self-host at the time. Appwrite was open source, easy to self-host, and had pre-built Functions, Storage, and Databases, which let the team replace their custom Flask stack quickly."
---

In 2020, Sergio Ponguta and his brother started Smartbee, a company offering security and communications solutions for coal mining operations in Colombia. Both brothers, being formally educated in systems engineering, combined with a lack of fear of traversing down mines, felt comfortable launching this venture. 

They launched their venture by installing security cameras for mines. A requirement of their customers resulted in them setting up a communications system for telephone stations located both outside and inside the mine. As they began growing their offerings, they discovered that the Ministry of Mines and Energy in Colombia made it mandatory for all coal mines to monitor and record gas levels inside. Smartbee took this as an opportunity and started importing gas sensors to provide them to coal mines in Colombia. To add extra value on top of the sensors, they started developing a platform that would aggregate sensor data in real-time and generate reports accordingly. 


# Challenges revolving around the development of the platform

As Smartbee started developing its monitoring system, it faced several challenges:

## Mandatory regulations around sensor data collection

In 2015, the Ministerio de Minas y Energía (Colombia) made it mandatory to have permanent and continuous monitoring systems of carbon monoxide, methane, and oxygen in underground coal mines via [Decreto 1886 de 2015](https://www.funcionpublica.gov.co/eva/gestornormativo/norma.php?i=65325). The ministry gave all coal mine owners a reasonable time frame to implement these systems. However, this time frame meant that Smartbee was working against a clock to be able to cater their platform to coal mine owners. Otherwise, Smartbee would see a substantial loss on investment. On top of that, due to the small team size at Smartbee and Sergio being the only experienced software developer, they were short on hands to accomplish this monumental task.

## Performance issues of previous stacks

For the initial iteration of their MVP, Smartbee started building an online platform, using a combination of PHP and Flask (Python) containers to help manage and synchronize data streamed from their sensors in mines. This solution faced several issues, especially with load management from perpetually streaming data, implementing offline-online synchronization, and security of their servers.

To simplify and accelerate their development process, Sergio started looking for open-source Backend-as-a-Service platforms. He was aware of Firebase in the past; however, Smartbee needed a system that could also be self-hosted in the vicinity of the coal mines. He also came across Supabase; however, their high complexity to self-host turned him away.

{% call_to_action title="All-in-one development platform" description="Use built-in backend infrastructure and web hosting, all from a single place." point1="Start for free"  point2="Open source" point3="Support for over 13 SDKs" point4="Managed cloud solution" cta="Start building" url="https://cloud.appwrite.io/" /%}

# Architecting their current solution 

Amid this process, Sergio came across Appwrite. Appwrite’s high count of GitHub stars and highly active developer community were the primary validating factors for him to consider leveraging the product for his platform.

Keeping Appwrite at the center of his solution, Smartbee began working on the platform. Their gas monitoring platform would have the following rungs in the ladder:

1. **Gas monitoring sensors:** Each sensor is an IoT device that constantly monitors and transmits gas levels of Oxygen, Methane, and Carbon Monoxide in the mines
2. **Local coal mine servers:** Each local mine server accepts the data stream from the sensors in a Python container. This data is stored in a local Appwrite instance in a database table. The local Appwrite instance also has a storage bucket where CSV reports are generated and updated for daily, weekly, and monthly data using a Python-based Appwrite function. The local server also transmits information every 10 seconds (with a check added for internet availability) to a remote server that constantly aggregates all the data and reports using a Python-based Appwrite function. This server is accessed by the team of each coal mine.
3. **Remote aggregation server:** The remote Appwrite instance constantly aggregates information from all local coal mine servers. This server is accessible to teams of all the coal mines Smartbee supports (each team only has permission to access their data). The remote server can also restore data from the database table to the local coal mine server using a Python-based Appwrite function.
4. **Client application:** The client application is built with Flutter and allows the teams of all the coal mines to view and manage data from their coal mines. Flutter was specifically selected because of its cross-platform nature and high support from the Appwrite team. Smartbee is currently transitioning this application to a SvelteKit web application so it can also be used through the browser.

# The power of an entire team

Using an Appwrite-based stack enabled several benefits for the Smartbee team:

## Solving performance and security problems of a self-developed stack

In the initial MVP that Smartbee built, they faced several performance issues due to their custom Flask-based solution. The solution could barely manage the load from sensors in 2 coal mines. Since transitioning to an Appwrite-based stack, Smartbee is able to process a substantially higher amount of data:

1. **Database:** 35 million rows are added from a total of 70 sensors each month
2. **Bandwidth:** 500GB of bandwidth is transmitted between the IoT sensors, local coal mine servers, and the remote server each month
3. **Function execution:** 4 million function executions to synchronize data and generate and update the CSV reports each month

## Improved developer productivity due to SDKs, Functions runtimes, and CLI

A significant factor in accelerating Smartbee’s developer productivity was Appwrite’s developer experience. The Appwrite CLI helped Smartbee quickly set up and manage all the local coal mine Appwrite instances and the remote server. Appwrite’s pre-built API endpoints and SDKs meant that Smartbee did not have to re-create any services to support the monitoring platform. They only had to swap in the function calls from Appwrite’s SDK for Python and replace the synchronization and CSV report management scripts with Python-based Appwrite functions. One of the most significant benefits that Smartbee received was our Storage offering. In the past, Smartbee used an FTP server to store and manage reports, which had severe performance deficits, considering the high data stream from Smartbee’s IoT sensors. Appwrite's pre-built storage buckets and SDK allowed a simpler-to-implement yet performant alternative. In fact, Sergio learned Flutter and chose it for their client application because of the well-supported Appwrite SDK and learning resources available around the same.

## Support from a highly active and helpful virtual community

Aside from the GitHub stars, the other primary factor that enabled Smartbee to choose Appwrite was our highly active developer community on Discord. The Discord community allowed Sergio to get support from Appwrite whenever he needed it, which enabled him to build the platform successfully. Sergio actively gives back to the Appwrite community today, too, by participating as a voluntary moderator in our Discord server.

# Conclusion

In summary, Smartbee's adoption of Appwrite not only allowed them to address their performance issues but also significantly boosted developer productivity, resulting in a scalable and efficient monitoring platform that they have now catered to 7 coal mines in Colombia.

In Sergio’s own words,

> Just go for it, don’t think twice. Try Appwrite, and you will love it!

Learn more about Smartbee by visiting their [website](https://smartbee.com.co/).
