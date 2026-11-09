<?php

declare(strict_types=1);

namespace P1Bot\Tests\Integration;

use P1Bot\Bootstrap;
use P1Bot\Tests\Support\IntegrationTestCase;

/**
	The bot speaks in the feminine gender; BOT_PERSONA_NAME (optional) gives her a name.
*/
class PersonaTest extends IntegrationTestCase {
	protected string $persona = 'Фемида';

	protected function config(): array {
		$config = parent::config();
		$config['openai']['api_key'] = 'sk-test';
		$config['persona_name'] = $this->persona;
		return $config;
	}

	protected function instructions(): string {
		$this->http->queueOpenAi(['id' => 'r1', 'output' => [[
			'type' => 'message',
			'role' => 'assistant',
			'content' => [['type' => 'output_text', 'text' => 'Привет!']],
		]]]);
		$this->dispatch($this->privateMessage($this->player('vasya'), 'привет'));
		foreach ($this->http->requests as $request) {
			if (str_ends_with($request['url'], '/responses')) {
				return (string) $request['json']['instructions'];
			}
		}
		return '';
	}

	public function testPromptIsFeminineAndNamesHer(): void {
		$prompt = $this->instructions();
		self::assertStringContainsString('помощница по турнирам', $prompt);
		self::assertStringContainsString('в женском роде', $prompt);
		$lines = explode("\n", $prompt);
		self::assertSame('Тебя зовут Фемида. Если спрашивают, как тебя зовут, представляйся так.', $lines[1]);
	}

	public function testNoNameByDefault(): void {
		$this->persona = '';
		parent::setUp();
		$prompt = $this->instructions();
		self::assertStringContainsString('в женском роде', $prompt);
		self::assertStringNotContainsString('Тебя зовут', $prompt);
	}

	public function testNameIsCleanedUp(): void {
		self::assertSame(
			"a\nТебя зовут Фе ми да. Если спрашивают, как тебя зовут, представляйся так.\nb",
			Bootstrap::withPersonaName("a\nb", "  Фе\nми  да "),
		);
		self::assertSame("a\nb", Bootstrap::withPersonaName("a\nb", ' '));
	}
}
