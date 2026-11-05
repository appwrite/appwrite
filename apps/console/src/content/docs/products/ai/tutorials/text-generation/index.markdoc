---
layout: article
title: Text generation with Hugging Face
description: Implement text generation into your app with Appwrite and Hugging Face.
difficulty: intermediate
readtime: 15
---

Learn to setup an Appwrite Function utilizing text generation with Hugging Face.

# Prerequisites {% #prerequisites %}

- An Appwrite project
- A [Hugging Face API keys](https://huggingface.co/docs/api-inference/en/quicktour#get-your-api-token)

{% section #step-1 step=1 title="Create new function" %}
Head to the [Appwrite Console](https://cloud.appwrite.io/console) then click on **Functions** in the left sidebar and then click on the **Create Function** button.

{% only_dark %}
![Create function screen](/images/docs/functions/dark/template.avif)
{% /only_dark %}

{% only_light %}
![Create function screen](/images/docs/functions/template.avif)
{% /only_light %}

1. In the Appwrite Console's sidebar, click **Functions**.
1. Click **Create function**.
1. Under **Connect Git repository**, select your provider.
1. After connecting to GitHub, under **Quick start**, select the **Node.js** starter template.
1. In the **Variables** step, add the `HUGGINGFACE_ACCESS_TOKEN`, generate it [here](https://huggingface.co/docs/api-inference/en/quicktour#get-your-api-token).
1. Follow the step-by-step wizard and create the function.
{% /section %}

{% section #step-2 step=2 title="Add HuggingFace SDK" %}
Once the function is created, clone the function and open it in your development environment.

Once you have the repository open, you can install the Hugging Face inference SDK by running the following command in your terminal:

```bash
npm install @huggingface/inference
```
{% /section %}

{% section #step-3 step=3 title="Parse payload body" %}
After installing the SDK, write the code that will accept a JSON body.

Open up your `src/main.js` file and replace the function body with the following code:

```js
export default async ({ req, res }) => {
  if (!req.body.prompt || typeof req.body.prompt !== 'string') {
    return res.json({
        ok: false,
        error: 'Missing required field `prompt`'
    }, 400);
  }
}
```
{% /section %}

{% section #step-4 step=4 title="Make a request to Hugging Face" %}
Add the following import at the top of your `src/main.js` file:

```js
import { HfInference } from '@huggingface/inference';
```

In your function body, add the following code after the parameter checks:

```js

export default async ({ req, res }) => {
    // ... existing parameter checks

    const hf = new HfInference(process.env.HUGGINGFACE_ACCESS_TOKEN);

    try {
        const completion = await hf.textGeneration({
            model: 'mistralai/Mistral-7B-Instruct-v0.2',
            inputs: req.body.prompt,
            max_new_tokens: req.body.max_new_tokens || 200,
        });
        return res.json({ ok: true, completion }, 200);
    } catch (err) {
        return res.json({ ok: false, error: 'Failed to query model.' }, 500);
    }
}
```

The function makes a request to the Hugging Face API with the prompt provided in the request body. The response will be sent back to the client.
{% /section %}

{% section #step-5 step=5 title="Test the function" %}
Test our function by sending a POST request to the function's endpoint with a JSON body containing the `prompt` parameter.

Navigate to your function in the Appwrite Console and click on **Execute now**. In the modal that appears, enter the following JSON body:

```json
{
    "prompt": "Write a story about a dragon",
}
```

Click **Execute** and you should see a response similar to the following:

```json
{
    "ok": true,
    "completion": "Once upon a time, in a land far away, there was a dragon... [truncated]"
}
```
{% /section %}
