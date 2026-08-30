<?php

namespace iTRON\cf7Telegram;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

class Compatibility {
	public static function init(): void {
		add_action( 'wpcf7_init', [ self::class, 'registerTelegramTag' ] );
	}

	public static function registerTelegramTag(): void {
		if ( ! function_exists( 'wpcf7_add_form_tag' ) ) {
			return;
		}

		wpcf7_add_form_tag( 'telegram', [ self::class, 'renderTelegramTag' ], [ 'display-block' => true ] );
	}

	public static function renderTelegramTag(): string {
		return '';
	}
}
