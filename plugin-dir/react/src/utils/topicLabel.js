/* global wp */

export const getTopicLabel = (chat) =>
    chat.customName || chat.telegramTopicName || `${wp.i18n.__( 'Topic', 'cf7-telegram' )} #${chat.messageThreadID}`;
