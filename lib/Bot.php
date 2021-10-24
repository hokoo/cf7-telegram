<?php

namespace iTRON\cf7Telegram;

use iTRON\wpPostAble\wpPostAble;
use iTRON\wpPostAble\wpPostAbleTrait;
use Longman\TelegramBot\Telegram;

class Bot extends Entity implements wpPostAble{
	use WPPostAbleTrait;
}
