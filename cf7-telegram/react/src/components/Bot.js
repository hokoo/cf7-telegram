/* global cf7TelegramData */

import React from 'react';
import BotView from './BotView';

const Bot = ({ bot, chats, botsChatRelations }) => {
    const relatedChatIds = botsChatRelations
        .filter(relation => relation.data.from === bot.id)
        .map(relation => relation.data.to);

    const chatsForBot = chats.filter(chat => relatedChatIds.includes(chat.id));

    return <BotView bot={bot} chatsForBot={chatsForBot} />;
};

export default Bot;
