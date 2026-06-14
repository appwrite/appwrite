---
layout: post
title: Sound null-safety for your Dart functions
description: Learn to reduce null related runtime errors with Dart 3.
date: 2024-03-01
cover: /images/blog/sound-null-safety-for-your-dart-functions/cover.avif
timeToRead: 12
author: vincent-ge
category: product
featured: false
faqs:
  - question: "What is sound null safety in Dart?"
    answer: "Sound null safety means the compiler tracks whether a value can be null, and forces you to handle the null case at compile time. A type like `String` cannot hold null, while `String?` explicitly can. This catches an entire class of runtime null errors before your code ships."
  - question: "Why does null safety matter for Appwrite Functions?"
    answer: "Functions sit at the boundary between your code and external input (user-submitted JSON, third-party API responses, webhook payloads). You do not control the shape of that data, so null safety surfaces missing or malformed fields as compile-time errors instead of mysterious runtime crashes inside [Appwrite Functions](/docs/products/functions)."
  - question: "How do I model a JSON payload that may have missing fields?"
    answer: "Mark every field that is not guaranteed to be present as nullable in your Dart model (`String?` instead of `String`), and write `fromJson` factories that explicitly check for null before reading nested objects. The compiler will then refuse to let you ignore the missing case downstream."
  - question: "Should I use `!` to bypass null checks?"
    answer: "Only when you are certain the value cannot be null at that point, for example right after an explicit null check or for a value the runtime guarantees. Reaching for `!` to silence the analyzer defeats the purpose of null safety and replaces a compile-time error with a runtime one."
  - question: "Which Dart runtimes does Appwrite support?"
    answer: "[Appwrite Functions](/docs/products/functions) supports Dart 3.x runtimes (and newer). Sound null safety is enabled by default in Dart 3, so any function you write or migrate today benefits from it without extra configuration."
  - question: "How do I handle null gracefully in user-facing errors?"
    answer: "Validate the incoming payload at the top of your function, and return a structured error (with status code and message) if required fields are missing. The caller gets actionable feedback instead of a generic 500, and your business logic only runs with data shapes it knows how to handle."
---

Dart 3 runtimes are now available on Appwrite Cloud. Among the many cool features introduced in Dart 3, sound null safety stands out as an important change for Appwrite Functions.

Sound null safety means you can expect to know when a variable is not null. More importantly, it helps you make sure you properly handle potential null values by warning you at compile time.

When you build with Appwrite Functions, you’ll often build integrations that interface with external services and user input directly. In these cases, you have no control over what’s sent over the request.

This helps prevent all types of errors at run time, which can be difficult to catch and debug otherwise.

```dart
The getter 'age' was called on null.
Receiver: null
Tried calling: type
```

# The setup

Let’s take an example function that takes a JSON object that’s expected in the request to document which people showed up at an event when they came, and when they left.

The JSON would look like this under ideal situations.

```json
{
    "profiles": [
        {
            "name": "John",
            "age": "25",
            "stay": {
                "arrivedAt": "2022-01-01T00:00:00Z",
                "leftAt": "2022-01-01T02:00:00Z"
            }
        },
        ... more profiles
    ],
    "count": 15
}
```

# The naive implementation

```dart
import 'dart:async';

class Stay {
  final String arrivedAt;
  final String leftAt;

  Stay({this.arrivedAt, this.leftAt});

  factory Stay.fromJson(Map<String, dynamic> json) {
    if (json == null) {
      return null;
    }
    return Stay(
      arrivedAt: json['arrivedAt'],
      leftAt: json['leftAt'],
    );
  }
}

class Profile {
  final String name;
  final String age;
  final Stay stay;

  Profile({this.name, this.age, this.stay});

  factory Profile.fromJson(Map<String, dynamic> json) {
    if (json == null) {
      return null;
    }
    return Profile(
      name: json['name'],
      age: json['age'],
      stay: Stay.fromJson(json['stay']),
    );
  }
}

// This is your Appwrite function
// It's executed each time we get a request
Future<dynamic> main(final context) async {
  context.log("Here are the people that showed up!");
  var people = context.req.body; // This is JSON parsed as a map
  List<Profile> profiles = (people['profiles'] as List)
      .map((profile) => Profile.fromJson(profile))
      .toList();

  for (var profile in profiles) {
    context.log("Name: ${profile.name}");
    context.log("Age: ${profile.age}");
    context.log("Arrived at: ${profile.stay.arrivedAt}");
    context.log("Left at: ${profile.stay.leftAt}");
  }

  context.res.empty();
}
```

In Dart 2, without null safety, this code will happily compile and run. Well, until your API receives an incomplete JSON like this:

```dart
{
"profiles": [
    {
        "name": "Bob",
        "age": "35"
    }
    {
        "name": "Bob",
        "age": "35",
        "stay": {
            "arrivedAt": "2022-01-01T02:00:00Z",
            "leftAt": "2022-01-01T04:00:00Z"
        }
    },
    {}
  ],
  "count": 3
}
```

This will give you an unexpected runtime error. If you’re building integrations or new APIs with Appwrite functions, you’d want to gracefully handle the null values instead of seeing this error in your logs.

```dart
The getter 'age' was called on null.
Receiver: null
Tried calling: age
```

# Handling nulls in Dart 3

If you tried to follow along in Dart 3, you’ll find the compiler screaming at us to handle null values.

```dart
compiling...
../build/lib/main.dart:8:14: Error: The parameter 'arrivedAt' can't have a value of 'null' because of its type 'String', but the implicit default value is 'null'.
Try adding either an explicit non-'null' default value or the 'required' modifier.
  Stay({this.arrivedAt, this.leftAt});
             ^^^^^^^^^

... And more errors for every single non-null safe access!
```

While this is annoying, forcing you to explicitly handle nulls at compile times make sure you get no surprises at runtime.

Let’s add some null handling to our function. First, lets look at the Profile class.

```dart
class Profile {
  final String name; // Can't be null
  final String age;  // Can't be null
  final Stay ?stay;  // Can sometimes be null

	// You must provide name and age, age might not be provided
  Profile({required this.name, required this.age, this.stay});

  factory Profile.fromJson(Map<String, dynamic> json) {
    return Profile(
      name: json?['name'] ?? 'No name provided',
      age: json?['age'] ?? 'No age provided',
      stay: json?['stay'] != null ? Stay.fromJson(json['stay']) : null,
    );
  }
}
```

Notice that `final String name` and `final String age` are declared as class variables that cannot be null, but `final Stay ?stay`  is explicitly stated to be nullable. This is why the constructor of `Profile({required this.name, required this.age, this.stay})` specifies that name and age are required. If you try to pass null, or a nullable value into these required params without providing non-null defaults, the compiler will complain.

When constructing a `Profile` class from a JSON, we also did some null handling. We access members using `json?[]` syntax, which checks if `json` is null before attempting access, preventing null pointer exceptions. Since stay is nullable, we check if stay exists in the `json` passed in, and if not, we default to null. Later when accessing member `stay`, we’ll be reminded to handle this properly.

In `Stay` there are similar concepts applied.

```dart
class Stay {
  final String? arrivedAt;
  final String? leftAt;

  Stay({this.arrivedAt, this.leftAt});

  factory Stay.fromJson(Map<String, dynamic>? json) {
    return Stay(
      arrivedAt: json?['arrivedAt'],
      leftAt: json?['leftAt'],
    );
  }
}
```

Declaring `arrivedAt` and `leftAt` as optional forces us to explicitly check to null safety during access.

```dart
context.log("Name: ${profile.name}");
context.log("Age: ${profile.age}");
context.log("Arrived at: ${profile.stay?.arrivedAt}");
context.log("Left at: ${profile.stay?.leftAt}");
```

Notice how we can safely access `name` and `age`, but we must check if stay is null. If we decide to access `arrivedAt` or `leftAt` to split the date string, for example, we’ll also be warned to handle null values here since these are also nullable.

```dart
context.log("Arrived at: ${profile.stay?.arrivedAt?.split('T')[0] ?? 'unknown'}");
context.log("Left at: ${profile.stay?.leftAt?.split('T')[0] ?? 'unknown'}");
```

# The new function

Here’s the new Appwrite with all the null handling in place.

```dart
import 'dart:async';

class Stay {
  final String? arrivedAt;
  final String? leftAt;

  Stay({this.arrivedAt, this.leftAt});

  factory Stay.fromJson(Map<String, dynamic>? json) {
    return Stay(
      arrivedAt: json?['arrivedAt'],
      leftAt: json?['leftAt'],
    );
  }
}

class Profile {
  final String name;
  final String age;
  final Stay ?stay;

  Profile({required this.name, required this.age, this.stay});

  factory Profile.fromJson(Map<String, dynamic> json) {
    return Profile(
      name: json?['name'] ?? 'No name provided',
      age: json?['age'] ?? 'No age provided',
      stay: json?['stay'] != null ? Stay.fromJson(json['stay']) : null,
    );
  }
}

// This is your Appwrite function
// It's executed each time we get a request
Future<dynamic> main(final context) async {
  context.log("Here are the people that showed up!");
  var people = context.req.body; // This is JSON parsed as a map
  List<Profile> profiles = (people['profiles'] as List)
      .map((profile) => Profile.fromJson(profile))
      .toList();

  for (var profile in profiles) {
    context.log("Name: ${profile.name}");
    context.log("Age: ${profile.age}");
    context.log("Arrived at: ${profile.stay?.arrivedAt?.split('T')[0] ?? 'unknown'}");
    context.log("Left at: ${profile.stay?.leftAt?.split('T')[0] ?? 'unknown'}");
  }
  context.res.empty();
}
```

Which will gracefully handle even malformed JSONs like the one we showed above and still output to the best of its ability.

```dart
Here are the people that showed up!
Name: Jane
Age: 30
Arrived at: unknown
Left at: unknown
Name: Bob
Age: 35
Arrived at: 2022-01-01
Left at: 2022-01-01
```

# Wrapping up

Dart 3 with sound null-safety will save you from hours of debugging when tracking down null-value related errors at runtime. Forcing you to be conscious of handling null values brings these null-value caused errors from runtime to compile time. Having compiler errors is always a better time than errors in your precious production functions.

If you’re still on Dart 2.x, I strongly recommend you migrate to Dart 3 to benefit from safety. Dart’s documentation has a [migration guide](https://dart.dev/null-safety#migrate) to help you simply the process.

# Resources

Visit our documentation to learn more about Dart, join us on Discord to be part of the discussion, view our blog and YouTube channel, or visit our GitHub repository to see our open-source code.

- [Docs](/docs/products/functions/runtimes)
- [Discord](https://appwrite.io/discord)
- [Blog](/blog)
- [YouTube](https://www.youtube.com/channel/UCtBJ1v69gm8NgbCju_03Fiw)
- [GitHub](https://github.com/appwrite/appwrite)
