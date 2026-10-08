---
layout: article
title: Type generation
description: Generate types from your Appwrite database schema. Learn how to use the Appwrite CLI to create and manage your types effectively.
---

The Appwrite CLI provides a simple way to generate types based on your Appwrite database schema. This feature is particularly useful for developers who want to ensure type safety in their applications by generating type definitions that match their database collections and attributes.

To generate types, the CLI reads the database schema from your project's `appwrite.json` file and generates type definitions for each collection.

## Generating types

First, ensure you have the [Appwrite CLI](/docs/tooling/command-line/installation#getting-started) installed and your project is [initialised](/docs/tooling/command-line/installation#initialization). Then, run the following command in your terminal to pull collections from your Appwrite project:

```bash
appwrite pull collections
```

To generate types, you can use the Appwrite CLI command:

```bash
appwrite types [options] <output-directory>
```

The following options are currently available:

| Option | Description |
|--------|-------------|
| `--language` or `-l` |  The programming language for which types can be generated. Choices include `ts`, `js`, `php`, `kotlin`, `swift`, `java`, `dart`, `auto`. The CLI will use `auto` as the default option if this option is skipped. |
| `--help` or `-h` | Displays help information for the command. |

## Example usage

Suppose you want to generate types for a collection with data on books with the following schema from your `appwrite.json` file:

```json
{
    "projectId": "682ca9a50004cf4b330f",
    "projectName": "Appwrite project",
    "databases": [
        {
            "$id": "684c678b00211ddac082",
            "name": "Library",
            "enabled": true
        }
    ],
    "collections": [
        {
            "$id": "684c6790002d457ee89d",
            "$permissions": [],
            "databaseId": "684c678b00211ddac082",
            "name": "Books",
            "enabled": true,
            "documentSecurity": false,
            "attributes": [
                {
                    "key": "name",
                    "type": "varchar",
                    "required": true,
                    "array": false,
                    "size": 255,
                    "default": null
                },
                {
                    "key": "author",
                    "type": "varchar",
                    "required": true,
                    "array": false,
                    "size": 255,
                    "default": null
                },
                {
                    "key": "release_year",
                    "type": "datetime",
                    "required": false,
                    "array": false,
                    "format": "",
                    "default": null
                },
                {
                    "key": "category",
                    "type": "varchar",
                    "required": false,
                    "array": false,
                    "elements": [
                        "fiction",
                        "nonfiction"
                    ],
                    "format": "enum",
                    "default": null
                },
                {
                    "key": "genre",
                    "type": "varchar",
                    "required": false,
                    "array": true,
                    "size": 100,
                    "default": null
                },
                {
                    "key": "is_checked_out",
                    "type": "boolean",
                    "required": true,
                    "array": false,
                    "default": null
                }
            ],
            "indexes": []
        }
    ]
}
```

Here's how you can generate types for this collection across all supported languages:

{% tabs %}
{% tabsitem #ts title="TypeScript" %}
Run the following command in your terminal:

```bash
appwrite types --language ts ./types
```

This will generate the following types in the `./types` sub-directory of your project:

```ts
import { Models } from 'appwrite';

export enum Category {
  FICTION = "fiction",
  NONFICTION = "nonfiction",
}

export type Books = Models.Document & {
  name: string;
  author: string;
  releaseYear: string | null;
  category: Category | null;
  genre: string[] | null;
  isCheckedOut: boolean;
}
```
{% /tabsitem %}

{% tabsitem #js title="JavaScript" %}
Run the following command in your terminal:

```bash
appwrite types --language js ./types
```

This will generate the following types in the `./types` sub-directory of your project:

```js
/**
 * @typedef {import('appwrite').Models.Document} Document
 */


/**
 * @typedef {Object} Books
 * @property {string} name
 * @property {string} author
 * @property {string|null|undefined} releaseYear
 * @property {"fiction"|"nonfiction"|null|undefined} category
 * @property {string[]|null|undefined} genre
 * @property {boolean} isCheckedOut
 */
```
{% /tabsitem %}

{% tabsitem #java title="Java" %}
Run the following command in your terminal:

```bash
appwrite types --language java ./types
```

This will generate the following types in the `./types` sub-directory of your project:

```java
package io.appwrite.models;

import java.util.*;
public class Books {

    public enum Category {
        fiction,
        nonfiction;
    }

    private String name;
    private String author;
    private String releaseYear;
    private Category category;
    private List<String> genre;
    private boolean isCheckedOut;

    public Books() {
    }

    public Books(
        String name,
        String author,
        String releaseYear,
        Category category,
        List<String> genre,
        boolean isCheckedOut
    ) {
        this.name = name;
        this.author = author;
        this.releaseYear = releaseYear;
        this.category = category;
        this.genre = genre;
        this.isCheckedOut = isCheckedOut;
    }

    public String getName() {
        return name;
    }

    public void setName(String name) {
        this.name = name;
    }

    public String getAuthor() {
        return author;
    }

    public void setAuthor(String author) {
        this.author = author;
    }

    public String getReleaseYear() {
        return releaseYear;
    }

    public void setReleaseYear(String releaseYear) {
        this.releaseYear = releaseYear;
    }

    public Category getCategory() {
        return category;
    }

    public void setCategory(Category category) {
        this.category = category;
    }

    public List<String> getGenre() {
        return genre;
    }

    public void setGenre(List<String> genre) {
        this.genre = genre;
    }

    public boolean getIsCheckedOut() {
        return isCheckedOut;
    }

    public void setIsCheckedOut(boolean isCheckedOut) {
        this.isCheckedOut = isCheckedOut;
    }

    @Override
    public boolean equals(Object obj) {
        if (this == obj) return true;
        if (obj == null || getClass() != obj.getClass()) return false;
        Books that = (Books) obj;
        return Objects.equals(name, that.name) &&
              Objects.equals(author, that.author) &&
              Objects.equals(releaseYear, that.releaseYear) &&
              Objects.equals(category, that.category) &&
              Objects.equals(genre, that.genre) &&
              Objects.equals(isCheckedOut, that.isCheckedOut);
    }

    @Override
    public int hashCode() {
        return Objects.hash(name, author, releaseYear, category, genre, isCheckedOut);
    }

    @Override
    public String toString() {
        return "Books{" +
                "name=" + name +
                "author=" + author +
                "releaseYear=" + releaseYear +
                "category=" + category +
                "genre=" + genre +
                "isCheckedOut=" + isCheckedOut +
                '}';
    }
}
```
{% /tabsitem %}

{% tabsitem #php title="PHP" %}
Run the following command in your terminal:

```bash
appwrite types --language php ./types
```

This will generate the following types in the `./types` sub-directory of your project:

```php
<?php
namespace Appwrite\Models;

enum Category: string {
  case FICTION = 'fiction';
  case NONFICTION = 'nonfiction';
}

class Books {
  private string $name;
  private string $author;
  private string|null $releaseYear;
  private Category|null $category;
  private array $genre;
  private bool $isCheckedOut;

  public function __construct(
    string $name,
    string $author,
    ?string $releaseYear = null,
    ?Category $category = null,
    ?array $genre = null,
    bool $isCheckedOut
  ) {
    $this->name = $name;
    $this->author = $author;
    $this->releaseYear = $releaseYear;
    $this->category = $category;
    $this->genre = $genre;
    $this->isCheckedOut = $isCheckedOut;
  }

  public function getName(): string {
    return $this->name;
  }

  public function setName(string $name): void {
    $this->name = $name;
  }
  public function getAuthor(): string {
    return $this->author;
  }

  public function setAuthor(string $author): void {
    $this->author = $author;
  }
  public function getReleaseYear(): string|null {
    return $this->releaseYear;
  }

  public function setReleaseYear(string|null $releaseYear): void {
    $this->releaseYear = $releaseYear;
  }
  public function getCategory(): Category|null {
    return $this->category;
  }

  public function setCategory(Category|null $category): void {
    $this->category = $category;
  }
  public function getGenre(): array {
    return $this->genre;
  }

  public function setGenre(array $genre): void {
    $this->genre = $genre;
  }
  public function getIsCheckedOut(): bool {
    return $this->isCheckedOut;
  }

  public function setIsCheckedOut(bool $isCheckedOut): void {
    $this->isCheckedOut = $isCheckedOut;
  }
}
```
{% /tabsitem %}

{% tabsitem #dart title="Dart" %}
Run the following command in your terminal:

```bash
appwrite types --language dart ./types
```

This will generate the following types in the `./types` sub-directory of your project:

```dart
enum Category {
  fiction,
  nonfiction,
}

class Books {
  String name;
  String author;
  String? releaseYear;
  Category? category;
  List<String>? genre;
  bool isCheckedOut;

  Books({
    required this.name,
    required this.author,
    this.releaseYear,
    this.category,
    this.genre,
    required this.isCheckedOut,
  });

  factory Books.fromMap(Map<String, dynamic> map) {
    return Books(
      name: map['name'].toString(),
      author: map['author'].toString(),
      releaseYear: map['release_year']?.toString() ?? null,
      category: map['category'] != null ? Category.values.where((e) => e.name == map['category']).firstOrNull : null,
      genre: List<String>.from(map['genre'] ?? []) ?? [],
      isCheckedOut: map['is_checked_out'],
    );
  }

  Map<String, dynamic> toMap() {
    return {
      "name": name,
      "author": author,
      "release_year": releaseYear,
      "category": category?.name ?? null,
      "genre": genre,
      "is_checked_out": isCheckedOut,
    };
  }
}
```
{% /tabsitem %}

{% tabsitem #kotlin title="Kotlin" %}
Run the following command in your terminal:

```bash
appwrite types --language kotlin ./types
```

This will generate the following types in the `./types` sub-directory of your project:

```kotlin
package io.appwrite.models

enum class Category {
    fiction,
    nonfiction
}

data class Books(
    val name: String,
    val author: String,
    val releaseYear: String?,
    val category: Category?,
    val genre: List<String>?,
    val isCheckedOut: Boolean,
)
```
{% /tabsitem %}

{% tabsitem #swift title="Swift" %}
Run the following command in your terminal:

```bash
appwrite types --language swift ./types
```

This will generate the following types in the `./types` sub-directory of your project:

```swift
import Foundation

public enum Category: String, Codable, CaseIterable {
  case fiction = "fiction"
  case nonfiction = "nonfiction"
}

public class Books: Codable {
    public let name: String
    public let author: String
    public let releaseYear: String?
    public let category: Category?
    public let genre: [String]?
    public let isCheckedOut: Bool

    enum CodingKeys: String, CodingKey {
        case name = "name"
        case author = "author"
        case releaseYear = "release_year"
        case category = "category"
        case genre = "genre"
        case isCheckedOut = "is_checked_out"
    }

    init(
        name: String,
        author: String,
        releaseYear: String?,
        category: Category?,
        genre: [String]?,
        isCheckedOut: Bool
    ) {
        self.name = name
        self.author = author
        self.releaseYear = releaseYear
        self.category = category
        self.genre = genre
        self.isCheckedOut = isCheckedOut
    }

    public required init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)

        self.name = try container.decode(String.self, forKey: .name)
        self.author = try container.decode(String.self, forKey: .author)
        self.releaseYear = try container.decodeIfPresent(String.self, forKey: .releaseYear)
        self.category = try container.decodeIfPresent(Category.self, forKey: .category)
        self.genre = try container.decodeIfPresent([String].self, forKey: .genre)
        self.isCheckedOut = try container.decode(Bool.self, forKey: .isCheckedOut)
    }

    public func encode(to encoder: Encoder) throws {
        var container = encoder.container(keyedBy: CodingKeys.self)

        try container.encode(name, forKey: .name)
        try container.encode(author, forKey: .author)
        try container.encodeIfPresent(releaseYear, forKey: .releaseYear)
        try container.encodeIfPresent(category, forKey: .category)
        try container.encodeIfPresent(genre, forKey: .genre)
        try container.encode(isCheckedOut, forKey: .isCheckedOut)
    }

    public func toMap() -> [String: Any] {
        return [
            "name": name as Any,
            "author": author as Any,
            "release_year": releaseYear as Any,
            "category": category as Any,
            "genre": genre as Any,
            "is_checked_out": isCheckedOut as Any
        ]
    }

    public static func from(map: [String: Any]) -> Books {
        return Books(
            name: map["name"] as! String,
            author: map["author"] as! String,
            releaseYear: map["release_year"] as? String,
            category: map["category"] as? String,
            genre: map["genre"] as? [String],
            isCheckedOut: map["is_checked_out"] as! Bool
        )
    }
}
```
{% /tabsitem %}
{% /tabs %}