<?php

namespace iTRON\cf7Telegram\Controllers;

use iTRON\cf7Telegram\Channel;
use iTRON\cf7Telegram\Client;
use iTRON\wpConnections\Exceptions\RelationNotFound;
use iTRON\wpConnections\Query;

class CF7 {
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

    /**
     * @throws RelationNotFound
     */
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
		foreach ( $targetChannels as $channel ) {
			/** @var Channel $channel */
			$channel->doSendOut( apply_filters( 'cf7tg_filtered_message', $output, $instance, $mode ), $mode );
		}
	}

	public static function markdown( $content ){
		$tags = apply_filters( 'cf7tg_markdown', self::$markdown_tags );
		extract( $tags );

		$content = ! empty( $bold ) ? str_replace( $bold, '*', $content ) : $content;
		$content = ! empty( $italic ) ? str_replace( $italic, '_', $content ) : $content;
		$content = ! empty( $code ) ? str_replace( $code, ' ``` ', $content ) : $content;
		$content = ! empty( $underline ) ? str_replace( $underline, '__', $content ) : $content;
		$content = ! empty( $strike ) ? str_replace( $strike, '~', $content ) : $content;

		return apply_filters( 'cf7tg_markdown_content', $content, $tags );
	}
}