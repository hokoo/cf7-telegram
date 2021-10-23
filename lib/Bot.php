<?php

namespace iTRON\cf7Telegram;

use iTRON\wpPostAble\wpPostAble;
use iTRON\wpPostAble\wpPostAbleTrait;
use Longman\TelegramBot\Telegram;

class Bot extends Telegram implements wpPostAble{
	use WPPostAbleTrait;
}
