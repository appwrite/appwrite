---
layout: post
title: Build an offline AI chatbot with WebLLM and WebGPU
description: Learn how to build an offline AI chatbot with WebLLM and WebGPU.
date: 2025-06-10
cover: /images/blog/chatbot-with-webllm-and-webgpu/cover.avif
timeToRead: 13
author: ebenezer-don
category: tutorial
featured: false
lastUpdated: 2025-10-14
faqs:
  - question: "What makes WebLLM different from other LLM frameworks?"
    answer: "Unlike traditional LLM setups that rely on cloud APIs, WebLLM runs models entirely in your browser. It uses WebGPU for GPU acceleration, meaning everything (from token generation to inference) happens locally. This provides privacy, faster responses, and zero server dependency once the model is downloaded."
  - question: "Can WebLLM really run large models like Llama 3 or Phi on my device?"
    answer: "Yes, but performance depends on your hardware. Smaller models like SmolLM2 (360M) run smoothly even on laptops, while Phi 3.5 Mini or Llama 3.1 8B require more VRAM (up to 5 GB). WebLLM uses quantized model formats, reducing memory needs while maintaining good accuracy for on-device inference."
  - question: "Is it possible to run the chatbot completely offline?"
    answer: "Yes. Once the model has been downloaded and cached, WebLLM stores it locally using IndexedDB. After that, you can disconnect from the internet, and the chatbot will still function. All responses are generated directly on your device using WebGPU."
---

When you hear "LLM," you probably think of APIs, tokens, and cloud infrastructure. But what if we could remove the server completely? That your browser could download a model, run it on your device, and answer questions in real time, all using just JavaScript and GPU acceleration?

Local LLMs running inside the browser were nearly impossible just a year ago. But thanks to new technologies like WebLLM and WebGPU, you can now load a full language model into memory, run it on your device, and have a real-time conversation, all without a server.

In this guide, we'll build a local chatbot that runs entirely in the browser. No backend. No API keys. By the end, you should have a good understanding of [WebLLM](https://webllm.mlc.ai/) and [WebGPU](https://developer.mozilla.org/en-US/docs/Web/API/WebGPU_API), and will have built an app that looks and functions like this:

![WebLLM and WebGPU chat app demo](/images/blog/chatbot-with-webllm-and-webgpu/webllm-webgpu-chat-app-demo.gif)

You can also try out the app using this [live URL](https://ebenezerdon.github.io/simple-webllm-chat/).

To build this, we need to first understand two important pieces: **WebLLM** and **WebGPU**.

## What is WebLLM?

WebLLM is an open-source project from the team at MLC (Machine Learning Compiler). It lets you run language models directly in your browser tab using GPU acceleration. The models are compiled into formats that your browser can understand and execute, no need to send data to a server.

Why is this important?

- It keeps user data private
- It reduces latency
- It works offline after the model download
- It removes the need for API costs or rate limits

Under the hood, WebLLM handles model loading, tokenization, execution, and streaming responses. It gives you a simple interface to load and chat with a model like LLaMA or Phi.

But WebLLM can't do it alone. It needs hardware access, and that's where WebGPU comes in.

## What is WebGPU?

WebGPU is a new browser API that gives JavaScript access to the system's GPU, not just for drawing graphics, but for running large-scale parallel computations like matrix operations and tensor math.

In our case, WebGPU lets the browser perform the heavy math required to generate text from an LLM.

Here's what WebGPU does for us:

- **Performance**: Runs faster than JavaScript or even WebAssembly for these workloads
- **GPU-first**: Designed from the ground up for compute, not just rendering
- **Accessibility**: Available across different browsers, though support varies by platform. As of June 2025:

  - **Chrome/Edge**: Fully supported on Windows, Mac, and ChromeOS since version 113. On Linux, it requires enabling the `chrome://flags/#enable-unsafe-webgpu` flag
  - **Firefox**: Available in Nightly builds by default, with stable release tentatively planned for Firefox 141
  - **Safari**: Available in Safari Technology Preview, with support in iOS 18 and visionOS 2 betas via Feature Flags
  - **Android**: Chrome 121+ supports WebGPU on Android

For production applications, you should include proper WebGPU feature detection and provide fallbacks for unsupported browsers.

Together, WebLLM and WebGPU allow us to do something powerful: load a quantized language model directly in the browser and have real-time chat without any backend server.

With this understanding of WebLLM and WebGPU, we can now start building!

## Setting up the HTML for our AI chat app

Before we write any JavaScript, we need a user interface. This will be the visible part of our app, the dropdown for selecting models, the chat area, and the input box.

Here's the plan:

- We'll create a container for everything
- Add a select box for choosing the model
- Include a progress bar for when the model is loading
- Display the chat history
- Create a form with a text input and a button to submit prompts

To begin, create an `index.html` file and paste the following code inside it:

```html
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Browser LLM Chat Demo</title>
    <style>
      /* We'll add styling here. But later! */
    </style>
  </head>
  <body>
    <div class="app-container">
      <h1>Chat with LLM (In your browser)</h1>

      <div class="controls">
        <select id="model-select">
          <option value="SmolLM2-360M-Instruct-q4f32_1-MLC">
            SmolLM2 360M (Very Small)
          </option>
          <option value="Phi-3.5-mini-instruct-q4f32_1-MLC">
            Phi 3.5 Mini (Medium)
          </option>
          <option value="Llama-3.1-8B-Instruct-q4f32_1-MLC">
            Llama 3.1 8B (Large)
          </option>
        </select>
        <button id="load-model">Load Model</button>
      </div>

      <div class="chat-container">
        <div id="output">Select a model and click "Load Model" to begin</div>

        <div
          id="progress-container"
          class="progress-container"
          style="display: none"
        >
          <div class="progress-bar">
            <div id="progress-fill" class="progress-fill"></div>
          </div>
          <div id="progress-text" class="progress-text">0%</div>
        </div>

        <form id="chat-form" class="form-group">
          <input id="prompt" placeholder="Type your question..." disabled />
          <button type="submit" disabled>Send</button>
        </form>
      </div>
    </div>
  </body>
</html>

```

In the HTML file, we've created a chat interface with controls for model selection and loading. The interface includes a chat output area, progress indicators, and an input form, which we need to let users interact with the AI model.

### Model selection

Notice that in the `div` with class `controls`, we have a `select` element for model selection and a `button` for loading the model. Here are the specifications for each model:

| Model        | Parameters   | Q4 file size | VRAM needed |
| ------------ | ------------ | ------------ | ----------- |
| SmolLM2-360M | 360 million  | ~270 MB      | ~380 MB     |
| Phi-3.5-mini | 3.8 billion  | ~2.4 GB      | ~3.7 GB     |
| Llama-3.1-8B | 8.03 billion | ~4.9 GB      | ~5 GB       |

When you're deciding which of these models to use in a browser environment with WebLLM, think first about what kind of work you want it to handle.

- **SmolLM2-360M** is the smallest by a wide margin, which means it loads quickly and puts the least strain on your device. If you're writing short notes, rewriting text, or making quick coding helpers that run in a browser, this might be all you need.

- **Phi-3.5-mini** brings more parameters and more capacity for reasoning, even though it still runs entirely in your browser. It's good for handling multi-step explanations, short document summarisation, or answering questions about moderately long prompts. If you're looking for a balance between size and capability, Phi-3.5-mini has a comfortable middle ground.

- **Llama-3.1-8B** is the largest of the three and carries more of the general knowledge and pattern recognition that bigger models can offer. It's more reliable if you're dealing with open-ended dialogue, creative writing, or complex coding tasks. But you'll need more memory.

Each of these models trades off size, memory use, and output quality in different ways. So choosing the right one depends on what your hardware can handle and what kind of prompts you plan to work with. All can run directly in modern browsers with WebGPU support.

There are more models available at the [WebLLM repository](https://github.com/mlc-ai/web-llm), ranging from smaller models for mobile devices to larger ones for more capable systems.

With the HTML in place, the next thing we'll do is work on the JavaScript implementation, then add some CSS to make it look nice. We're saving the CSS for the last step so we can focus on the core features.

## Using WebLLM and WebGPU to build the chatbot

Our JavaScript will do four main things:

1. Load the selected model into memory
2. Track the loading progress and show feedback
3. Enable the chat form once the model is ready
4. Stream the response back as the assistant types it out

We'll build this piece by piece.

### Step 1: Import WebLLM

We need to bring in WebLLM so we can access the model engine.

```js
import { CreateMLCEngine } from '<https://esm.run/@mlc-ai/web-llm@0.2.79>'
```

This gives us the function that will initialize the model.

### Step 2: Get references to the DOM elements

Let's wire up the interface. We'll grab the elements we need so we can update them later.

```js
const output = document.getElementById('output')
const form = document.getElementById('chat-form')
const promptInput = document.getElementById('prompt')
const submitButton = document.querySelector('button[type="submit"]')
const modelSelect = document.getElementById('model-select')
const loadModelButton = document.getElementById('load-model')
const progressContainer = document.getElementById('progress-container')
const progressFill = document.getElementById('progress-fill')
const progressText = document.getElementById('progress-text')
```

We'll use these to display messages, show progress, and control the form state.

### Step 3: Track the model engine

We need a variable to hold the model once it's loaded.

```js
let engine = null
```

We'll update this later when the user loads a model.

### Step 4: Show progress

When downloading and initializing the model, we want to keep the user informed.

```js
const updateProgress = (percent) => {
  progressContainer.style.display = 'block'
  progressFill.style.width = `${percent}%`
  progressText.textContent = `${percent}%`
}
```

This sets the visual width of the progress bar and updates the percentage text.

### Step 5: Load the model

Here's the key function. We call this when the user clicks the "Load Model" button.

```js
const loadModel = async (modelId) => {
  try {
    output.textContent = 'Initializing...'
    promptInput.disabled = true
    submitButton.disabled = true
    loadModelButton.disabled = true
    progressContainer.style.display = 'none'
```

We first disable the interface to prevent interference while loading.

Next, we make sure the browser supports WebGPU:

```js
if (!navigator.gpu) {
  throw new Error('WebGPU not supported in this browser...')
}
```

This check is crucial because WebGPU availability varies significantly across browsers and platforms. The code will gracefully fail if WebGPU isn't available, allowing you to show appropriate fallback content to users.

Then we download and initialize the model:

```js
engine = await CreateMLCEngine(modelId, {
  initProgressCallback: (progress) => {
    let percent =
      typeof progress === 'number'
        ? Math.floor(progress * 100)
        : Math.floor(progress.progress * 100)

    updateProgress(percent)
    output.textContent = `Loading model... ${percent}%`
  },
  useIndexedDBCache: true,
})
```

Once complete:

```js
    output.textContent = 'Model ready! Ask me something!'
    promptInput.disabled = false
    submitButton.disabled = false
    loadModelButton.disabled = false
  } catch (error) {
    loadModelButton.disabled = false
    output.innerHTML += `<div class="error">Failed to load model: ${error.message}</div>`
  }
}
```

Now the model is ready to use.

### Step 6: Handle chat form submission

When the user types a question and presses enter, this block sends it to the model:

```js
form.addEventListener('submit', async (e) => {
  e.preventDefault()

  if (!engine) {
    output.innerHTML += `<div class="error">No model loaded...</div>`
    return
  }

  const prompt = promptInput.value.trim()
  if (!prompt) return

  output.textContent = `You: ${prompt}\\n\\nAssistant: `
  promptInput.value = ''
  promptInput.disabled = true
  submitButton.disabled = true
```

Then we stream the assistant's response:

```js
  try {
    const stream = await engine.chat.completions.create({
      messages: [{ role: 'user', content: prompt }],
      stream: true,
    })

    for await (const chunk of stream) {
      const token = chunk.choices[0].delta.content || ''
      output.textContent += token
      output.scrollTop = output.scrollHeight
    }

    promptInput.disabled = false
    submitButton.disabled = false
    promptInput.focus()
  } catch (error) {
    output.innerHTML += `<div class="error">Error during chat: ${error.message}</div>`
    promptInput.disabled = false
    submitButton.disabled = false
  }
})
```

### Step 7: Trigger the model load on button click

Finally, we hook up the "Load Model" button:

```js
loadModelButton.addEventListener('click', async () => {
  await loadModel(modelSelect.value)
})
```

## Adding CSS styling

Now that we have the JavaScript in place, let's add some CSS to give the app a clean look. In the `<style>` tag in the `<head>` section of the HTML file, add the following CSS:

```css
body {
  font-family: Inter, system-ui, -apple-system, sans-serif;
  background-color: #f9fafb;
  color: #111827;
  line-height: 1.6;
  max-width: 800px;
  margin: 0 auto;
  padding: 2rem;
}

.app-container {
  background-color: white;
  border-radius: 16px;
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
  padding: 2rem;
  overflow: hidden;
  margin-top: 5rem;
}

h1 {
  font-size: 1.5rem;
  font-weight: 600;
  margin: 0 0 1.5rem;
  color: #111827;
}

#output {
  background: #f3f4f6;
  padding: 1.25rem;
  min-height: 220px;
  border-radius: 12px;
  white-space: pre-wrap;
  overflow-y: auto;
  max-height: 420px;
  font-size: 0.95rem;
}

input,
button,
select {
  font-family: inherit;
  font-size: 0.95rem;
  padding: 0.8rem 1rem;
  width: 100%;
  border-radius: 10px;
  border: 1px solid #e5e7eb;
  background-color: white;
}

button {
  background-color: #fd366e;
  color: white;
  border: none;
  font-weight: 500;
  cursor: pointer;
  transition: background-color 0.15s;
}

button:hover:not(:disabled) {
  background-color: #e62e60;
}

button:disabled {
  background-color: #ffa5c0;
  cursor: not-allowed;
}

.error {
  color: #dc2626;
  background-color: #fee2e2;
  padding: 0.8rem;
  border-radius: 10px;
  margin-top: 0.75rem;
  font-size: 0.9rem;
}

.controls {
  display: grid;
  grid-template-columns: 2fr 1fr;
  gap: 0.75rem;
  margin-bottom: 1.5rem;
}

.chat-container {
  margin-top: 1.5rem;
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}

.progress-container {
  margin-top: 1rem;
}

.progress-bar {
  width: 100%;
  height: 8px;
  background-color: #e5e7eb;
  border-radius: 999px;
  overflow: hidden;
}

.progress-fill {
  height: 100%;
  background-color: #fd366e;
  width: 0%;
  transition: width 0.3s ease;
}

.progress-text {
  font-size: 0.8rem;
  text-align: center;
  margin-top: 0.5rem;
  color: #6b7280;
}

.form-group {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

input {
  box-sizing: border-box;
  margin: 0rem;
}

input:focus,
select:focus {
  outline: none;
  border-color: rgba(253, 54, 110, 0.5);
  box-shadow: 0 0 0 1px rgba(253, 54, 110, 0.1);
}

```

Next, open the `index.html` file in your browser and you should see something like this:

![WebLLM and WebGPU chat app demo](/images/blog/chatbot-with-webllm-and-webgpu/webllm-webgpu-chat-app-demo.gif)

Try loading a model and chatting with the AI. To be sure that this all runs locally, you can also turn off your internet connection and see if the app still works after initial model download.

## Conclusion

We've built a local chatbot that runs entirely in the browser. You can now load a model, chat with it, and have a real-time conversation, all without a server.

This is the future of AI. Local, private, and fast. And what we've done is just the beginning. You can build on this foundation, add chat history, improve UX, support longer contexts, or experiment with your own compiled models.

You can also check out a more complex version of this app that includes chat history and a few other features [here](https://github.com/ebenezerdon/webllm-offline-ai).

The source code for the app we built in this guide is available on [GitHub](https://github.com/appwrite-community/simple-webllm-chat).

## Further reading

- [Building a chat app with Appwrite and Google Gemini](https://appwrite.io/blog/post/build-a-chat-app-with-appwrite-and-gemini)
- [Build a chatbot with GPT-4o and Appwrite Functions](https://appwrite.io/blog/post/personal-chatbot-gpt-4o)
- [Building a full-stack app with Svelte and Appwrite](https://appwrite.io/blog/post/build-fullstack-svelte-appwrite)
