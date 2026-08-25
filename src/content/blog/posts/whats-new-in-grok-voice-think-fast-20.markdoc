---
layout: post
title: "Grok Voice Think Fast 2.0: Benchmarks, pricing, specs"
description: Grok Voice Think Fast 2.0 hits 0.70s time to first audio, 82.9% speech-to-speech quality, and $0.08 per minute. See the benchmarks, pricing, and migration path.
date: 2026-07-31
cover: /images/blog/whats-new-in-grok-voice-think-fast-20/cover.avif
timeToRead: 7
author: aishwari
category: ai
featured: false
faqs:
  - question: "What is Grok Voice Think Fast 2.0?"
    answer: "Grok Voice Think Fast 2.0 is SpaceXAI's next-generation speech-to-speech voice model. It reasons in parallel with speech, scores 82.9% on the Artificial Analysis Speech-to-Speech Quality Index, reaches 0.70s time to first audio, and is priced at $0.08 per minute of audio."
  - question: "How much does Grok Voice Think Fast 2.0 cost?"
    answer: "Grok Voice Think Fast 2.0 is priced at $0.08 per minute of audio, billed per minute rather than per token for predictable voice pricing."
  - question: "How do I migrate to Grok Voice Think Fast 2.0?"
    answer: "No action is needed. On August 5, 2026, the grok-voice-latest alias moves from grok-voice-think-fast-1.0 to grok-voice-think-fast-2.0. To stay on 1.0, pin grok-voice-think-fast-1.0 before that date."
  - question: "Is Grok Voice Think Fast 2.0 good for transcription?"
    answer: "Yes. Across thousands of short phrases in 24 languages it shows a 1.5 to 2.0x lower word error rate than Deepgram Nova 3 and ElevenLabs Scribe v2, and the gap widens to roughly 10x in noisy, telephony-compressed audio."
  - question: "How fast is Grok Voice Think Fast 2.0?"
    answer: "Time to first audio is 0.70s, down from 1.25s in version 1.0. Because the model reasons while it speaks, tool calls usually execute before the end of the agent's first sentence."
---

Voice agents fail in two places that a benchmark table rarely captures: the pause before the agent starts speaking, and the words it gets wrong when a customer calls from a car with the windows down. Latency breaks the illusion of a conversation, and a single misheard order number breaks the transaction. Most voice stacks trade one for the other.

SpaceXAI's answer is [Grok Voice Think Fast 2.0](https://x.ai/news/grok-voice-think-fast-2), announced on July 29, 2026 as the next generation of its speech-to-speech voice model. The pitch is that you no longer have to choose. It is faster to first audio, more accurate on transcription, and smarter in conversation than its predecessor, without any changes to your existing prompts.

This post covers what Grok Voice Think Fast 2.0 is, how it performs on the benchmarks SpaceXAI published, what it costs, how migration works, and what you need on the backend to ship a voice agent that actually does something.

# What is Grok Voice Think Fast 2.0?

**Grok Voice Think Fast 2.0 is a speech-to-speech model that reasons in parallel with speech.** Instead of transcribing audio, sending text to a separate reasoning model, and then synthesizing a reply, it takes audio in and produces audio out while thinking through the query mid-response. That parallelism is the reason the model can be substantially smarter than other speech-to-speech models with no added latency.

The headline numbers from the launch:

- **Speech-to-speech quality:** 82.9% on the Artificial Analysis Speech-to-Speech Quality Index, up from 75.7% in version 1.0.
- **Time to first audio:** 0.70s, down from 1.25s.
- **Reasoning tokens:** roughly 0.4x the volume of version 1.0 per response.
- **Price:** $0.08 per minute of audio.

Each of those maps to a real failure mode in production voice apps. The rest of this post breaks them down.

# Grok Voice Think Fast 2.0 benchmarks

SpaceXAI published a speech-to-speech comparison sourced from [Artificial Analysis](https://artificialanalysis.ai/), measuring Grok Voice Think Fast 2.0 against its predecessor, GPT-Realtime-2.1, and Gemini 3.1 Flash. Treat these as vendor-reported results run against a third-party suite.

| Benchmark | Grok Voice Think Fast 2.0 | Grok Voice Think Fast 1.0 | GPT-Realtime-2.1 (High) | Gemini 3.1 Flash (High) |
| --- | ---: | ---: | ---: | ---: |
| AA Speech-to-Speech Quality Index | **82.9%** | 75.7% | 79.1% | 69.5% |
| Big Bench Audio (speech reasoning) | **97.2%** | 97.1% | 96.0% | 96.6% |
| Full Duplex Bench (conversational dynamics) | 95.1% | 77.8% | **95.7%** | 74.3% |
| τ-voice Bench (agentic performance) | **56.5%** | 52.1% | 45.7% | 37.7% |
| Time to First Audio | **0.70s** | 1.25s | Not reported | 2.98s |

Two results stand out for anyone building agents rather than demos.

**Agentic performance leads the field.** τ-voice Bench measures whether the model can drive multi-step, tool-using workflows over voice. At 56.5%, Grok Voice Think Fast 2.0 is roughly 11 points ahead of GPT-Realtime-2.1 and nearly 19 ahead of Gemini 3.1 Flash. If your voice agent looks up an account, checks inventory, or books an appointment, this is the column that predicts whether it completes the task.

**Time to first audio nearly halves.** Dropping from 1.25s to 0.70s is the difference between a natural turn and an awkward gap. Gemini 3.1 Flash at 2.98s sits in a different latency class entirely. The one benchmark where the model trails is Full Duplex Bench, where GPT-Realtime-2.1 edges it 95.7% to 95.1%, a gap small enough to be within run-to-run noise.

# Grok Voice Think Fast 2.0 transcription accuracy

Transcription is where the model makes its most surprising claim: it beats dedicated speech-to-text systems at their own job. SpaceXAI evaluated word error rate across thousands of short phrases in 24 languages.

Key results, measured as word error rate where lower is better:

- **1.5 to 2.0x lower error** than [Deepgram Nova 3](https://deepgram.com/) and [ElevenLabs Scribe v2](https://elevenlabs.io/), both purpose-built transcription models.
- **1.4x lower error** than Grok Voice Think Fast 1.0.
- **Roughly 10x lower error in noisy settings**, where the gap to dedicated speech-to-text models widens the most.

That last point is the one that matters in the field. The evaluation deliberately targeted real-world conditions: substantial background noise and telephony compression, the exact environment a support line or drive-through order actually runs in. A model that wins on clean studio audio but collapses on a compressed phone call is not useful for a call center. This one is tuned for the hard case.

Coverage spans English, Spanish, Portuguese, Arabic, Chinese, Japanese, Hindi, and more, including regional variants such as Spanish (Spain) versus Spanish (Mexico) and Arabic across Saudi Arabia and the UAE. For multilingual products, that regional split matters more than a single aggregate score.

# How Grok Voice Think Fast 2.0 reasons while speaking

The defining trait of the Think Fast line is that it reasons through a query *while* speaking, not before. Reasoning in parallel with speech is what lets the model be meaningfully smarter than other speech-to-speech models without paying for it in latency.

Version 2.0 pushes this further by using fewer reasoning tokens to reach the same or better answers.

| Metric | Grok Voice Think Fast 2.0 | Grok Voice Think Fast 1.0 |
| --- | ---: | ---: |
| Relative reasoning tokens per response (P50) | **0.4x** | 1.0x |

In production, that efficiency has a concrete payoff: **tool calls fire faster**. SpaceXAI reports that a tool call usually executes before the end of the agent's first sentence. So while the agent says "Let me pull that up for you," the request to your backend is already in flight. By the time the sentence ends, the data is often back. That is the behavior that makes a voice agent feel responsive instead of stalled.

# Grok Voice Think Fast 2.0 conversational capabilities

Benchmarks reward correct answers, but voice agents live or die on how they feel to talk to. SpaceXAI used extensive reinforcement learning to push Grok Voice Think Fast 2.0 toward patterns observed in real human conversation.

In practice, the model:

- Speaks in **shorter sentences** rather than monologues.
- Asks **one question at a time** instead of stacking prompts.
- **Avoids filler** and unnecessary preamble.

The effect is that conversations feel simple and fluid from the caller's side, even when the model is guiding a complex workflow and thinking several steps ahead behind the scenes. Good conversational pacing is not cosmetic. A model that asks three questions in one breath forces the caller to remember and answer them in order, which is where real dialogues fall apart.

# Grok Voice Think Fast 2.0 pricing and migration

**Grok Voice Think Fast 2.0 is priced at $0.08 per minute of audio.** SpaceXAI bills per minute rather than per token, which keeps voice pricing predictable. You can estimate the cost of a call from its duration alone, without modeling how verbose the model will be.

Migration requires no work in the common case.

| Item | Detail |
| --- | --- |
| Price | $0.08 per minute of audio |
| Migration date | August 5, 2026 |
| What changes | `grok-voice-latest` moves from `grok-voice-think-fast-1.0` to `grok-voice-think-fast-2.0` |
| Action to upgrade | None |
| Action to stay on 1.0 | Pin `grok-voice-think-fast-1.0` before August 5, 2026 |

SpaceXAI expects improved performance across almost all use cases with no prompt edits. In A/B testing on Starlink's support line (+1 888 GO STARLINK), it reported a meaningful increase in both sales conversion rate and support containment rate, the share of calls resolved without a human agent. If you are using the floating alias, the upgrade happens automatically on August 5. xAI recommends evaluating the new model on your own workloads.

# When to reach for Grok Voice Think Fast 2.0

The model is built for the voice agent loop: listen, reason, call a tool, respond, and repeat, all in real time. It is the right choice when:

- **Latency is user-facing.** Phone support, drive-through ordering, and live assistants where a half-second pause is noticeable.
- **Audio is messy.** Contact centers running over telephony, or any product where callers are in cars, kitchens, or crowds.
- **The agent has to act, not just talk.** Workflows that look up accounts, check inventory, or complete transactions, where τ-voice-style tool use is the point.
- **You serve multiple languages.** Products spanning regional variants that a single-language model would mishandle.

Be careful where correctness depends on private context the model cannot hear. The model can call tools, but it still needs scoped credentials, current data, and a backend that returns the right answer. A fast, accurate voice model wired to the wrong data source just gets to the wrong answer sooner.

# Building voice agents with Grok Voice Think Fast 2.0 and Appwrite

A speech-to-speech model is only half of a voice agent. The other half is everything the tool calls actually do: authenticate the caller, read and write their data, run the business logic, and stream state to whatever screen is watching the call. The model handles voice. Something has to handle the rest.

[Appwrite](/) is an open source backend that covers that half:

- [**Functions**](/docs/products/functions) run the tool calls the model triggers, from looking up an order to booking an appointment, as serverless endpoints in the language you already use.
- [**Databases**](/docs/products/databases) store conversation state, transcripts, and the records the agent reads and updates during a call.
- [**Auth**](/docs/products/auth) identifies the caller and scopes what the agent is allowed to touch, so a voice command cannot reach data it should not.
- [**Realtime**](/docs/apis/realtime) streams call state and results to a dashboard or companion app as the conversation happens.
- [**Messaging**](/docs/products/messaging) sends the follow-up SMS, email, or push once the call ends.

Wiring these together by hand is where the time saved by a fast model usually goes to die. Appwrite gives your agent one project with all of it already in place, on managed Cloud or self-hosted. If you're building with Grok Voice Think Fast 2.0, start with the [Grok Build guide](/docs/tooling/ai/agents/grok-build) to connect Grok to Appwrite for tool calling. You can also explore [building with AI function templates](/blog/post/building-with-ai-function-templates), and if you're comparing backend options, [Appwrite vs Convex for AI agents](/blog/post/appwrite-vs-convex-ai-agents) walks through the trade-offs.

To try the full loop, [create a free Appwrite project](https://cloud.appwrite.io/), expose your business logic as Functions, and point your Grok Voice Think Fast 2.0 agent's tool calls at them. You end up with a voice agent that can actually complete a task, not just describe one.

# Resources

- [Grok Voice Think Fast 2.0 announcement](https://x.ai/news/grok-voice-think-fast-2)
- [Appwrite Functions](/docs/products/functions)
- [Appwrite Databases](/docs/products/databases)
- [Appwrite AI tooling](/docs/tooling/ai)
- [Create a free Appwrite project](https://cloud.appwrite.io/)
- [Join the Appwrite Discord](/discord)
