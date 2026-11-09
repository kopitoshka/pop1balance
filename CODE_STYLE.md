# Code style

Andrey's personal PHP style. All new code and changes follow it.

- **Tabs** for leading indentation at every level: PHP, SQL migrations, `phpunit.xml.dist`, `composer.json`
	(see `.editorconfig`). Spaces only for alignment after the tabs.
- **No `final`** anywhere: classes, methods, constants.
- **No `private`**: methods, properties, constants and promoted constructor params are `protected`. Public stays public.
- **Opening braces on the same line** for classes, methods and control flow:

	```php
	class Foo {

		public function bar(int $limit): int {
			if ($limit > 0) {
				return $limit;
			}
			return 0;
		}
	}
	```

- `if`/`else`/loops **always have braces**, every operation on its own line. No one-line control flow.
- **Exactly one blank line before each method** (before its doc comment, if there is one), including the first one in a class.
- **Doc comments are multiline**, no decorative `*` column: `/**` on its own line, TAB-indented text, `*/` on its own line.
	Annotations (`@param`, `@return`, `@var`) stay, just without the star. Same for other multiline `/* */` comments.

	```php
	/**
		Tournaments in the chat, newest first.

		@param list<string> $statuses
		@return list<Tournament>
	*/
	```

- **snake_case** for local variables, parameters and properties (`$chat_id`, `$this->card_msg_id`).
	Class and method names keep their names (`TournamentRepository::listForChat()`).
	Array keys used in JSON, the DB or the OpenAI tool schema are data and are not renamed.
- **Long arrays and argument lists are multiline**, one item per line with a trailing comma.
	Short scalar word lists may be grouped several per line.
- PHP 8.0 compatible: no enums, `readonly`, first-class callables, `never`, `new` in initializers, pure intersection types.
- Russian user-facing texts in a human tone; code, comments and commits in English.
