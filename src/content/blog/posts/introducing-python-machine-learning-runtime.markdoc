---
layout: post
title: "Introducing the Python machine learning runtime"
description: A new runtime optimized for machine learning workloads, now available in Appwrite Cloud.
date: 2024-05-21
cover: /images/blog/introducing-python-machine-learning-runtime/cover.avif
timeToRead: 5
author: luke-silver
category: product, announcement
featured: false
faqs:
  - question: "What is the Python ML runtime in Appwrite Functions?"
    answer: "Python ML is a dedicated function runtime built on a Debian based image (instead of Alpine) so that machine learning libraries like NumPy, PyTorch, TensorFlow, and scikit learn can install correctly. Select it as `Python (ML) 3.11` when creating a function. See [Appwrite Functions](/docs/products/functions)."
  - question: "Why not just install ML libraries in the regular Python runtime?"
    answer: "The standard Python runtime is based on Alpine Linux, which keeps the image small but lacks the glibc and build toolchain that wheels for libraries like NumPy and TensorFlow expect. Python ML uses a different base image so those installations actually work."
  - question: "Does the Python ML runtime support GPU acceleration?"
    answer: "Not yet. The runtime is CPU only during beta. If your model needs a GPU, run training on a GPU host and use Python ML for inference of smaller models or for preprocessing pipelines."
  - question: "What libraries should I install for AI workloads?"
    answer: "Common picks include TensorFlow, PyTorch, scikit learn, Keras, and NLTK. Add them to your function's requirements.txt and Appwrite will install them at build time. For inference, lightweight options like ONNX Runtime or scikit learn pipelines work best within function execution limits."
  - question: "Can I run an LLM inside a Python ML function?"
    answer: "You can run small models locally, but large language models are usually too big to load into a CPU function within memory and time limits. The common pattern is to call a hosted inference API (OpenAI, Hugging Face Inference, Together) from the function and just orchestrate the call."
  - question: "How do I keep Python ML cold starts manageable?"
    answer: "Move heavy initialization (loading models, downloading weights) outside the handler so it runs once per warm container instead of on every request. Cache models in the function's filesystem when possible to avoid downloading them again."
---

# Introducing Python ML

If you're looking to build AI powered applications, you've probably considered using Python. And in case you haven't, it's probably time you did. We at Appwrite have thought about it, and our conclusion is simple. To improve the experience of building AI powered apps with Appwrite, a new runtime needs to emerge to cater to AI specific needs. 

We're excited to present Appwrite's newest Function runtime, **Python ML.** The new runtime has been tailored and optimised for machine learning use cases, saving you a lot of hassle and time, making building AI powered applications a whole lot easier.

## Python and Machine Learning

Python's simplicity and it's rich ecosystem of data science libraries have made it the preferred languages for machine learning research and implementation. The jewels of this ecosystem are libraries and frameworks specifically designed for machine learning, such as [TensorFlow](https://www.tensorflow.org/), [PyTorch](https://pytorch.org/), [scikit-learn](https://scikit-learn.org/stable/), [Keras](https://keras.io/), and [NLTK](https://www.nltk.org/). These libraries provide pre-built functions and algorithms for common machine-learning tasks, enabling developers to build and train models quickly and efficiently.

## Why build a new runtime

Appwrite's Python runtime has been one of the most popular Function runtimes. However, it has a limitation with machine learning use cases. The image was built on an Alpine Docker image. We chose Alpine because it's images offer a minimal and clean distribution of Linux. The current Python runtime has a compressed [size of under 22MB!](https://hub.docker.com/layers/openruntimes/python/v3-3.9/images/sha256-f6d89b6d2f570b8176af767fddc0b268ebeb8a9532243410e03cb60f9d9780b3?context=explore) Unfortunately, Alpine doesn't support installing machine learning libraries like `numpy` and `Tensorflow` due to the absence of some core libraries and build tools.

We decided to build a new runtime image to overcome this limitation and at the same time benefit from all the hardware-specific optimisations required for running models at scale. The Python ML runtime will allow you to install all the libraries and tools required for Machine Learning with ease. While we procure and build a world class GPU infrastructure for training, fine-tuning and inference, we'd like to bring to your notice that the Python ML runtime is currently CPU-only during the Beta. We understand this can be a challenge for certain use cases, however we have some actionable tips and tricks to overcome these limitations in the interim.

## Getting started

1. Head to the [Appwrite Console](https://cloud.appwrite.io/console) then click on **Functions** in the left sidebar and then click on the **Create Function** button.
    ![Create Function](/images/blog/introducing-python-machine-learning-runtime/create-function.avif)
2. To get started with the new runtime, navigate to your Appwrite console and select `Python (ML) 3.11` when creating a new function. 
3. Follow the step-by-step wizard and create the function.

Here's an example function that trains a simple neural network on the MNIST dataset using TensorFlow and TensorFlow Datasets:

```python
import tensorflow as tf
import tensorflow_datasets as tfds


def normalize_img(image, label):
    return tf.cast(image, tf.float32) / 255.0, label


def main(context):
    (ds_train, ds_test), ds_info = tfds.load(
        "mnist",
        split=["train", "test"],
        shuffle_files=True,
        as_supervised=True,
        with_info=True,
    )

    ds_train = ds_train.map(normalize_img, num_parallel_calls=tf.data.AUTOTUNE)
    ds_train = ds_train.cache()
    ds_train = ds_train.shuffle(ds_info.splits["train"].num_examples)
    ds_train = ds_train.batch(128)
    ds_train = ds_train.prefetch(tf.data.AUTOTUNE)

    ds_test = ds_test.map(normalize_img, num_parallel_calls=tf.data.AUTOTUNE)
    ds_test = ds_test.batch(128)
    ds_test = ds_test.cache()
    ds_test = ds_test.prefetch(tf.data.AUTOTUNE)

    model = tf.keras.models.Sequential(
        [
            tf.keras.layers.Flatten(input_shape=(28, 28)),
            tf.keras.layers.Dense(128, activation="relu"),
            tf.keras.layers.Dense(10),
        ]
    )

    model.compile(
        optimizer=tf.keras.optimizers.Adam(0.001),
        loss=tf.keras.losses.SparseCategoricalCrossentropy(from_logits=True),
        metrics=[tf.keras.metrics.SparseCategoricalAccuracy()],
    )

    model.fit(
        ds_train,
        epochs=6,
        validation_data=ds_test,
    )

    return context.res.json({"success": True})
```

Find the full source code in our [GitHub repository](https://github.com/open-runtimes/open-runtimes/tree/main/runtimes/python-ml-3.11).

## Best practices

The Python ML runtime is in *beta*. We plan to iterate in response to developer demand and needs. For now, the runtime is CPU-only. Here are some tips and tricks to help you overcome this limitation:

### Smaller models & Tensorflow Lite

Smaller models are faster to load and execute. Avoid using large models that require a lot of memory and CPU resources.

Tensorflow Lite models are optimized for mobile and edge devices. They are smaller in size and can be run on the CPU. A typical tensorflow model can be 10x smaller when converted to a Tensorflow Lite model, with only a small loss in accuracy. You can convert your Tensorflow models to Tensorflow Lite models using the [Tensorflow Lite Converter](https://www.tensorflow.org/lite/convert).

### Async executions

Synchronous executions have a global timeout limit of 30s on Appwrite Cloud. Therefore, it is recommended to use asynchronous function executions for long running tasks. This will allow you to run multiple tasks concurrently, set your own timeout period and make the most of the compute resources available. Create an asynchronous execution using the `async` parameter in the [createExecution endpoint](https://appwrite.io/docs/references/cloud/client-web/functions#createExecution).

If you see `FUNCTION_SYCHRONOUS_TIMEOUT` errors when executing your function, navigate to your **Function settings** and try increasing your timeout period to a larger number such 50 seconds or more.

![Settings timeout](/images/blog/introducing-python-machine-learning-runtime/settings-timeout.avif)

## Training vs inference

The Python ML runtime is more suitable for inference tasks. Training models on the runtime is not recommended as it can be slow and resource-intensive due to it being CPU only during the beta. We recommend training your models on a local machine or a cloud-based GPU instance and then deploying the trained model to the Python ML runtime for inference.

If you have training that does not require intensive GPU resources, you can train your models during the build step of the function and then deploy the trained model to the runtime. This will allow you to run inference tasks without the need for repeated training.

## Third-party integrations

For all other use-cases, you can use third-party AI services with Appwrite Functions. Perplexity, Replicate and OpenAI offer powerful APIs that can be used to train and run models on their infrastructure.

We've built a range of example functions and technical guides in our [AI documention](https://appwrite.io/docs/products/ai).
