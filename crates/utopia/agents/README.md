# Utopia Agents

> [!IMPORTANT]
> This repository is a read-only mirror of [`crates/utopia/agents`](https://github.com/appwrite/appwrite/tree/main/crates/utopia/agents) in [appwrite/appwrite](https://github.com/appwrite/appwrite). Development happens there — please open issues and pull requests against appwrite/appwrite.

[![Discord](https://img.shields.io/discord/564160730845151244?label=discord)](https://discord.gg/GSeTUeA)

AI agents on LLM providers: conversations, messages with image attachments, structured output schemas, streamed responses, token accounting, embeddings, and diff checks.

This library is maintained by the [Appwrite team](https://appwrite.io).

## Getting started

```toml
[dependencies]
utopia-agents = { git = "https://github.com/utopia-rs/agents" }
```

Utopia Agents talks to AI providers. An `Agent` wraps a provider adapter (OpenAI, Anthropic, Gemini, Deepseek, OpenRouter, Perplexity, xAI, or the Ollama and Appwrite embedding services) with a description and instructions; a `Conversation` sends it messages and keeps the answers. This guide covers the common tasks. Its examples answer from a canned client instead of a provider, the way tests do: pass a client to any adapter with `with_client` (PHP: the constructor's `$client`).

## Ask an agent a question

Give the agent a description and instructions, add the user's message to a conversation and send it. The answer is added to the conversation as the assistant's message, and the tokens the provider reported are counted.

```rust
use std::sync::Arc;
use utopia_agents::{Agent, BoxFuture, Conversation, Message, OpenAi, Transport, User};
use utopia_client::{Request, Response};

/// Answers every streamed request with the same server-sent events.
struct Canned(&'static str);

impl Transport for Canned {
    fn send(&self, _request: Request) -> BoxFuture<'_, Result<Response, utopia_client::Error>> {
        Box::pin(async { Response::new(200) })
    }

    fn stream<'a>(
        &'a self,
        _request: Request,
        sink: &'a mut (dyn FnMut(&[u8]) + Send),
    ) -> BoxFuture<'a, Result<Response, utopia_client::Error>> {
        Box::pin(async move {
            sink(self.0.as_bytes());
            Response::new(200)
        })
    }
}

let stream = "data: {\"choices\":[{\"delta\":{\"content\":\"Appwrite is an open-source backend.\"}}]}\n\ndata: [DONE]\n\n";
let adapter = OpenAi::new("sk-test").with_client(Arc::new(Canned(stream)));

let mut agent = Agent::new(adapter);
agent.set_description("You answer questions about Appwrite.").add_instruction("Tone", "Be brief.");

let mut conversation = Conversation::new(agent);
conversation.message(&User::new("user-1", "Ada"), Message::new("What is Appwrite?"), Vec::new())?;
let answer = conversation.send().await?;

assert_eq!(answer.text(), "Appwrite is an open-source backend.");
assert_eq!(conversation.messages().len(), 2);
assert_eq!(conversation.messages()[1].role(), "assistant");
```

## Stream the answer as it arrives

A listener gets each token of a streamed answer as it is parsed, however the response is cut into chunks on the way.

```rust
use std::sync::{Arc, Mutex};
use utopia_agents::{Agent, BoxFuture, Conversation, Message, Anthropic, Transport, User};
use utopia_client::{Request, Response};

// Two text deltas, split mid-event across three chunks.
let client = Arc::new(Chunks(vec![
    "data: {\"type\":\"content_block_delta\",\"delta\":{\"type\":\"text_",
    "delta\",\"text\":\"Hello\"}}\ndata: {\"type\":\"content_block_delta\",",
    "\"delta\":{\"type\":\"text_delta\",\"text\":\" there\"}}\n",
]));
let mut conversation = Conversation::new(Agent::new(Anthropic::new("sk-test").with_client(client)));

let tokens = Arc::new(Mutex::new(Vec::new()));
let seen = Arc::clone(&tokens);
conversation.listen(move |token: &str| seen.lock().unwrap().push(token.to_owned()));
conversation.message(&User::new("user-1", ""), Message::new("Hi"), Vec::new())?;
conversation.send().await?;

assert_eq!(*tokens.lock().unwrap(), ["Hello", " there"]);
```

## Ask for structured output

A schema makes the agent answer with a JSON object of the given properties. Adapters without structured output refuse it.

```rust
use utopia_agents::{Agent, OpenAi, Schema, SchemaObject, Xai, property};

let mut object = SchemaObject::default();
object.add_property("location", property("string", "The city and state"))?;
object.add_property("unit", property("string", "celsius or fahrenheit"))?;
let schema = Schema::new("get_weather", "The weather in a city", object, vec!["location".into(), "unit".into()]);

let mut agent = Agent::new(OpenAi::new("sk-test"));
agent.set_schema(schema.clone())?;
assert_eq!(agent.schema().map(Schema::name), Some("get_weather"));

let error = Agent::new(Xai::new("sk-test")).set_schema(schema).err().unwrap();
assert_eq!(error.to_string(), "Schema is not supported for this model");
```

## Attach an image

Attachments are messages whose content is the file. The adapter's limits are checked as the message is added: OpenAI, Anthropic, Gemini and Deepseek take up to ten PNG, JPEG, WebP or GIF images of 5 MB each, 20 MB in all.

```rust
use utopia_agents::{Agent, Conversation, Gemini, Message, User};

let png = b"\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0".to_vec();
let mut conversation = Conversation::new(Agent::new(Gemini::new("test-api-key")));
let user = User::new("user-1", "");

conversation.message(&user, Message::new("What is in this image?"), vec![Message::new(png)])?;
assert_eq!(conversation.messages()[0].attachments()[0].mime_type(), Some("image/png"));

let refused = conversation.message(&user, Message::new("And this?"), vec![Message::new("%PDF-1.4 ...")]);
assert_eq!(refused.err().unwrap().to_string(), "Attachment MIME type is not allowed");
```

## Embed texts

Embedding adapters turn text into vectors. Ollama embeds one text per request; Appwrite's embedding service takes several at once.

```rust
use std::sync::Arc;
use utopia_agents::{Adapter, Agent, BoxFuture, Ollama, Transport};
use utopia_client::{Request, Response};

let body = "{\"embeddings\":[[0.25,-0.5,0.125]],\"prompt_eval_count\":4,\"total_duration\":81000}";
let mut agent = Agent::new(Ollama::new().with_client(Arc::new(Json(body))));

let result = agent.embed("Hello world").await?;
assert_eq!(result.embedding.len(), 3);
assert_eq!(result.tokens_processed, Some(4));
assert_eq!(agent.adapter().embedding_dimension()?, 768);
```

## Ask about a change between two versions

`DiffCheck` copies two directories (or clones two Git URLs), compares them with `git diff`, and asks the agent about the difference only when there is one. Placeholders in the prompt receive the diff, the two sources and the diff's size.

```rust
use utopia_agents::{DiffCheck, OpenAi};
use utopia_agents::diff_check::{Options, Runner};

let mut adapter = OpenAi::new("sk-...");
let mut options = Options::default();
options.set_exclude_paths(&[".github", "docs/**"]).set_max_diff_lines(300)?;

let outcome = DiffCheck::new()
    .run(Runner::Adapter(&mut adapter), "/srv/sdk-1.0", "/srv/sdk-1.1", "Summarize ({{diff_stats}}):\n{{diff}}", &options)
    .await?;
if outcome.has_changes {
    println!("{}", String::from_utf8_lossy(&outcome.response));
}
```

## License

BSD-3-Clause. See [LICENSE](LICENSE).
