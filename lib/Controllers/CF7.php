<?php

namespace iTRON\cf7Telegram\Controllers;

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

use iTRON\cf7Telegram\Channel;
use iTRON\cf7Telegram\Client;
use iTRON\wpConnections\Query;

class CF7 {
	const CMD = 'cf7tg_start';

	private static array $markdown_tags = [
		'bold' => [
			'<h1>','</h1>', '<h2>','</h2>', '<h3>','</h3>', '<h4>','</h4>', '<h5>','</h5>', '<h6>','</h6>',
			'<b>','</b>',
			'<strong>','</strong>',
			'<mark>','</mark>'
		],
		'italic' => [
			'<em>','</em>',
			'<i>','</i>'
		],
		'code' => [
			'<code>','</code>',
			'<pre>','</pre>'
		],
		'underline'	=> [
			'<u>','</u>', '<ins>','</ins>'
		],
		'strike' => [
			'<s>','</s>', '<strike>','</strike>'
		]
	];

    public static function handleSubscribe(\WPCF7_ContactForm $cf, &$abort, \WPCF7_Submission $instance ) {

		if ( $abort ) {
			return;
		}

		if ( apply_filters( 'wpcf7tg_skip_tg', false, $cf, $instance ) ) {
			return;
		}

		$client = Client::getInstance();

		$connections = $client->getForm2ChannelRelation()->findConnections( new Query\Connection( $cf->id() ) );


		if ( $connections->isEmpty() ) {
			return;
		}

		$mail = $cf->prop( 'mail' );
		$output = apply_filters( 'cf7tg_unfiltered_message', wpcf7_mail_replace_tags( @$mail[ 'body' ] ), $instance );

		$mode = 'HTML';
		if ( false === @$mail['use_html'] ) :
			$mode = 'Markdown';
			$output = self::markdown( $output );
			$output = wp_kses( $output, [] );
		else :
			$output = wp_kses( $output, array(
				'a'	=> array( 'href' => true ),
				'b' => [], 'strong' => [], 'i' => [], 'em' => [], 'u' => [], 'ins' => [], 's' => [], 'strike' => [], 'del' => [], 'code' => [], 'pre' => [],
			) );
		endif;

		$targetChannels = $client->getChannels()->filterByIDs( $connections->column( 'to' ) );
		$deliveries = [];
		foreach ( $targetChannels as $channel ) {
			/** @var Channel $channel */
			try {
				$deliveries = array_merge(
					$deliveries,
					$channel->doSendOut( apply_filters( 'cf7tg_filtered_message', $output, $instance, $mode ), $mode, $instance )
				);
			} catch ( \Throwable $exception ) {
				do_action( 'cf7tg_telegram_delivery_exception', $exception, $channel, $instance );
			}
		}

		if ( ! empty( $deliveries ) ) {
			$legacyList = [];
			foreach ( $deliveries as $delivery ) {
				$chatID = $delivery['chatID'] ?? null;
				if ( null !== $chatID ) {
					$legacyList[ $chatID ] = [ 'id' => $chatID ];
				}
			}

			do_action( 'wpcf7tg_messages_sent', $legacyList, $output, $mode, $instance );
			do_action( 'cf7tg_telegram_deliveries_completed', $deliveries, $output, $mode, $instance );
		}
	}

	public static function markdown( $content ){
		$tags = apply_filters( 'wpcf7tg_markdown', self::$markdown_tags );
		$tags = apply_filters( 'cf7tg_markdown', $tags );

		$replacements = [
			'bold'      => '*',
			'italic'    => '_',
			'code'      => ' ``` ',
			'underline' => '__',
			'strike'    => '~',
		];

		$placeholders = [];
		foreach ( $replacements as $group => $marker ) {
			foreach ( (array) ( $tags[ $group ] ?? [] ) as $index => $tag ) {
				$placeholder = "\x1A" . $group . $index . "\x1A";
				$placeholders[ $placeholder ] = $marker;
				$content = str_replace( $tag, $placeholder, $content );
			}
		}

		$content = self::escapeMarkdownText( $content );
		$content = str_replace( array_keys( $placeholders ), array_values( $placeholders ), $content );

		return apply_filters( 'cf7tg_markdown_content', $content, $tags );
	}

	private static function escapeMarkdownText( string $content ): string {
		return preg_replace( '/([_*`\[])/', '\\\\$1', $content ) ?? $content;
	}
}
