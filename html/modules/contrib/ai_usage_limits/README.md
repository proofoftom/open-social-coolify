# AI usage limits

**AI usage limits** is a Drupal module that extends the Drupal AI module by
allowing administrators to define usage limits for each enabled AI provider.

## Features

- Supports the following usage limits:
  - Input token usage
  - Output token usage
  - Total token usage
  - Cached token usage
  - Reasoning token usage
- Admin UI available at `/admin/config/ai/usage_limits`.

## Limitations

Currently only tested with the AI Provider OpenAI module.
If you have it working with another provider, please let the module author know
via the issue queue.

## Permissions

- **administer ai providers** — Required to access and modify usage limits.

## Requirements

- [Drupal AI module](https://www.drupal.org/project/ai)
  - Requires version 1.2.0-beta1 or higher

## Compatibility

- [AI Provider OpenAI](https://www.drupal.org/project/ai_provider_openai)
  - Requires version 1.2.0-alpha1 or higher
  - Potentially also works with other AI providers extending the
    `OpenAiBasedProviderClientBase` class from the AI core module (untested).

## Alternatives
You can use [LiteLLM](https://www.litellm.ai) as a proxy, and connect that with
OpenAI or other AI providers. LiteLLM allows for cost management. Use
[AI Provider LiteLLM](https://www.drupal.org/project/ai_provider_litellm)to
connect to a LiteLLM provider.
