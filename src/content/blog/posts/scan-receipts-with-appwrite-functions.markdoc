---
layout: post
title: Streamline receipt scanning with Appwrite Cloud
description: Learn how to extract information from receipts with Appwrite Cloud and Appwrite Functions.
date: 2024-05-01
cover: /images/blog/receipt-scan.avif
timeToRead: 15
author: vincent-ge
category: tutorial
faqs:
  - question: "What does the Hugging Face Donut Receipts model return?"
    answer: "The model returns extracted receipt fields wrapped in XML-like tags, including store name, address, phone, date, time, subtotal, tax, total, tips, and discount. You parse the response with regex (or a small parser) to pull each tagged value into a structured object you can store or return to your client."
  - question: "Why run receipt scanning inside Appwrite Functions instead of from the client?"
    answer: "Running the scan inside [Appwrite Functions](/docs/products/functions) keeps your Hugging Face API key off the client and gives you a single server-side endpoint that handles the upload, the model call, and post-processing. It also makes it easy to chain follow-up actions (storing the receipt, updating a database row) in the same execution."
  - question: "How do I store the receipt image so the function can process it?"
    answer: "Upload the image to [Appwrite Storage](/docs/products/storage) from the client, then pass the file ID to the function. The function reads the file using the server SDK, forwards the bytes to the Hugging Face model, and writes the parsed result to a database row tied to the user."
  - question: "How do I keep my Hugging Face API key secret in an Appwrite Function?"
    answer: "Set the key as an environment variable on the function and mark it as a secret in the Appwrite Console. The function reads it at runtime via `process.env` or the equivalent in your runtime, and the value is never exposed in the dashboard or API once it is marked secret."
  - question: "Can I use a different OCR or receipt extraction model?"
    answer: "Yes. The same Function structure works with any HTTP-accessible model or OCR service (other Hugging Face models, Google Document AI, AWS Textract, an OpenAI vision model). Swap the API URL, headers, and response parser, and keep the upload and storage flow the same."
  - question: "How can I make receipt entry feel low-friction for users?"
    answer: "Default to image upload over a form, parse what you can with OCR, and only ask the user to confirm or correct the extracted fields. Pre-filling totals, dates, and merchant names removes most of the typing that causes users to abandon receipt-tracking apps."
---
I downloaded a personal finance app a few weeks ago to help me track my expenses.
I uninstalled that app about five minutes later.

All it took to lose interest was the giant form I had to fill out to add a receipt.

With over [30+ apps installed on a typical user's phone](https://www.thinkwithgoogle.com/marketing-strategies/app-and-mobile/average-number-of-apps-on-smartphones/)
on average, fighting for engagement and retention is tougher than ever.
Users are sensitive to friction, with a reported 50% of users [opting for new online merchants just for lowered friction](https://www.pwc.co.uk/industries/documents/frictionless-retail-the-future-of-shopping.pdf)
and Shoppify reporting [low-friction payment methods and flows can lead to >50% increase in conversion](https://web-assets.bcg.com/e4/0c/a1acfc2a4e52a029cb37c6fe83ba/bcg-leading-online-shoppers-to-the-finish-line-june-2023.pdf).

No one has time to fill out giant forms.
To help you and your users avoid filling out giant forms, we're going to build a simple receipt processing endpoint with Appwrite
that will extract the total amount from a receipt image using a Hugging Face model.

# Prerequisites
Before you start, you'll need to know the basics of working with Appwrite Cloud and [Appwrite Functions](/docs/products/functions).
We're going to be using Appwrite Functions to run our receipt processing code, which calls a [Hugging Face model](https://huggingface.co/AdamCodd/donut-receipts-extract)
to extract data from a receipt image. Make sure you also have a **Hugging Face API key** to use the model.

# Scanning receipts
We're going to use the [Hugging Face Donut Receipts Extract model](https://huggingface.co/AdamCodd/donut-receipts-extract) to
extract relevant information from the receipt image.

Hugging Face's API makes this pretty simple. We'll send a POST request to the model's endpoint with the image data, and it will return the extracted information.

Our script can be as simple as:
```python
import requests

# define the URL and image path
API_URL = "https://api-inference.huggingface.co/models/AdamCodd/donut-receipts-extract"
headers = {"Authorization": "Bearer hf_<HF_API_KEY>"}
IMAGE = "./test.jpg"

def scan_receipt(filename):
    # read the image data
    with open(filename, "rb") as f:
        data = f.read()
    # Post the image data to the model
    response = requests.post(API_URL, headers=headers, data=data)
    return response.json()
```

The response comes back as a set of XML-like tags, we can use Regex to extract the relevant information from the model response.

```python
import re

def parse_receipt(text):
    # Use regular expressions to manually extract data between tags
    data = {}

    # Extract fields using regular expressions
    data['store_name'] = re.search(r'<s_store_name>(.*?)</s_store_name>', text).group(1)
    data['store_address'] = re.search(r'<s_store_addr>(.*?)</s_store_addr>', text).group(1)
    data['phone'] = re.search(r'<s_phone>(.*?)</s_phone>', text).group(1)
    data['date'] = re.search(r'<s_date>(.*?)</s_date>', text).group(1)
    data['time'] = re.search(r'<s_time>(.*?)</s_time>', text).group(1)
    data['subtotal'] = re.search(r'<s_subtotal>(.*?)</s_subtotal>', text).group(1)
    data['service_charge'] = re.search(r'<s_svc>(.*?)</s_svc>', text).group(1)
    data['tax'] = re.search(r'<s_tax>(.*?)</s_tax>', text).group(1)
    data['total'] = re.search(r'<s_total>(.*?)</s_total>', text).group(1)
    data['tips'] = re.search(r'<s_tips>(.*?)</s_tips>', text).group(1)
    data['discount'] = re.search(r'<s_discount>(.*?)</s_discount>', text).group(1)
    return data
```

And putting it all together:

```python
# Call the function and parse the receipt using regex
parsed_receipt = parse_receipt(
    scan_receipt(IMAGE)[0]['generated_text']
)
print(parsed_receipt)
```

Let's also quickly save our dependencies in a `requirements.txt` file by running these commands:
```bash
pip install pipreqs
pipreqs .
```

The outputted file for me just looks like this:
```txt
appwrite==5.0.2
Requests==2.31.0
```

# Integrating with Appwrite Functions
This script is great for running locally, but we want to run it in the Cloud so we can call it from our app.
We also want to make sure we're not exposing our Hugging Face API key in our app and add user based permissions to our endpoint.

Let's create a new function in Appwrite Functions to run our script. We'll use the Python starter template, which will automatically create a repo on your account,
add boilerplate code, and deploy the function to Appwrite Cloud.

![Scan function in Appwrite](/images/blog/scan-receipts-with-appwrite-functions/scan-function.avif)

Navigate to the settings tab of the function and add the following environment variables:

![Environment Variables](/images/blog/scan-receipts-with-appwrite-functions/environment-variables.avif)

The generated starter code will look like this when you clone the repo:

```python
from appwrite.client import Client
import os


# This is your Appwrite function
# It's executed each time we get a request
def main(context):
    # Why not try the Appwrite SDK?
    #
    # Set project and set API key
    # client = (
    #     Client()
    #        .set_project(os.environ["APPWRITE_FUNCTION_PROJECT_ID"])
    #        .set_key(context.req.headers["x-appwrite-key"])
    # )

    # You can log messages to the console
    context.log("Hello, Logs!")

    # If something goes wrong, log an error
    context.error("Hello, Errors!")

    # The `ctx.req` object contains the request data
    if context.req.method == "GET":
        # Send a response with the res object helpers
        # `ctx.res.text()` dispatches a string back to the client
        return context.res.text("Hello, World!")

    # `ctx.res.json()` is a handy helper for sending JSON
    return context.res.json(
        {
            "motto": "Build like a team of hundreds_",
            "learn": "https://appwrite.io/docs",
            "connect": "https://appwrite.io/discord",
            "getInspired": "https://builtwith.appwrite.io",
        }
    )

```

We will update the function to accept a file ID from Appwrite Storage, download the file, and run our receipt scanning script on it.

First, setup the Appwrite SDK in the function.
```python
import os
import requests
import re
from appwrite.client import Client
from appwrite.services.storage import Storage

def main(context):
    HF_API_KEY = os.environ['HF_API_KEY']
    HF_ENDPOINT = os.environ['HF_ENDPOINT']
    BUCKET_ID = os.environ['BUCKET_ID']
    headers = {'Authorization': HF_API_KEY}

    # Set project and set API key
    # client = new Client()
    #   .set_project(os.environ['APPWRITE_FUNCTION_PROJECT_ID'])
    #   .set_key(context.req.headers['x-appwrite-key']);

    storage = Storage(client)

    # ... rest of the code
```

Next, we'll download the file from Appwrite Storage and run our receipt scanning script on it.

```python
    # ... still in main()
    file_id = context.req.body["$id"]

    def scan_receipt(data):
        response = requests.post(HF_ENDPOINT, headers=headers, data=data)
        return response.json()

    def parse_receipt(text):
        data = {}
        data['store_name'] = re.search(r'<s_store_name>(.*?)</s_store_name>', text).group(1)
        data['store_address'] = re.search(r'<s_store_addr>(.*?)</s_store_addr>', text).group(1)
        data['phone'] = re.search(r'<s_phone>(.*?)</s_phone>', text).group(1)
        data['date'] = re.search(r'<s_date>(.*?)</s_date>', text).group(1)
        data['time'] = re.search(r'<s_time>(.*?)</s_time>', text).group(1)
        data['subtotal'] = re.search(r'<s_subtotal>(.*?)</s_subtotal>', text).group(1)
        data['service_charge'] = re.search(r'<s_svc>(.*?)</s_svc>', text).group(1)
        data['tax'] = re.search(r'<s_tax>(.*?)</s_tax>', text).group(1)
        data['total'] = re.search(r'<s_total>(.*?)</s_total>', text).group(1)
        data['tips'] = re.search(r'<s_tips>(.*?)</s_tips>', text).group(1)
        data['discount'] = re.search(r'<s_discount>(.*?)</s_discount>', text).group(1)
        return data

    image = storage.get_file_download(bucket_id=BUCKET_ID, file_id=file_id)
    parsed_receipt = parse_receipt(
        scan_receipt(image)[0]['generated_text']
    )

    # Return the data we got as a JSON response
    return context.res.json({
        "receipt": parsed_receipt
    }, 200)
```

# Make a request
Now that our function is set up, we can make a request to it from our app to scan a receipt.

Using a CURL request on the function's [domain](/docs/products/functions/domains):
```bash
curl -X POST https://66325723b6d05e9f6262.appwrite.global \
     -H "Content-Type: application/json" \
     -d '{"$id": "66328472002415a37392"}'
```

Which might return something like:

```json
{
  "receipt": {
    "store_name": " Boutique",
    "store_address": " 4143 STATE STREET LOS ANGELES, CA",
    "phone": "213-555-1234",
    "date": "2022-03-05",
    "time": "13:05PM",
    "subtotal": " $183,87",
    "service_charge": "",
    "tax": " $22,98",
    "total": " $206,85",
    "tips": "",
    "discount": ""
  }
}
```

# Next steps
There are a few things you can do to integrate this into your app. For example, you could integrate this
with a web app by uploading the receipt image to Appwrite Storage and calling the function with the file ID.

```ts
import { Storage, Functions, ExecutionMethod } from 'appwrite';

const file = await storage.createFile({
    bucketId: '6632844900292d283610', // bucketId
    fileId: '66328472002415a37392', // fileId
    file: document.getElementById('uploader').files[0] // file from html input
});

const result = await functions.createExecution({
    functionId: '66325723000399792782', // functionId
    body: JSON.stringify({$id: file.$id}), // data
    async: false, // async (optional)
    xpath: '/', // xpath (optional)
    method: ExecutionMethod.POST, // method
    headers: {"Content-Type": "application/json"} // headers
});
```

Another option is to trigger the function with a webhook when a new file is uploaded to Appwrite Storage.

![Appwrite event triggers for Function](/images/blog/scan-receipts-with-appwrite-functions/events.avif)

The function will already have the file ID in the request body, you'll just need to store the returned data in a collection
instead of returning it as a response.

# Resources

Visit our documentation to learn more about Appwrite, join us on Discord to be part of the discussion, view our blog and YouTube channel, or visit our GitHub repository to see our open-source code.

- [Docs](/docs/products/ai)
- [Discord](https://appwrite.io/discord)
- [Blog](/blog)
- [YouTube](https://www.youtube.com/channel/UCtBJ1v69gm8NgbCju_03Fiw)
- [GitHub](https://github.com/appwrite/appwrite)