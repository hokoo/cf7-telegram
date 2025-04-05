/* global cf7TelegramData */

export const fetchClient = async () => {
    const response = await fetch(cf7TelegramData.routes.client, {
        method: 'GET',
        headers: { 'X-WP-Nonce': cf7TelegramData?.nonce },
    });
    return await response.json();
};

export const fetchForms = async () => {
    const response = await fetch(cf7TelegramData.routes.forms, {
        method: 'GET',
        headers: { 'X-WP-Nonce': cf7TelegramData?.nonce },
    });
    return await response.json();
};

export const fetchBots = async () => {
    const response = await fetch(cf7TelegramData.routes.bots, {
        method: 'GET',
        headers: { 'X-WP-Nonce': cf7TelegramData?.nonce },
    });
    return await response.json();
};

export const fetchChats = async () => {
    const response = await fetch(cf7TelegramData.routes.chats, {
        method: 'GET',
        headers: { 'X-WP-Nonce': cf7TelegramData?.nonce },
    });
    return await response.json();
};

export const fetchChannels = async () => {
    const response = await fetch(cf7TelegramData.routes.channels, {
        method: 'GET',
        headers: { 'X-WP-Nonce': cf7TelegramData?.nonce },
    });
    return await response.json();
};

export const fetchFormsForChannels = async () => {
    const response = await fetch(cf7TelegramData.routes.relations.form2channel, {
        method: 'GET',
        headers: { 'X-WP-Nonce': cf7TelegramData?.nonce },
    });
    return await response.json();
};

export const fetchBotsForChannels = async () => {
    const response = await fetch(cf7TelegramData.routes.relations.bot2channel, {
        method: 'GET',
        headers: { 'X-WP-Nonce': cf7TelegramData?.nonce },
    });
    return await response.json();
};

export const fetchBotsForChats = async () => {
    const response = await fetch(cf7TelegramData.routes.relations.bot2chat, {
        method: 'GET',
        headers: { 'X-WP-Nonce': cf7TelegramData?.nonce },
    });
    return await response.json();
};

export const fetchChatsForChannels = async () => {
    const response = await fetch(cf7TelegramData.routes.relations.chat2channel, {
        method: 'GET',
        headers: { 'X-WP-Nonce': cf7TelegramData?.nonce },
    });
    return await response.json();
};
