Utopia Agents talks to AI providers. An `Agent` wraps a provider adapter (OpenAI, Anthropic, Gemini, Deepseek, OpenRouter, Perplexity, xAI, or the Ollama and Appwrite embedding services) with a description and instructions; a `Conversation` sends it messages and keeps the answers. This guide covers the common tasks. Its examples answer from a canned client instead of a provider, the way tests do: pass a client to any adapter with `with_client` (PHP: the constructor's `$client`).

## Ask an agent a question

Give the agent a description and instructions, add the user's message to a conversation and send it. The answer is added to the conversation as the assistant's message, and the tokens the provider reported are counted.

```
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

# tokio::runtime::Builder::new_current_thread().enable_all().build().unwrap().block_on(async {
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
# Ok::<(), utopia_agents::Error>(())
# }).unwrap();
```

```php
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Agents\Adapters\OpenAI;
use Utopia\Agents\Agent;
use Utopia\Agents\Conversation;
use Utopia\Agents\Message;
use Utopia\Agents\Roles\User;
use Utopia\Client\Psr18\StreamingClientInterface;
use Utopia\Psr7\Response;

/** Answers every streamed request with the same server-sent events. */
final class Canned implements ClientInterface, StreamingClientInterface
{
    public function __construct(private string $events) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return new Response(200);
    }

    public function stream(RequestInterface $request, callable $sink): ResponseInterface
    {
        $sink($this->events);

        return new Response(200);
    }
}

$stream = "data: {\"choices\":[{\"delta\":{\"content\":\"Appwrite is an open-source backend.\"}}]}\n\ndata: [DONE]\n\n";
$adapter = new OpenAI('sk-test', client: new Canned($stream));

$agent = new Agent($adapter);
$agent->setDescription('You answer questions about Appwrite.')->addInstruction('Tone', 'Be brief.');

$conversation = new Conversation($agent);
$conversation->message(new User('user-1', 'Ada'), new Message('What is Appwrite?'));
$answer = $conversation->send();

echo $answer->getContent(), "\n";                    // Appwrite is an open-source backend.
echo count($conversation->getMessages()), "\n";      // 2
echo $conversation->getMessages()[1]->getRole(), "\n"; // assistant
```

## Stream the answer as it arrives

A listener gets each token of a streamed answer as it is parsed, however the response is cut into chunks on the way.

```
use std::sync::{Arc, Mutex};
use utopia_agents::{Agent, BoxFuture, Conversation, Message, Anthropic, Transport, User};
use utopia_client::{Request, Response};

# struct Chunks(Vec<&'static str>);
# impl Transport for Chunks {
#     fn send(&self, _r: Request) -> BoxFuture<'_, Result<Response, utopia_client::Error>> {
#         Box::pin(async { Response::new(200) })
#     }
#     fn stream<'a>(&'a self, _r: Request, sink: &'a mut (dyn FnMut(&[u8]) + Send)) -> BoxFuture<'a, Result<Response, utopia_client::Error>> {
#         Box::pin(async move { for c in &self.0 { sink(c.as_bytes()); } Response::new(200) })
#     }
# }
# tokio::runtime::Builder::new_current_thread().enable_all().build().unwrap().block_on(async {
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
# Ok::<(), utopia_agents::Error>(())
# }).unwrap();
```

```php
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Agents\Adapters\Anthropic;
use Utopia\Agents\Agent;
use Utopia\Agents\Conversation;
use Utopia\Agents\Message;
use Utopia\Agents\Roles\User;
use Utopia\Client\Psr18\StreamingClientInterface;
use Utopia\Psr7\Response;

final class Chunks implements ClientInterface, StreamingClientInterface
{
    public function __construct(private array $chunks) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return new Response(200);
    }

    public function stream(RequestInterface $request, callable $sink): ResponseInterface
    {
        foreach ($this->chunks as $chunk) {
            $sink($chunk);
        }

        return new Response(200);
    }
}

$client = new Chunks([
    'data: {"type":"content_block_delta","delta":{"type":"text_',
    "delta\",\"text\":\"Hello\"}}\ndata: {\"type\":\"content_block_delta\",",
    "\"delta\":{\"type\":\"text_delta\",\"text\":\" there\"}}\n",
]);
$conversation = new Conversation(new Agent(new Anthropic('sk-test', client: $client)));

$tokens = [];
$conversation->listen(function (string $token) use (&$tokens) {
    $tokens[] = $token;
});
$conversation->message(new User('user-1'), new Message('Hi'));
$conversation->send();

echo json_encode($tokens), "\n"; // ["Hello"," there"]
```

## Ask for structured output

A schema makes the agent answer with a JSON object of the given properties. Adapters without structured output refuse it.

```
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
# Ok::<(), utopia_agents::Error>(())
```

```php
use Utopia\Agents\Adapters\OpenAI;
use Utopia\Agents\Adapters\XAI;
use Utopia\Agents\Agent;
use Utopia\Agents\Schema;
use Utopia\Agents\Schema\SchemaObject;

$object = new SchemaObject();
$object->addProperty('location', ['type' => 'string', 'description' => 'The city and state']);
$object->addProperty('unit', ['type' => 'string', 'description' => 'celsius or fahrenheit']);
$schema = new Schema('get_weather', 'The weather in a city', $object, ['location', 'unit']);

$agent = new Agent(new OpenAI('sk-test'));
$agent->setSchema($schema);
echo $agent->getSchema()->getName(), "\n"; // get_weather

try {
    (new Agent(new XAI('sk-test')))->setSchema($schema);
} catch (\Exception $error) {
    echo $error->getMessage(), "\n"; // Schema is not supported for this model
}
```

## Attach an image

Attachments are messages whose content is the file. The adapter's limits are checked as the message is added: OpenAI, Anthropic, Gemini and Deepseek take up to ten PNG, JPEG, WebP or GIF images of 5 MB each, 20 MB in all.

```
use utopia_agents::{Agent, Conversation, Gemini, Message, User};

let png = b"\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0".to_vec();
let mut conversation = Conversation::new(Agent::new(Gemini::new("test-api-key")));
let user = User::new("user-1", "");

conversation.message(&user, Message::new("What is in this image?"), vec![Message::new(png)])?;
assert_eq!(conversation.messages()[0].attachments()[0].mime_type(), Some("image/png"));

let refused = conversation.message(&user, Message::new("And this?"), vec![Message::new("%PDF-1.4 ...")]);
assert_eq!(refused.err().unwrap().to_string(), "Attachment MIME type is not allowed");
# Ok::<(), utopia_agents::Error>(())
```

```php
use Utopia\Agents\Adapters\Gemini;
use Utopia\Agents\Agent;
use Utopia\Agents\Conversation;
use Utopia\Agents\Message;
use Utopia\Agents\Roles\User;

$png = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0";
$conversation = new Conversation(new Agent(new Gemini('test-api-key')));
$user = new User('user-1');

$conversation->message($user, new Message('What is in this image?'), [new Message($png)]);
echo $conversation->getMessages()[0]->getAttachments()[0]->getMimeType(), "\n"; // image/png

try {
    $conversation->message($user, new Message('And this?'), [new Message('%PDF-1.4 ...')]);
} catch (\InvalidArgumentException $error) {
    echo $error->getMessage(), "\n"; // Attachment MIME type is not allowed
}
```

## Embed texts

Embedding adapters turn text into vectors. Ollama embeds one text per request; Appwrite's embedding service takes several at once.

```
use std::sync::Arc;
use utopia_agents::{Adapter, Agent, BoxFuture, Ollama, Transport};
use utopia_client::{Request, Response};

# struct Json(&'static str);
# impl Transport for Json {
#     fn send(&self, _r: Request) -> BoxFuture<'_, Result<Response, utopia_client::Error>> {
#         Box::pin(async move { Ok(Response::new(200)?.with_body(self.0)) })
#     }
#     fn stream<'a>(&'a self, _r: Request, _s: &'a mut (dyn FnMut(&[u8]) + Send)) -> BoxFuture<'a, Result<Response, utopia_client::Error>> {
#         Box::pin(async { Response::new(200) })
#     }
# }
# tokio::runtime::Builder::new_current_thread().enable_all().build().unwrap().block_on(async {
let body = "{\"embeddings\":[[0.25,-0.5,0.125]],\"prompt_eval_count\":4,\"total_duration\":81000}";
let mut agent = Agent::new(Ollama::new().with_client(Arc::new(Json(body))));

let result = agent.embed("Hello world").await?;
assert_eq!(result.embedding.len(), 3);
assert_eq!(result.tokens_processed, Some(4));
assert_eq!(agent.adapter().embedding_dimension()?, 768);
# Ok::<(), utopia_agents::Error>(())
# }).unwrap();
```

```php
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Utopia\Agents\Adapters\Ollama;
use Utopia\Agents\Agent;
use Utopia\Client\Psr18\StreamingClientInterface;
use Utopia\Psr7\Response;
use Utopia\Psr7\Stream;

final class Json implements ClientInterface, StreamingClientInterface
{
    public function __construct(private string $body) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        return new Response(200, body: new Stream($this->body));
    }

    public function stream(RequestInterface $request, callable $sink): ResponseInterface
    {
        return new Response(200);
    }
}

$body = '{"embeddings":[[0.25,-0.5,0.125]],"prompt_eval_count":4,"total_duration":81000}';
$agent = new Agent(new Ollama(client: new Json($body)));

$result = $agent->embed('Hello world');
echo count($result['embedding']), ' ', $result['tokensProcessed'], "\n"; // 3 4
echo $agent->getAdapter()->getEmbeddingDimension(), "\n";               // 768
```

## Ask about a change between two versions

`DiffCheck` copies two directories (or clones two Git URLs), compares them with `git diff`, and asks the agent about the difference only when there is one. Placeholders in the prompt receive the diff, the two sources and the diff's size.

```no_run
use utopia_agents::{DiffCheck, OpenAi};
use utopia_agents::diff_check::{Options, Runner};

# async fn run() -> Result<(), utopia_agents::Error> {
let mut adapter = OpenAi::new("sk-...");
let mut options = Options::default();
options.set_exclude_paths(&[".github", "docs/**"]).set_max_diff_lines(300)?;

let outcome = DiffCheck::new()
    .run(Runner::Adapter(&mut adapter), "/srv/sdk-1.0", "/srv/sdk-1.1", "Summarize ({{diff_stats}}):\n{{diff}}", &options)
    .await?;
if outcome.has_changes {
    println!("{}", String::from_utf8_lossy(&outcome.response));
}
# Ok(())
# }
```

```php
use Utopia\Agents\Adapters\OpenAI;
use Utopia\Agents\DiffCheck\DiffCheck;
use Utopia\Agents\DiffCheck\Options;

$options = (new Options())->setExcludePaths(['.github', 'docs/**'])->setMaxDiffLines(300);
echo $options->getMaxDiffLines(), "\n"; // 300

// $result = (new DiffCheck())->run(new OpenAI('sk-...'), '/srv/sdk-1.0', '/srv/sdk-1.1', "Summarize ({{diff_stats}}):\n{{diff}}", $options);
// if ($result['hasChanges']) { echo $result['response'], "\n"; }
```
