---
layout: post
title: A technical deep dive into image classification
description: Learn all about image classification and how to integrate it into your Appwrite project using TensorFlow
date: 2024-08-06
lastUpdated: 2026-06-29
cover: /images/blog/image-classification.avif
timeToRead: 9
author: bradley-schofield
category: product
faqs:
  - question: "What is image classification?"
    answer: "Image classification is a computer vision task where a model takes an image and assigns it one or more labels describing what's in it. Use cases include moderating user uploads, categorizing product photos, organizing media libraries, and tagging content for search. It's distinct from object detection, which also locates objects within the image."
  - question: "Which architectures lead image classification benchmarks today?"
    answer: "Vision Transformers (ViT, Swin Transformer) currently top most major benchmarks including ImageNet, CIFAR-10, and STL-10. Convolutional Neural Networks (CNNs) like EfficientNet still hold their ground on datasets with many classes (CIFAR-100) and remain more data-efficient than transformers."
  - question: "What datasets should I train on?"
    answer: "ImageNet is the standard for general-purpose classification with 14 million labeled images across thousands of categories. CIFAR-10 and CIFAR-100 are smaller (60K images at 32x32) and useful for fast iteration. MNIST handles handwritten digits. For domain-specific apps, you'll usually need to collect or fine-tune on your own labeled data."
  - question: "How do I deploy an image classification model with Appwrite?"
    answer: "Train your model with TensorFlow or PyTorch on your own machine, export it, and ship the inference code as an [Appwrite Function](/docs/products/functions). The function receives the image (from [Appwrite Storage](/docs/products/storage) or as an upload), runs inference, and returns the prediction. Functions support Python out of the box."
  - question: "Should I use a pre-trained model or train from scratch?"
    answer: "For most apps, fine-tune a pre-trained model. Models like ResNet, EfficientNet, or a ViT pretrained on ImageNet already have strong visual features baked in, and fine-tuning on a few hundred to few thousand domain images often outperforms training from scratch and takes a fraction of the compute."
  - question: "How does a CNN actually classify an image?"
    answer: "A CNN runs the image through a stack of convolutional layers that detect progressively higher-level features (edges, textures, then object parts), pooling layers that downsample, and finally fully connected layers that map the extracted features to class probabilities. Training adjusts the filter weights via backpropagation to minimize classification error."
---

Image classification is an exciting field of computer vision that tries to understand and label images in their entirety. It has many applications: you can integrate it into your app to automatically categorize photos, filter inappropriate content from social media feeds, or even organize your online store's product catalog.

In this article, we’ll go over how the image classification field developed and its most popular architectures and datasets. Then, we’ll run you through implementing it into your Appwrite project using TensorFlow.

# A brief history of image classification

Early image classification began in the 1960s and relied on simple algorithms such as nearest neighbours and linear classifiers. During this time, some of the foundations of image classification were invented, such as the Vapnik-Chervonenkis Theory (VC Theory).

In the late 1980s, researchers explored how neural networks could be used. The introduction of back-propagation made it easier to train multi-layer perceptrons (MLPs) and showed that neural networks had the potential for classification tasks. However, a lack of large datasets to train on and insufficient processing power made real-world applications impractical.

All these efforts culminated in 2012 AlexNet won the ImageNet Large Scale Visual Recognition (ILSVRC) competition. This victory highlighted the potential of Convolutional Neural Networks (CNNs) for image classification, leading to a major shift in the field.

Following AlexNet, other notable architectures emerged, including VGGNet and GoogLeNet in 2014, ResNet in 2015, and EfficientNet in 2019. Each built on AlexNet's foundation, refining and simplifying CNN architecture, which is widely used today.

Retention mechanisms and transformers, inspired by their success in natural language processing, are new trends in image classification. These models treat image patches like text tokens. Few-shot learning and transfer learning have potential to reduce the need for large datasets, making models training more efficient.

# Architectures

Numerous architectures have been utilized for the task of image classification; here are a few notable ones:

### Convolutional Neural Networks (CNNs)
Convolutional neural networks (CNNs) use multiple layers to process data. Convolutional layers extract important features, pooling layers simplify the data and enhance feature detection, and a fully connected layer makes the final decisions.

### Transformers

The transformer architecture has completely changed the language processing field of AI. Researchers have investigated using the Transformer architecture since 2020, by converting sections of images into tokens with [Google's ViT](https://arxiv.org/pdf/2010.11929) and [Microsoft's Swin Transformer](https://arxiv.org/pdf/2103.14030). In these cases, Transformers showed yields matching or even greater results than CNNs.

Currently, Transformers are leading benchmarks on most image classification datasets, including ImageNet ([OmniVec](https://arxiv.org/pdf/2311.05709)), CIFAR-10 (ViT-H/14), and [STL-10](https://cs.stanford.edu/~acoates/stl10/) (ViT-L/16). Still, they need help with datasets containing many classes, such as CIFAR-100, which is currently topped by an [EfficientNet](https://arxiv.org/pdf/1905.11946) (CNN). Another downside to transformers is that they require significantly more data than a CNN to train.

### Capsule Networks (CapNet)

[CapNets](https://arxiv.org/pdf/1710.09829) were invented by Geoffrey Hinton to remedy CNN's issue of recognizing different orientations. Unlike CNNs, CapNets don't use max-pooling techniques, which may cause important details to be lost in an image. Instead, they utilize smaller groups of neurons called capsules, designed to detect specific objects and output a vector instead of a single float value. Because of this output, CapNets can understand relationships between objects better (Eyes appear above the nose, etc.…) to build up a more accurate classifier even when viewing images with different orientations.

# Datasets

Finding data to train these architectures used to be the most challenging part of developing state-of-the-art image classification models. Now, however, there is standardized high-quality labeled data that is offered entirely free for any researchers who require it. Next, we'll cover some of the most popular datasets.

### ImageNet

The most well-known dataset, and perhaps the oldest one still in use, [ImageNet](https://www.image-net.org/) is a collection of over 14 million labeled images built up over ten years, with over a million of those images also containing bounding box data on top of that. ImageNet is most famous for its competition, the ImageNet Large Scale Visual Recognition Challenge (ILSVRC for short), held annually from 2010 until 2017. It made waves across the industry when in 2012, AlexNet beat all other algorithms by over 10.8% using a convolutional neural network, sparking a boom in computer vision research.

### CIFAR-10/100
Released in 2009 by the Canadian Institute for Advanced Research, [CIFAR-10/100](https://www.cs.toronto.edu/~kriz/cifar.html) is now another widely used benchmark for image classification models. It is a collection of 60,000 32x32 color images, each labeled with one of 10 classes. It is a subset of the 80 Million Tiny Images subset, which was also released in 2009.

CIFAR-100 is another CIFAR dataset with 100 classes, each containing 600 images. It is also heavily used in benchmarking and training models.

### MNIST
Released by Yann LeCun and Corinna Cortes, [MNIST](https://yann.lecun.com/exdb/mnist/) is an extensive database of over 60,000 images of handwritten digits commonly used to train image classification models that power OCR technology. Each image is 28x28 pixels. MNIST's training data was taken from American Census Bureau employees, while the testing data was from American high school students.

MNIST used to be the hello-world dataset for Tensorflow's image classification tutorial; however, it has since been replaced with 3,700 pictures of flowers to demonstrate better how to use Tensorflow with color images.

# How to implement image classification into your app
To show you how you might use image classification in your application, we can create two scripts: a training script you can run on your machine and an inference script you can run as an Appwrite function.

### Step 1: Train the TensorFlow model

Install TensorFlow and NumPy installed on your machine, then paste the following code into a file called training.py; this is a modified version of the TensorFlow image classification tutorial found [here](https://www.tensorflow.org/tutorials/images/classification).

```python
import matplotlib.pyplot as plt
import numpy as np
import tensorflow as tf

from tensorflow import keras
from tensorflow.keras import layers
from tensorflow.keras.models import Sequential
import pathlib

dataset_url = "https://storage.googleapis.com/download.tensorflow.org/example_images/flower_photos.tgz"
data_dir = tf.keras.utils.get_file('flower_photos.tar', origin=dataset_url, extract=True)
data_dir = pathlib.Path(data_dir).with_suffix('')

batch_size = 32
img_height = 180
img_width = 180

train_ds = tf.keras.utils.image_dataset_from_directory(
  data_dir,
  validation_split=0.2,
  subset="training",
  seed=123,
  image_size=(img_height, img_width),
  batch_size=batch_size)

val_ds = tf.keras.utils.image_dataset_from_directory(
  data_dir,
  validation_split=0.2,
  subset="validation",
  seed=123,
  image_size=(img_height, img_width),
  batch_size=batch_size)

class_names = train_ds.class_names

AUTOTUNE = tf.data.AUTOTUNE

train_ds = train_ds.cache().shuffle(1000).prefetch(buffer_size=AUTOTUNE)
val_ds = val_ds.cache().prefetch(buffer_size=AUTOTUNE)

# Compile the model
num_classes = len(class_names)

model = Sequential([
  layers.Rescaling(1./255, input_shape=(img_height, img_width, 3)),
  layers.Conv2D(16, 3, padding='same', activation='relu'),
  layers.MaxPooling2D(),
  layers.Conv2D(32, 3, padding='same', activation='relu'),
  layers.MaxPooling2D(),
  layers.Conv2D(64, 3, padding='same', activation='relu'),
  layers.MaxPooling2D(),
  layers.Flatten(),
  layers.Dense(128, activation='relu'),
  layers.Dense(num_classes)
])

model.compile(optimizer='adam',
              loss=tf.keras.losses.SparseCategoricalCrossentropy(from_logits=True),
              metrics=['accuracy'])

model.summary()

# Train the model
epochs=10
history = model.fit(
  train_ds,
  validation_data=val_ds,
  epochs=epochs
)

converter = tf.lite.TFLiteConverter.from_keras_model(model)
tflite_model = converter.convert()

with open('model.tflite', 'wb') as f:
  f.write(tflite_model)
```

This code will download a bunch of sample images of flowers, create two groups of images for training and validation with an 80/20 split, and then build a standard convolutional neural network and train it. Finally, it will export the trained model into a TensorFlow Lite model.

### Step 2: Create a new function

Next, initialize a new function using the Appwrite CLI by using:

```bash
appwrite init project # Use if you don't already have an appwrite.config.json
appwrite init function
```

Give it any name and make sure to select the `Python (ML)` runtime; this should create a new function with the boilerplate included. Change directories into the freshly created function and open a code editor within it.

### Step 3: Add our inference code to the function

With the function boilerplate created, replace the `main.py` file with a script to load the model we just trained and run inference:

```python
import os
import json
import numpy as np
import tensorflow as tf
from PIL import Image
from io import BytesIO
from appwrite.client import Client
from appwrite.services.storage import Storage

script_dir = os.path.dirname(os.path.abspath(__file__))
TF_MODEL_FILE_PATH = os.path.join(script_dir, 'model.tflite')
img_height = 180
img_width = 180

interpreter = tf.lite.Interpreter(model_path=TF_MODEL_FILE_PATH)
interpreter.allocate_tensors()

def preprocess_image(image_bytes):
    img = Image.open(BytesIO(image_bytes))
    img = img.resize((img_width, img_height))
    img = np.array(img)
    img = img.astype('float32') / 255.0
    img = np.expand_dims(img, axis=0)
    return img

def main(context):
    if context.req.method != "POST":
        return context.res.send("Invalid Request", 400)

    client = (
        Client()
        .set_endpoint("https://<REGION>.cloud.appwrite.io/v1")
        .set_project(os.environ["APPWRITE_FUNCTION_PROJECT_ID"])
        .set_key(os.environ["APPWRITE_API_KEY"])
    )

    bucketId = os.environ["APPWRITE_BUCKET_ID"]

    storage = Storage(client)

    try:
        body = json.loads(context.req.body)
    except ValueError as err:
        return context.res.json({"ok": False, "error": err.message}, 400)

    file_id = body["$id"]
    file_bytes = storage.get_file_download(bucket_id=bucketId, file_id=file_id)
    
    # Preprocess the image
    img = preprocess_image(file_bytes)

    # Get the signature runner for the model
    classify_lite = interpreter.get_signature_runner('serving_default')
    
    # Run the image through the model
    predictions_lite = classify_lite(rescaling_1_input=img)['dense_1']
    score_lite = tf.nn.softmax(predictions_lite)

    class_names = ['daisy', 'dandelion', 'roses', 'sunflowers', 'tulips']

    response_message = "This image most likely belongs to {} with a {:.2f} percent confidence.".format(
        class_names[np.argmax(score_lite)], 100 * np.max(score_lite)
    )

    context.log(response_message)
    return context.res.send(response_message, 200)
```

Add a requirements.txt file with TensorFlow and NumPy, to fetch the required dependencies:

```text
tensorflow
appwrite
numpy
Pillow
```

### Step 4: Deploy your function

Move the `model.tflite` file that was created into the same directory as the `main.py` file and then change directories to that containing the `appwrite.config.json`. This `model.tflite` is quite a small file, so store it in the function. If using larger models, upload them to Appwrite Storage and then use `getFileDownload` to download them to the function. 

With the file now copied, run appwrite deploy function and select the function to deploy it to Appwrite.

### Step 5: Update your environment variables 

Now that the function has been deployed, navigate to it on the Appwrite console and set the `APPWRITE_API_KEY` and `APPWRITE_BUCKET_ID` environment variables to be ready for execution. Make sure to redeploy the function so these new environment variables are set.

### Step 6: Test your function

Finally, send a `POST` request to the function with a JSON body that contains a file ID from the bucket like this: 

```json
{"$id": "66503a9e00246481a92c"}
```

The classification result will be returned shortly as a response. This function can also accept a file event. To build on this function, you may want to upload the result to the database to identify images uploaded to your instance automatically.

# Conclusion
This demo should give you a good starting point for experimenting with AI and Appwrite. Try making a more advanced version or using safe tensors instead, and if you make anything cool, do not hesitate to let us know on our [Discord server](https://appwrite.io/discord). 

Check out the resources below to learn how to integrate AI into your Appwrite-powered application. We can’t wait to see what you build!

- [Appwrite AI integrations documentation](https://appwrite.io/docs/products/ai)
- [Build an intelligent chatbot with GPT-4o and Appwrite Functions](https://appwrite.io/blog/post/personal-chatbot-gpt-4o)
- [Learn how to build with Appwrite AI Function templates](https://appwrite.io/blog/post/building-with-ai-function-templates)
