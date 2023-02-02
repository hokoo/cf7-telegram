<?php
class wpcf7_Telegram{
	
	private
	$cmd = 'cf7tg_start',	
	$bot_token;
	
	static
	$instance;
	
	public 
	$domain = 'cf7-telegram',
	$api_url = 'https://api.telegram.org/bot%s/',
	$chats = array(),
	$addons = array(),
	$markdown_tags = array(
		'bold' => array(
			'<h1>','</h1>', '<h2>','</h2>', '<h3>','</h3>', '<h4>','</h4>', '<h5>','</h5>', '<h6>','</h6>',
			'<b>','</b>',
			'<strong>','</strong>',
			'<mark>','</mark>'
		),
		'italic' => array(
			'<em>','</em>',
			'<i>','</i>'
		),
		'code' => array(
			'<code>','</code>',
			'<pre>','</pre>'
		),
		'underline'	=> array(
			'<u>','</u>', '<ins>','</ins>',
		),
		'strike' => array(
			'<s>','</s>', '<strike>','</strike>',
		),
	);
	
	function __construct(){
		if ( ! empty( self::$instance ) ) return new WP_Error( 'duplicate_object', __( 'Prevent duplicate object creation', WPCF7TG_DOMAIN ) );
	}
	
	private function init(){
		$this->addons = array(
			'wpcf7tg_mediafiles' => __( 'File Sending', WPCF7TG_DOMAIN ),
		);
		
		$this->load_bot_token();
		$this->load_chats();
		
		add_action( 'admin_menu', array( $this, 'menu_page' ) );
		add_action( 'admin_init', array( $this, 'save_form' ), 50 );
		add_action( 'admin_init', array( $this, 'settings_section' ), 999 );
		add_action( 'current_screen', array( $this, 'current_screen' ), 999 );
		add_action( 'wpcf7_telegram_settings', array( $this, 'check_updates' ), 999 );
		add_action( 'wpcf7_init', array( $this, 'tg_shortcode' ) );
		add_action( 'wpcf7_before_send_mail', array( $this, 'send' ), 99999, 3 );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		add_action( 'wp_ajax_wpcf7_tg', array( $this, 'ajax' ) );	
	}
	
	public static function get_instance(){
		if ( empty( self::$instance ) ) :
			self::$instance = new self;
			self::$instance->init();
		endif;

		return self::$instance;
	}
	
	public function current_screen(){
		$screen = get_current_screen();
		//if ( 'contact-form-7_page_wpcf7_tg' != $screen->id ) return;
		if ( false === strpos( $screen->id, 'wpcf7_tg' ) ) return;
		do_action( 'wpcf7_telegram_settings' );
	}
	
	public function plugin_url() {
		return untrailingslashit( plugins_url( '/', WPCF7TG_FILE ) );
	}
	
	function admin_enqueue_scripts(){
		$me = self::get_instance();
		if ( ! did_action( 'wpcf7_telegram_settings' ) ) return;
		wp_enqueue_style( 'wpcf7telegram-admin-styles', $me->plugin_url() . '/css/admin.css', null, WPCF7TG_VERSION );
		wp_enqueue_script( 'wpcf7telegram-admin', $me->plugin_url() . '/js/admin.js', null, WPCF7TG_VERSION );
		wp_localize_script( 'wpcf7telegram-admin', 'wpData', array(
			'ajax'		=> admin_url('admin-ajax.php'),
			'nonce'		=> wp_create_nonce( 'wpcf7_telegram_nonce' ),
			'l10n'		=> array(
				'confirm_approve'	=> __( 'Do you really want to approve?', WPCF7TG_DOMAIN ),
				'confirm_refuse'	=> __( 'Do you really want to refuse?', WPCF7TG_DOMAIN ),
				'confirm_pause'		=> __( 'Do you really want to pause?', WPCF7TG_DOMAIN ),
				'approved'	=> __( 'Successfully approved', WPCF7TG_DOMAIN ),
				'refused'	=> __( 'Request refused', WPCF7TG_DOMAIN ),
			),
		) );
	}

	function settings_section() {
		$me = self::get_instance();
		add_settings_section(
			'wpcf7_tg_sections__main', 
			__( 'Bot-settings', WPCF7TG_DOMAIN ),
			array( $me, 'sections__main_callback_function' ),
			'wpcf7tg_settings_page'
		);
		
		add_settings_field( 
			'bot_token', 
			__( 'Bot Token<br/><small>You need to create your own Telegram-Bot.<br/><a target="_blanc" href="https://core.telegram.org/bots#3-how-do-i-create-a-bot">How to create</a></small>', WPCF7TG_DOMAIN ), 
			array( $me, 'settings_clb' ), 
			'wpcf7tg_settings_page', 
			'wpcf7_tg_sections__main', 
			array(
				'type'		=> 'password',
				'name'		=> 'wpcf7_telegram_tkn',
				'value'		=> self::has_token_constant() ? '' : $me->get_bot_token(),
				'disabled'	=> self::has_token_constant(),
				'ph'		=> self::has_token_constant() ? __( 'Defined by WPFC7TG_BOT_TOKEN constant', WPCF7TG_DOMAIN ) : __( 'or define by WPFC7TG_BOT_TOKEN constant', WPCF7TG_DOMAIN ),
			)
		);
	}
	
	function settings_clb( $data ){
		switch ( $data['type'] ){
			case 'text' :;
			case 'password' :
				$disabled = ! empty( $data['disabled'] ) ? ' disabled="disabled" ' : '';
				$placeholder = ' placeholder="'. esc_attr( @$data['ph'] ) . '"';
				echo 
				'<input type="'. esc_attr( $data['type'] ) .'" ' .
					'name="'. esc_attr( $data['name'] ) .'" ' .
					'value="'. esc_attr( $data['value'] ) .'"' .
					'class="large-text" ' .
					$disabled .
					$placeholder .
				'/>'; break;
		}
	}
	
	function menu_page(){
		add_submenu_page( 'wpcf7', 'CF7 Telegram', 'CF7 Telegram', 'wpcf7_read_contact_forms', 'wpcf7_tg', array( self::get_instance(), 'plugin_menu_cbf' ) );
	}
	function plugin_menu_cbf(){
	?>	
		<div class="wrap">	
			<h1><?php echo __( 'Telegram notificator settings', WPCF7TG_DOMAIN ); ?></h1>
			<?php 
				$this->bot_status();
				$this->view_full_list();
				settings_errors(); 
			?>
			<form method="post" action="admin.php?page=wpcf7_tg"> 
				<?php settings_fields( 'wpcf7tg_settings_page' ); ?>	
				<?php do_settings_sections( 'wpcf7tg_settings_page' ); ?> 
				<input type="hidden" name="wpcf7tg_settings_form_action" value="save" />
				<p><?php echo __( 'Just use the shortcode <code>[telegram]</code> in the form for activate notification through Telegram.', WPCF7TG_DOMAIN ); ?></p>
				<?php submit_button(); ?>	
			</form>	
			<?php
				$this->view_addonds();
			?>
		</div> 
	<?php			
	}
	
	function sections__main_callback_function(){
		echo '';
	}
	
	function tg_shortcode(){
		wpcf7_add_form_tag( 'telegram', array( self::get_instance(), 'tg_shortcode_handler' ) );
	}
	function tg_shortcode_handler(){
		return '<input type="hidden" name="wpcf7tg_sending" value="1" />';
	}
	
	function save_form(){
		$me = self::get_instance();
		if ( $me->current_action() !== 'update' ) return;
		if ( ! wp_verify_nonce( @ $_POST['_wpnonce'], 'wpcf7tg_settings_page-options' ) ) return;
		
		$me->save_bot_token();
	}
	
	function current_action(){
		return isset( $_REQUEST['action'] ) ? $_REQUEST['action'] : '';
	}
	
	private function load_bot_token(){
		$token = get_option( 'wpcf7_telegram_tkn' );
		$this->bot_token = empty( $token ) ? '' : $token;
		
		return $this;
	}
	
	private function set_bot_token( $token ){
		$this->bot_token = $token;
		update_option( 'wpcf7_telegram_tkn', $token, false );
		return $this;
	}
	
	private function save_bot_token(){
		if ( self::has_token_constant() ) :
			$this->set_bot_token( '' );
			return;
		endif;
		
		$token = $_REQUEST['wpcf7_telegram_tkn'];
		$this->set_bot_token( $token );
		return $this;
	}
	
	private function save_chats( $chats ){
		update_option( 'wpcf7_telegram_chats', $chats, false );
		return $this;
	}
	
	function load_chats(){
		$chats = get_option( 'wpcf7_telegram_chats' );
		#	Back-compat
		if ( ! empty( $chats ) && is_string( $chats ) ) :
			$list = explode( ',', $chats );
			$chats = array();
			
			foreach( $list as $item )
			$chats[ $item ] = array( 'id' => $item, 'status' => 'active', 'first_name' => '', 'last_name' => '' );
			
			$this->save_chats( $chats );
		endif;
		$this->chats = empty( $chats ) ? array() : ( array ) $chats;
		return $this;
	}
	
	private function get_bot_token(){
		return $this->bot_token;
	}		

	private function get_api_url(){
		$token = self::has_token_constant() ? WPFC7TG_BOT_TOKEN : $this->get_bot_token();
		return sprintf( $this->api_url, $token );
	}
	
	function markdown( $content ){
		$tags = apply_filters( 'wpcf7tg_markdown', $this->markdown_tags );
		extract( $tags );
		$content = ! empty( $bold ) ? str_replace( $bold, '*', $content ) : $content;
		$content = ! empty( $italic ) ? str_replace( $italic, '_', $content ) : $content;
		$content = ! empty( $code ) ? str_replace( $code, '`', $content ) : $content;
		$content = ! empty( $underline ) ? str_replace( $underline, '__', $content ) : $content;
		$content = ! empty( $strike ) ? str_replace( $strike, '~', $content ) : $content;
		
		return $content;
	}
	
	function send( $cf, & $abort, $instance ){
		$me = self::get_instance();
		$list = $me->get_chats();
		if ( empty( $list ) ) return;
		
		$data = $instance->get_posted_data();
		
		if ( empty( $data['wpcf7_telegram'] ) && empty( $data['wpcf7tg_sending'] ) ) return; /* Backword compat 'wpcf7_telegram'. To delete since 1.0 */
		if ( $abort ) return;
		if ( apply_filters( 'wpcf7tg_skip_tg', false, $cf, $instance ) ) return;

		$mail = $cf->prop( 'mail' );
		$output = wpcf7_mail_replace_tags( @ $mail[ 'body' ] );
		$mode = 'HTML';
		if ( false === @ $mail['use_html'] ) :
			$mode = 'Markdown';
			$output = $me->markdown( $output ); 			
			$output = wp_kses( $output, array() );
		else :
			$output = wp_kses( $output, array(
				'a'	=> array( 'href' => true ),
				'b' => array(), 'strong' => array(), 'i' => array(), 'em' => array(), 'u' => array(), 'ins' => array(), 's' => array(), 'strike' => array(), 'del' => array(), 'code' => array(), 'pre' => array(),
			) );
		endif;		
		
		foreach( $list as $id => $chat ) :
			$chat_id = is_numeric( $id ) ? $id : $chat['id'];
			if ( ! is_numeric( $chat_id ) ) continue;			
			$msg_data = array(
				'chat_id'					=> $chat_id,
				'text'						=> $output,
				'parse_mode'				=> $mode,
				'disable_web_page_preview'	=> true
			);
			$me->api_request( 'sendMessage', apply_filters( 'wpcf7tg_sendMessage', $msg_data, $chat_id, $mode ) );
			do_action( 'wpcf7tg_message_sent', $msg_data, $instance );
		endforeach;
		
		do_action( 'wpcf7tg_messages_sent', $list, $output, $mode, $instance );
	}
	
	function check_bot(){
		$check = $this->api_request( 'getMe' );
		
		if ( false === $check ) 
		return new WP_Error( 'check_bot_error', __( 'An error has occured. See php error log.', WPCF7TG_DOMAIN ) );

		return $check;		
	}
	
	private function bot_status(){
		if ( ! $this->has_token() ) return;
		$check_bot = $this->check_bot();
		$status_format = 
			'<div class="check_bot %s">
				<strong class="status">%s</strong>
				<div>'. __( 'Bot username', WPCF7TG_DOMAIN ) . ': <code class="bot_username">%s</code></div>
			</div>';
		
		if ( ! is_wp_error( $check_bot ) ) :
			echo ( true === @ $check_bot->ok ) ? 
				sprintf( $status_format, 'online', __( 'Bot is online', WPCF7TG_DOMAIN ), '@' . $check_bot->result->username ) :
				sprintf( $status_format, 'failed', __( 'Bot is broken', WPCF7TG_DOMAIN ), __( 'unknown', WPCF7TG_DOMAIN ) );
		else :
			echo $check_bot->get_error_message();
		endif;
	}
	
	private function view_full_list(){
		echo '<h2>'. __( 'Subscribers list', WPCF7TG_DOMAIN ) .'</h2>';
		
		$req = $this->pending_html_list();
		$app = $this->approved_html_list();
		
		if ( ! $req && ! $app ) _e( 'List is empty', WPCF7TG_DOMAIN );
		
		echo '<p>', sprintf( __( 'Add user: send the <code>%s</code> comand to your bot', WPCF7TG_DOMAIN ), '/'. $this->cmd ), '</p>';
		echo '<p>', sprintf( __( 'Add group: add your bot to the group and send the <code>%s</code> comand to your group', WPCF7TG_DOMAIN ), '/'. $this->cmd ), '</p>';
	}
	
	private function get_listitem_data( $chat, $status = 'pending' ){
		return array( 
			$status,
			$chat['id'],
			$chat['id'] > 0 ? 'admin-users' : 'groups',
			empty( $str = trim( $chat['id'] > 0 ?
				$chat['first_name'] .' '. $chat['last_name'] :
				$chat['title'] ) ) ? "[{$chat['id']}]" : $str,
			empty( $chat['username'] ) ? '' : '@'. $chat['username'],
			isset( $chat['date'] ) ? wp_date( 'j F Y H:i:s', $chat['date'] ) : '',
		);
	}
	
	private function get_chats( $status = 'active' ){
		$result = array();
		foreach ( $this->chats as $id => $chat ) :
			if ( $status == $chat['status'] )
			$result[ $id ] = $chat;
		endforeach;
		
		return $result;
	}
	
	private function approved_html_list(){
		$list = $this->get_chats();
		if ( empty( $list) ) return array();
		
		foreach( $list as $id => $chat )				
		echo vsprintf( $this->get_template( 'f_item' ), $this->get_listitem_data( $chat, 'active' ) );

		return true;
	}
	
	private function pending_html_list(){
		$data = $this->get_chats( 'pending' );
		if ( empty( $data ) ) return false;
		
		foreach( $data as $id => $item ) :
			echo vsprintf( $this->get_template( 'f_item' ), $this->get_listitem_data( $item ) );
		endforeach;
		
		return true;
	}
	
	function check_updates(){
		$me = self::get_instance();
		$update_id = get_option( 'wpcf7_telegram_last_update_id' );
		$param = array(
			'allowed_updates'	=> array( 'message' ),
			'offset'			=> $update_id,
		);
		$updates = $me->api_request( 'getUpdates', $param );		
		if ( empty( $updates->result ) ) return;		
		
		$update_ids = array();		
		$upd = array();
		
		foreach( $updates->result as $one ) :
			$update_ids []= $one->update_id;
			
			if ( is_array( @ $one->message->entities ) )
			foreach( $one->message->entities as $ent ) :
				$cmd = substr( $one->message->text, $ent->offset, $ent->length );
				if ( 'bot_command' == $ent->type && '/' . $me->cmd === $cmd && empty( $me->chats[ $one->message->chat->id ] ) ) :
					$upd[ $one->message->chat->id ] = ( array ) $one->message->chat;
					$upd[ $one->message->chat->id ]['date'] = $one->message->date;
					$upd[ $one->message->chat->id ]['status'] = 'pending';
				endif;
			endforeach;
		
			if ( false === strpos( $one->message->text, 'cf7_start' ) ) continue;

		endforeach;
		
		$me->chats += $upd;
		$me->save_chats( $me->chats );

		sort( $update_ids, SORT_NUMERIC );
		$next_update = array_pop( $update_ids );
		update_option( 'wpcf7_telegram_last_update_id', ( int ) $next_update + 1 );

	}
	
	private function action_approve( $chat_id, & $new_status ){
		$new_status = 'active';
		if ( empty( $this->chats[ $chat_id ] ) ) return false;
		$this->chats[ $chat_id ]['status'] = $new_status;
		$this->save_chats( $this->chats );
		
		$this->api_request( 'sendMessage', array(
			'chat_id'					=> $chat_id,
			'text'						=> __( 'Subscribed for Contact Form 7 notifications from', WPCF7TG_DOMAIN ) . ' ' . home_url(),
			'disable_web_page_preview'	=> true,
		) );
		
		return true;
	}
	
	private function action_pause( $id, & $new_status ){
		$new_status = 'pending';
		if ( empty( $this->chats[ $id ] ) ) return false;
		$this->chats[ $id ]['status'] = $new_status;
		$this->save_chats( $this->chats );
		
		return true;
	}
	
	private function action_refuse( $id, & $new_status ){
		$new_status = 'deleted';
		unset( $this->chats[ $id ] );
		$this->save_chats( $this->chats );
		
		return true;
	}

	public function api_request( $method, $parameters = null, $headers = null ) {
		if ( ! is_string( $method ) ) :
			error_log( "[TELEGRAM] Method name must be a string\n" );
			return false;
		endif;

		if ( is_null( $parameters ) ):
			$parameters = array();
		endif;

		$url = $this->get_api_url() . $method;
		$args = array(
			'timeout'		=> 5,
			'redirection'	=> 5,
			'blocking'		=> true,
			'method'		=> 'POST',
			'body'			=> $parameters,
		);
		
		if ( ! empty( $headers ) )
		$args['headers'] = $headers;
		
		return $this->request( $url, $args );
	}

	private function request( $url, $args ) {
		$response = wp_remote_post( $url, $args );
		if ( is_wp_error( $response ) ) :
			error_log( "wp_remote_post returned error : ". $response->get_error_code() . ': ' . $response->get_error_message() . ' : ' . $response->get_error_data() ."\n");
			return false;
		endif;
		$http_code = intval( $response['response']['code'] );
		if ( $http_code >= 500 ) :
			// do not to DDOS server if something goes wrong
			error_log( "[TELEGRAM] Server return status {$http_code}" ."\n" );
			sleep( 3 );
			return false;
		elseif ( $http_code == 401 ) :
			//throw new Exception( 'Invalid access token provided' );
			error_log( "[TELEGRAM] Wrong token \n" );
			return json_decode( $response['body'] );
		elseif ( $http_code != 200 ) :
			error_log( "[TELEGRAM] Request has failed with error {$response['response']['code']}: {$response['response']['message']}\n" );
			return false;
		elseif ( empty( $response['body'] ) ) :
			error_log( "[TELEGRAM] Server return empty body" );
			return false;
		else :
			return json_decode( $response['body'] );
		endif;
	}
	
	public function has_token_constant(){
		return defined( 'WPFC7TG_BOT_TOKEN' );
	}
	
	private function has_token(){
		return ( $this->has_token_constant() || $this->get_bot_token() ) ;
	}
	
	private function get_template( $name ){
		$t['f_item'] =
		'<div class="wpcf7tg_notice notice-%1$s is-dismissible" data-chat="%2$d" status="%1$s" >
			<div class="info dashicons-before dashicons-%3$s">
				<span class="username">
					%4$s
				</span>
				<span class="nickname">
					%5$s
				</span>
				%6$s
			</div>
			<div class="buttons">
				<a class="approve" data-action="approve" ><span class="screen-reader-text">'. __( 'Approve', WPCF7TG_DOMAIN ) . '</span>'. __( 'Approve', WPCF7TG_DOMAIN ) . '</a>
				<a class="pause" data-action="pause" ><span class="screen-reader-text">'. __( 'Pause', WPCF7TG_DOMAIN ) . '</span>'. __( 'Pause', WPCF7TG_DOMAIN ) . '</a>
				<a class="refuse" data-action="refuse" ><span class="screen-reader-text">'. __( 'Delete', WPCF7TG_DOMAIN ) . '</span>'. __( 'Delete', WPCF7TG_DOMAIN ) . '</a>
			</div>
		</div>';
		
		return $t[ $name ];
	}
	
	function ajax(){
		$me = self::get_instance();
		check_ajax_referer( 'wpcf7_telegram_nonce' );
		$chat_id = @ $_POST['chat'];
		if ( empty( $chat_id ) ) wp_die( json_encode( new \WP_Error( 'empty_chat_id', 'There is no chat_id in request', array( 'status' => 400 ) ) ) );
		
		$action = 'action_' . @ $_POST['do_action'];
		if ( ! method_exists( $me, $action ) ) wp_die( json_encode( new \WP_Error( 'wrong_action', 'There is no correct action in request', array( 'status' => 400 ) ) ) );
		
		$new_status = '';
		echo json_encode( array( 'result' => $me->$action( $chat_id, $new_status ), 'chat' => $chat_id, 'new_status' => $new_status ) );
		wp_die();
	}
	
	function view_addonds(){
		
		foreach ( $this->addons as $slug => $name ) :
			echo class_exists( $slug ) ?
				'<p>' . __( 'Uses addon:', WPCF7TG_DOMAIN ) . ' ' . $name . '</p>' :
				'<p><a href="https://nebster.net/product/contact-form-7-telegram-attachments/" target="_blank" >' . __( 'File sending add-on is available', WPCF7TG_DOMAIN ) . '</a>. ' .  __( 'Sale 75% until Dec, 31, 2020', WPCF7TG_DOMAIN ) . '</p>';
		endforeach;
	}
}
