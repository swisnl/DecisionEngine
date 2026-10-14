# Upgrade guide

## Unreleased

### OpenAI engines

The `openai` driver now targets OpenAI's Decisions API (`/v1/decisions`, `gpt-6-luna`). The previous
structured-output engine on the Responses API is renamed:

| Before | After |
|---|---|
| `Engines\OpenAi\OpenAiEngine` | `Engines\OpenAi\OpenAiResponsesEngine` |
| driver `openai` | driver `openai_responses` |
| `Outcome::$engine === 'openai'` | `'openai_responses'` |
| config error `engines.openai.api_key` | `engines.openai_responses.api_key` |

If you published `config/decision-engine.php`, update every engine with `driver => 'openai'`: it
now sends to the Decisions API, which only accepts `gpt-6-luna`. Either keep the Responses API by
setting `driver => 'openai_responses'`, or move to the Decisions API by setting
`model => 'gpt-6-luna'` and dropping the `reasoning` option (Responses-only options are ignored;
use `engineOptions(['merge_body' => …])` for body tweaks).

## 0.1.0

Initial release; nothing to upgrade from. Breaking changes between minor versions will be listed
here before 1.0.
