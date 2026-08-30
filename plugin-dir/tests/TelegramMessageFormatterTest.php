<?php

declare( strict_types=1 );

use iTRON\cf7Telegram\Controllers\CF7;
use iTRON\cf7Telegram\Telegram\TelegramMessageFormatter;

final class TelegramMessageFormatterTest extends Cf7tg_TestCase {
	public function testMarkdownEscapesUnderscoresAndSpecialCharactersWithoutEscapingTagMarkers(): void {
		$markdown = CF7::markdown( 'first_name <b>A_B</b> test_two [link] `code` *star*' );

		$this->assertSame(
			'first\_name *A\_B* test\_two \[link] \`code\` \*star\*',
			$markdown
		);
	}

	public function testMarkdownRunsLegacyAndCurrentTagFiltersAndContentFilter(): void {
		add_filter(
			'wpcf7tg_markdown',
			static function ( array $tags ): array {
				$tags['bold'] = [ '<legacy>', '</legacy>' ];
				return $tags;
			}
		);

		add_filter(
			'cf7tg_markdown',
			static function ( array $tags ): array {
				$tags['italic'] = [ '<current>', '</current>' ];
				return $tags;
			}
		);

		add_filter(
			'cf7tg_markdown_content',
			static fn( string $content, array $tags ): string => $content . '|' . implode( ',', $tags['bold'] ),
			10,
			2
		);

		$this->assertSame(
			'*Legacy* _Current_|<legacy>,</legacy>',
			CF7::markdown( '<legacy>Legacy</legacy> <current>Current</current>' )
		);
	}

	public function testPlaintextConvertsHtmlAndMarkdownWithoutLosingWordUnderscores(): void {
		$this->assertSame(
			'A & B plain',
			TelegramMessageFormatter::plaintext( '<b>A &amp; B</b> plain', 'HTML' )
		);

		$this->assertSame(
			'hello_name Bold Italic Under Strike [x]',
			TelegramMessageFormatter::plaintext( 'hello\_name *Bold* _Italic_ __Under__ ~Strike~ \[x]', 'Markdown' )
		);
	}

	public function testChunksKeepUnicodeMessagesAtOrBelowTelegramLimit(): void {
		$exact = str_repeat( 'Ж', TelegramMessageFormatter::MAX_MESSAGE_LENGTH );
		$over = $exact . 'Я';

		$exactChunks = TelegramMessageFormatter::chunks( $exact );
		$overChunks = TelegramMessageFormatter::chunks( $over );

		$this->assertSame( 1, count( $exactChunks ) );
		$this->assertSame( TelegramMessageFormatter::MAX_MESSAGE_LENGTH, $this->unicodeLength( $exactChunks[0] ) );
		$this->assertSame( 2, count( $overChunks ) );
		$this->assertSame( TelegramMessageFormatter::MAX_MESSAGE_LENGTH, $this->unicodeLength( $overChunks[0] ) );
		$this->assertSame( 1, $this->unicodeLength( $overChunks[1] ) );
		$this->assertSame( $over, implode( '', $overChunks ) );
	}

	private function unicodeLength( string $text ): int {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $text, 'UTF-8' );
		}

		preg_match_all( '/./us', $text, $matches );
		return count( $matches[0] ?? [] );
	}
}
