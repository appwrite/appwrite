---
layout: post
title: Building with Appwrite AI Function templates
description: Get an overview of Appwrite AI Function templates and what you can build.
date: 2024-05-16
cover: /images/blog/building-with-ai-function-templates/function-templates.avif
timeToRead: 7
author: aditya-oberai
category: tutorial
faqs:
  - question: "What are Appwrite AI Function templates?"
    answer: "They are pre-built Appwrite Functions that cover common AI tasks like image classification, object detection, text generation, summarization, and translation. You can clone a template from the Appwrite Console, plug in your API keys for providers like OpenAI or Hugging Face, and have a working endpoint in minutes. See [Appwrite Functions](/docs/products/functions) for how templates are deployed."
  - question: "Which AI providers do the templates integrate with?"
    answer: "The initial set of templates covers Hugging Face Inference, OpenAI, and Perplexity, with more added over time. Each template is just code, so you can swap in any provider you want. The template's job is to give you a working starting point that handles auth, request shape, and error handling."
  - question: "What is the difference between image classification and object detection?"
    answer: "Image classification assigns a single label, or a short set of labels, to a whole image, for example tagging a photo as a cat or a dog. Object detection finds multiple items inside an image and gives you bounding boxes for each one, so you can locate them. Detection is heavier but useful for inventory, surveillance, and self-driving style use cases."
  - question: "Do I need to manage my own AI infrastructure to use these templates?"
    answer: "No. The templates call hosted inference APIs, so you do not need to provision GPUs or run your own model servers. Appwrite handles function execution and scaling for you. If you later want to host your own models, you can swap the template's API call for a self-hosted endpoint."
  - question: "How do I secure my AI provider API keys in an Appwrite Function?"
    answer: "Store them as environment variables on the function, not in source code. Appwrite encrypts these values and injects them at runtime, so the keys never leave the server. Never ship them in client-side code or commit them to a public repo. See [Appwrite Functions](/docs/products/functions) for environment variable setup."
  - question: "Can I use AI Function templates for production workloads?"
    answer: "Yes, with the same caveats as any third-party AI service: watch your provider rate limits, add retries for transient failures, and consider caching responses where the input is repeatable. The templates are starting points, not full production systems, so plan to add logging and observability before you ship to real users."
---

It’s an exciting time for software development, as many new concepts and techniques pop up every day, giving us endless possibilities to build new and shiny things. But this sea of opportunity can be hard to navigate and keep up with. AI, for instance, is a field that is rapidly evolving and is influencing not only the products we can build but also the way we develop them. However, building AI-powered applications can be complicated. Appwrite’s new AI Function templates make it easier to build AI powered applications. 

So, what did we add? This blog provides an overview of all the concepts we’ve added, tutorials, templates, and the integrations we support.

# Computer vision

[Computer vision](https://appwrite.io/blog/post/state-of-computer-vision) is a field of AI aiming to provide machines with a comprehensive understanding of visual data from a variety of sources. Two sub techniques we added support for are image classification and object detection. Both techniques are widely used in our day to day lives, let’s take a look.

## Image classification

Image classification is a process used in machine learning and artificial intelligence to categorize images into different groups based on their content. It’s a technique that serves many use cases, such as:

1. **Healthcare**: In the medical field, image classification is used to analyze medical images, such as X-rays, MRIs, and CT scans, to detect and diagnose diseases. For example, AI can identify patterns in imaging that may indicate tumors, fractures, or other abnormalities.
2. **Autonomous vehicles**: Self-driving cars use image classification to understand their surroundings. Cameras capture images that the system classifies in real-time to identify road signs, pedestrians, other vehicles, and various obstacles to navigate safely.
3. **Retail**: Image classification can automate inventory management by identifying products on shelves. It's also used in visual search systems, allowing customers to search for products by uploading images instead of using text-based queries.

## Function templates added to the Console

- Image Classification - Classify images using the Hugging Face inference API.

## Tutorial

{% cards %}
{% cards_item href="/docs/products/ai/tutorials/image-classification" title="Image classification" %}
Understand and label the contents of images
{% /cards_item %}
{% /cards %}

## Object detection

Object detection is a technology used in computer vision that not only categorizes images but also identifies and locates multiple objects within a single image. It's a step beyond basic image classification because it involves not just identifying what objects are present in an image, but also pinpointing their specific boundaries (usually through bounding boxes). The technique has made many media appearances, and companies like Tesla and Apple have widely used it in their products. Some examples:

1. **Autonomous driving**: Object detection is critical for autonomous vehicles to interpret their surroundings accurately. It helps cars detect and localize objects like pedestrians, other vehicles, traffic lights, and road signs, facilitating safe navigation and decision-making.
2. **Face recognition**: In security and surveillance, object detection is used for facial recognition systems. These systems identify and verify individuals by detecting faces in images or video streams, useful in law enforcement, secure facility access, and authentication systems.
3. **Retail**: In retail, object detection is used for customer behavior analysis, stock management, and theft prevention. Cameras can detect when items are picked from shelves, track customer movements, and identify actions that might indicate suspicious behavior.

## Function templates added to the Console

- Object Detection - Detect objects in images using the Hugging Face inference API.

## Tutorial

{% cards %}
{% cards_item href="/docs/products/ai/tutorials/object-detection" title="Object detection" %}
Detect and label objects in images
{% /cards_item %}
{% /cards %}

# Natural language processing

[Natural language processing](https://appwrite.io/blog/post/state-of-natural-language-processing) (NLP) is a fascinating intersection of computer science, artificial intelligence, and linguistics. It's about teaching computers to understand, interpret, and generate human language. Translating languages, answering questions, or helping find information, NLP is at the heart of many technologies we use every day. Two techniques that Appwrite Function templates support are text generation and language translation.

## Text generation

Text generation, facilitated by technologies like artificial neural networks, specifically those designed for natural language processing (NLP), has a wide range of applications. The most well known applications are probably chatbots, but it supports a lot more applications you (probably) use every day:

1. **Personal assistants**: Digital assistants such as Siri, Alexa, and Google Assistant use text generation to converse with users, manage tasks, and provide information in a natural, conversational manner.
2. **Coding and development**: AI-driven text generation aids developers by suggesting code completions, generating documentation, and even writing code snippets based on descriptions of functionality.
3. **Creative writing**: AI can assist in generating creative text for stories, poetry, scripts, and even music compositions, providing a new tool for artists and writers to explore creative ideas.

## Function templates added to the Console

- Text Generation - Generate text using the Hugging Face inference API.
- Chat - Create a chatbot using the Perplexity AI API.
- Prompt ChatGPT - Ask questions and let OpenAI GPT-3.5-turbo answer.
- RAG with LangChain - Generate text using a LangChain RAG model.

## Tutorial

{% cards %}
{% cards_item href="/docs/products/ai/tutorials/text-generation" title="Text generation" %}
Generate human-like text
{% /cards_item %}
{% /cards %}

## Language translation

Language translation, particularly when facilitated by advanced artificial intelligence (AI) technologies, is a crucial application of natural language processing (NLP) that serves numerous practical and essential purposes. Thanks to this technological advancement, we no longer need to master a language to order food in a foreign restaurant. Here are more use cases:

1. **Translation services**: Language translation is critical in machine translation services like Google Translate, which convert text from one language to another. While not perfect, these services are continually improving and are invaluable for global communication.
2. **Education and learning**: Translation tools help students and educators access a broader range of educational materials and resources. They allow people to learn languages independently or access courses and content that are not available in their native language.
3. **Travel and tourism**: Translation apps and devices are indispensable for travelers, helping them navigate foreign environments, read signs and menus, communicate with locals, and understand cultural nuances.

## Function templates added to the Console

- Analyze - Automate moderation by getting the toxicity of messages.
- Language Translation - Translate text using the Hugging Face inference API.

## Tutorial

{% cards %}
{% cards_item href="/docs/products/ai/tutorials/language-translation" title="Language translation" %}
Translate text between languages
{% /cards_item %}
{% /cards %}

# Audio processing

[Audio processing](https://appwrite.io/blog/post/state-of-audio-processing) is a field of machine learning that allows machines to understand, analyze, and manipulate various audio signals. The applications are vast and varied, from speech recognition to music generation and noise reduction. It's used in many everyday tools you use, including voice assistants, music streaming services, and for noise reduction in online calls. We’ve added support for speech to recognition, text-to-speech, and even music generation.

## Speech to recognition

Speech recognition technology, which converts spoken language into text, is a vital component of modern computing interfaces and has numerous applications across various sectors. One of its most known use cases is the ability to turn speech into text messages on your phone. But who actually uses this? Here are some better use cases:

1. **Virtual assistants**: Devices like smartphones and smart speakers use speech recognition to enable users to interact with them through voice commands. Virtual assistants like Siri, Alexa, and Google Assistant respond to inquiries, control smart home devices, set reminders, and play music, all initiated by voice.
2. **Accessibility**: Speech recognition significantly enhances accessibility for individuals with disabilities. It allows people who have difficulty using conventional input devices (like keyboards and mice) to interact with computers and mobile devices using their voice.
3. **Customer service**: Many companies employ speech recognition in their customer service operations, using voice-driven interfaces to handle customer inquiries, automate responses, and route calls to appropriate departments without human intervention.

## Function templates added to the Console

- Speech Recognition - Transcribe audio to text using the Hugging Face inference API.

## Tutorial

{% cards %}
{% cards_item href="/docs/products/ai/tutorials/speech-recognition" title="Speech recongition" %}
Efficiently convert speech to text
{% /cards_item %}
{% /cards %}

## Text to speech

Text-to-speech (TTS) technology converts written text into spoken words, and these days, it has made headlines for its help in creating deep fakes. But it’s not all bad as it can be a very helpful tool for many use cases such as:

1. **Consumer electronics**: Many devices, including smartphones and computers, incorporate TTS to read text aloud, from navigation directions in GPS systems to operating instructions and notifications.
2. **Telecommunications**: Companies use TTS for automated telephony systems, which help in delivering information to callers and guiding them through menu options without human intervention.
3. **Media**: TTS technology is used in news reading apps and websites, where it can read articles to users, making content consumption possible even while multitasking.

## Function templates added to the Console

- Text to Speech - Convert text to speech using the Hugging Face inference API.
- Speak with ElevenLabs - Convert text to speech using the ElevenLabs API.
- Speak with LMNT - Convert text to speech using the LMNT API.

## Tutorial

{% cards %}
{% cards_item href="/docs/products/ai/tutorials/text-to-speech" title="Text to speech" %}
Generate music from a text prompt
{% /cards_item %}
{% /cards %}

## Music generation

Music generation with artificial intelligence (AI) involves using machine learning algorithms to create music, often without direct human input for composition. Although many artists are not happy with this trend, the technology is growing in popularity and applications. Here are some examples where AI generated music is used:

1. **Film and Video Games**: AI can generate background music and sound effects for films and video games, adapting dynamically to the visuals or gameplay to enhance the user experience without the need to compose each piece of music manually.
2. **Music Production**: AI assists artists by generating music samples, beats, or entire compositions. Musicians can modify these AI-generated elements or use them to inspire new works. This technology is particularly helpful for artists with limited access to professional studios or expensive equipment.
3. **Copyright-free Music Generation**: AI can generate original compositions that do not require licensing fees for creators who need copyright-free music for videos, podcasts, or other media.

## Function templates added to the Console

- Music generation - Generate music from a text prompt using the Hugging Face inference API.

## Tutorial

{% cards %}
{% cards_item href="/docs/products/ai/tutorials/music-generation" title="Music generation" %}
Efficiently convert text to music
{% /cards_item %}
{% /cards %}

# Getting started with AI and Appwrite

As we have covered in this blog, we have added a lot of great templates for you to get started on your AI journey. Including support for AI integrations:

- [Pinecone](https://appwrite.io/docs/products/ai/integrations/pinecone)
- [OpenAI](https://appwrite.io/docs/products/ai/integrations/openai)
- [Hugging Face](https://appwrite.io/docs/products/ai/tutorials/image-classification)
- [ElevenLabs](https://appwrite.io/docs/products/ai/integrations/elevenlabs)
- [Perplexity](https://appwrite.io/docs/products/ai/integrations/perplexity)
- [Replicate](https://appwrite.io/docs/products/ai/integrations/replicate)
- [fal.ai](https://appwrite.io/docs/products/ai/integrations/fal-ai)
- [LangChain](https://appwrite.io/docs/products/ai/integrations/langchain)
- [Together AI](https://appwrite.io/docs/products/ai/integrations/togetherai)

![Image of Appwrite Console](/images/blog/building-with-ai-function-templates/function-templates-overview.avif)

We've also added more AI Function templates for more use cases than discussed above:
- Sync with Pinecone - Sync your Appwrite database with Pinecone's vector database.
- Generate with fal.ai - Generate images using fal.ai's API.
- Censor with Redact - Censor sensitive information from a provided text string using Redact API by Pangea.
- Analyze with PerspectiveAPI - Automate moderation by getting toxicity of messages.
- Generate with Replicate - Generate text, audio and images using Replicate's API.

Take a look at these two blogs to learn more about using Function templates in your projects:

- [Streamline receipt scanning with Appwrite Cloud](https://appwrite.io/blog/post/scan-receipts-with-appwrite-functions)
- [Build an intelligent chatbot with ChatGPT and Appwrite Functions](https://appwrite.io/blog/post/function-template-prompt-chatgpt)

We will continue to add more AI tutorials, integrations, blogs, videos, and Function templates. You can also contribute by adding your own pull requests to the [repository](https://github.com/appwrite/templates).
