/* global cf7TelegramData */

import React, { useState } from 'react';
import BotView from './BotView';

const Bot = ({ bot, chats, botsChatRelations, setBots }) => {
    const [isEditingName, setIsEditingName] = useState(false);
    const [isEditingToken, setIsEditingToken] = useState(false);
    const [nameValue, setNameValue] = useState(bot.title.rendered);
    const [tokenValue, setTokenValue] = useState(bot.token);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);

    const relatedChatIds = botsChatRelations
        .filter(relation => relation.data.from === bot.id)
        .map(relation => relation.data.to);

    const chatsForBot = chats.filter(chat => relatedChatIds.includes(chat.id));

    const handleEditName = () => {
        setError(null);
        setIsEditingName(true);
    };

    const handleEditToken = () => {
        setError(null);
        setIsEditingToken(true);
    };

    const cancelEdit = () => {
        setNameValue(bot.title.rendered);
        setTokenValue(bot.token);
        setIsEditingName(false);
        setIsEditingToken(false);
        setError(null);
    };

    const saveBot = async () => {
        setSaving(true);
        setError(null);

        try {
            const response = await fetch(`${cf7TelegramData.routes.bots}${bot.id}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': cf7TelegramData?.nonce,
                },
                body: JSON.stringify({
                    title: nameValue,
                    token: tokenValue,
                }),
            });

            if (!response.ok) throw new Error('Failed to update bot');

            // Обновляем глобальное состояние с ботами
            setBots(prev => prev.map(b => (
                b.id === bot.id ? { ...b, title: { ...b.title, rendered: nameValue }, token: tokenValue } : b
            )));

            setIsEditingName(false);
            setIsEditingToken(false);
        } catch (err) {
            console.error(err);
            setError('Failed to update bot');
        } finally {
            setSaving(false);
        }
    };

    const handleKeyDown = (e) => {
        if (e.key === 'Enter') saveBot();
        if (e.key === 'Escape') cancelEdit();
    };

    return (
        <BotView
            bot={bot}
            chatsForBot={chatsForBot}
            isEditingName={isEditingName}
            isEditingToken={isEditingToken}
            nameValue={nameValue}
            tokenValue={tokenValue}
            saving={saving}
            error={error}
            handleEditName={handleEditName}
            handleEditToken={handleEditToken}
            cancelEdit={cancelEdit}
            saveBot={saveBot}
            handleKeyDown={handleKeyDown}
            setNameValue={setNameValue}
            setTokenValue={setTokenValue}
        />
    );
};

export default Bot;
