---
layout: article
title: Language translation with Hugging Face
description: Implement language translation into your app with Appwrite and Hugging Face.
difficulty: intermediate
readtime: 15
---

Learn to setup an Appwrite Function utilizing language translation with Hugging Face.

# Prerequisites {% #prerequisites %}

- An Appwrite project
- A [Hugging Face API key](https://huggingface.co/docs/api-inference/en/quicktour#get-your-api-token)

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
After installing the SDK, write the code that will accept a validate the request body.

Open up your `src/main.js` file and replace the function body with the following code:

```js
export default async ({ req, res }) => {
  if (!req.body.source || typeof req.body.source !== 'string') {
    return res.json({
        ok: false,
        error: 'Missing requrired field `source`',
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
        const translation = await hf.translation({
            model: 'facebook/mbart-large-50-many-to-many-mmt',
            inputs: req.body.source,
            parameters: {
                src_lang: 'en_XX', // English locale
                tgt_lang: 'fr_XX', // French locale
            }
        });
        return res.json({
            ok: true,
            output: translation.translation_text
        });
    } catch (err) {
        return res.json({
            ok: false,
            error: 'Failed to query Hugging Face'
        }, 500);
    }
}
```

First, ensure the function is called with method `POST`. Then, make a request to the Hugging Face API to translate the `source` text from English to French. You can change the `src_lang` and `tgt_lang` parameters to any language supported by the model you choose.
{% /section %}

{% section #step-5 step=5 title="Test the function" %}
Test our function by sending a POST request to the function's endpoint with a JSON body containing the `source` parameter.

Navigate to your function in the Appwrite Console and click on **Execute now**. In the modal that appears, enter the following JSON body:

```json
{
    "source": "Hello, how are you?"
}
```

Click **Execute** and you should see a response similar to the following:

```json
{
    "ok": true,
    "output": "Bonjour, comment ça va?"
}
```
{% /section %}
