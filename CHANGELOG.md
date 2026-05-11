# Changelog

## [0.6.2](https://github.com/pdro-lucas/filament-ai-writer/compare/v0.6.1...v0.6.2) (2026-05-11)


### Miscellaneous

* **deps:** update filament/filament requirement || ^5.0 ([a115e84](https://github.com/pdro-lucas/filament-ai-writer/commit/a115e84c53ec37adacbd77ed61d5a8c5defe1af1))
* **deps:** update filament/filament requirement from ^4.0 to ^4.0 || ^5.0 ([bdc0773](https://github.com/pdro-lucas/filament-ai-writer/commit/bdc0773441064ea149c88f142cd8b91277cacbfb))

## [0.6.1](https://github.com/pdro-lucas/filament-ai-writer/compare/v0.6.0...v0.6.1) (2026-05-11)


### Documentation

* add demonstration video ([80e2164](https://github.com/pdro-lucas/filament-ai-writer/commit/80e2164e89595e4398188cde8ad3ed522ef36741))

## [0.6.0](https://github.com/pdro-lucas/filament-ai-writer/compare/v0.5.1...v0.6.0) (2026-05-10)


### Features

* add modal tone/length/emoji controls and performance improvements ([355c58d](https://github.com/pdro-lucas/filament-ai-writer/commit/355c58d06bb5ba806ed5d08e9a8e57943951ff1f))

## [0.5.1](https://github.com/pdro-lucas/filament-ai-writer/compare/v0.5.0...v0.5.1) (2026-05-10)


### Bug Fixes

* replace max_tokens with max_completion_tokens for OpenAI ([de4e2a2](https://github.com/pdro-lucas/filament-ai-writer/commit/de4e2a2669cf3397a8634590d0d60968ece30de0))

## [0.5.0](https://github.com/pdro-lucas/filament-ai-writer/compare/v0.4.0...v0.5.0) (2026-05-10)


### Features

* add beforeGenerate hooks and AiTextGenerated event ([00089b9](https://github.com/pdro-lucas/filament-ai-writer/commit/00089b95d99e8df51e49714723c42c9aee3d365c))
* add beforeGenerate hooks and AiTextGenerated event ([fc602ed](https://github.com/pdro-lucas/filament-ai-writer/commit/fc602ed584b3063286ba1a8fb7de6a70aba3c068))


### Bug Fixes

* resolve AiTextGenerated user via Filament guard ([22abfe0](https://github.com/pdro-lucas/filament-ai-writer/commit/22abfe073293eda95569b6e7f0f9f644833d94b5))


### Miscellaneous

* ignore .idea directory ([55209a6](https://github.com/pdro-lucas/filament-ai-writer/commit/55209a6ad9e9d6f899953c782d4921145cfbf6e9))


### Documentation

* add beforeGenerate hooks, AiTextGenerated event, and contributing section ([5c8df3a](https://github.com/pdro-lucas/filament-ai-writer/commit/5c8df3aafbacd9e20f82db74fbad4e2e26c1f251))
* add beta status notice to README ([81b9033](https://github.com/pdro-lucas/filament-ai-writer/commit/81b9033b87cc8dd5fc4a4b4211420f385fc9d51e))
* fix event listener registration example location ([4886513](https://github.com/pdro-lucas/filament-ai-writer/commit/4886513352dd2eb0cc91d756208f1cf6301fd7f1))
* update example to handle unauthenticated cases ([8214db5](https://github.com/pdro-lucas/filament-ai-writer/commit/8214db53947c22b892ab57e06a3a235a3c5e8a69))


### Code Refactoring

* improve notification handling and update action color to fuchsia ([ff0740b](https://github.com/pdro-lucas/filament-ai-writer/commit/ff0740be4891bb0fe89461aa239d44707168c0bf))

## [0.4.0](https://github.com/pdro-lucas/filament-ai-writer/compare/v0.3.1...v0.4.0) (2026-05-10)


### Features

* add configurable HTTP timeout and retry settings to AI providers ([4d08b63](https://github.com/pdro-lucas/filament-ai-writer/commit/4d08b634e589b58fc540c37d4dabeaab1410c0f0))


### Documentation

* document timeout and retry environment variables ([8eb182e](https://github.com/pdro-lucas/filament-ai-writer/commit/8eb182e63c4bb211bcd68133b434c59896f806ee))

## [0.3.1](https://github.com/pdro-lucas/filament-ai-writer/compare/v0.3.0...v0.3.1) (2026-05-10)


### Documentation

* add dynamic tags example and simplify local development wording ([d88eac6](https://github.com/pdro-lucas/filament-ai-writer/commit/d88eac6ce1997e8370511bddd82e86d99e443e17))

## [0.3.0](https://github.com/pdro-lucas/filament-ai-writer/compare/v0.2.0...v0.3.0) (2026-05-10)


### Features

* add silent mode with context fields, value map, allowed values, and array support ([bd2a475](https://github.com/pdro-lucas/filament-ai-writer/commit/bd2a475b04317b7a82a31dcdc6f5039ef1578591))


### Documentation

* update README with silent mode usage examples and full API reference ([52f1c29](https://github.com/pdro-lucas/filament-ai-writer/commit/52f1c295d38f256dc2de6e832a34394379cb425d))

## [0.2.0](https://github.com/pdro-lucas/filament-ai-writer/compare/v0.1.0...v0.2.0) (2026-05-10)


### Features

* add AI provider implementations (OpenAI, Anthropic, Gemini) ([76c6838](https://github.com/pdro-lucas/filament-ai-writer/commit/76c6838cc7482ea7ae546c184985d9c2d70905d6))
* add AiProvider contract interface ([90ca377](https://github.com/pdro-lucas/filament-ai-writer/commit/90ca377125d14bc57e21d33a4a23faf093229ba0))
* add AiWriterAction with modal form and configurable prompt ([4adcb54](https://github.com/pdro-lucas/filament-ai-writer/commit/4adcb54b69d39ec9f460e0cc692605cf872517ab))
* add service provider with auto-binding and config publishing ([105b725](https://github.com/pdro-lucas/filament-ai-writer/commit/105b7254fa17e1e8dd4a7e3dc6e97fb3d215389a))


### Miscellaneous

* **deps:** bump googleapis/release-please-action from 4 to 5 ([cf8b1e0](https://github.com/pdro-lucas/filament-ai-writer/commit/cf8b1e02b4a8c7e1e54c56b4c4b93fb737b04d37))
* **deps:** bump googleapis/release-please-action from 4 to 5 ([718b125](https://github.com/pdro-lucas/filament-ai-writer/commit/718b125e78df683f0134d9bbe6eccc56d8fc3278))
* initialize project with composer.json and config ([4a25952](https://github.com/pdro-lucas/filament-ai-writer/commit/4a2595291ee52a7bc175e61aafe928f537d9346e))
* setup dependabot and release-please for automated releases ([266a11d](https://github.com/pdro-lucas/filament-ai-writer/commit/266a11d5bcaf54ed8cdf8b1610b8c4058a36370d))
* trigger release-please with updated permissions ([ff1e17b](https://github.com/pdro-lucas/filament-ai-writer/commit/ff1e17b92d7d956df7e196f73dc4e14303aea693))
* update license year to 2026 ([968dab2](https://github.com/pdro-lucas/filament-ai-writer/commit/968dab25ac8629caa3aee27c74ecb7f61b6ed79a))


### Documentation

* add license file and contributing section to README ([1c5da28](https://github.com/pdro-lucas/filament-ai-writer/commit/1c5da28d753daa946f03c641e58a41025241c22d))
* add README with installation, configuration, and usage examples ([85f332f](https://github.com/pdro-lucas/filament-ai-writer/commit/85f332fcf54936562aac812515c97330e208faab))
