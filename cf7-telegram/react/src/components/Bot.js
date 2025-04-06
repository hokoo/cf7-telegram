/* global cf7TelegramData */

import React, { useState, useEffect } from 'react';
import BotView from './BotView';
import { getChatStatus } from '../utils/chatStatus';

const Bot = ({ bot, chats, botsChatRelations, setBots, setBotsChatRelations }) => {
    const [isEditingName, setIsEditingName] = useState(false);
    const [isEditingToken, setIsEditingToken] = useState(false);
    const [nameValue, setNameValue] = useState(bot.title.rendered);
    const [tokenValue, setTokenValue] = useState(bot.token);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);
    const [updatingStatusIds, setUpdatingStatusIds] = useState([]);
    const [online, setOnline] = useState(null);

    const relatedChatIds = botsChatRelations
        .filter(relation => relation.data.from === bot.id)
        .map(relation => relation.data.to);

    const chatsForBot = chats.filter(chat => relatedChatIds.includes(chat.id));

    useEffect(() => {
        if (online === null) {
            pingBot();
        }
    }, [online]);

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

    const pingBot = async () => {
        try {
            const res = await fetch(`${cf7TelegramData.routes.bots}${bot.id}/ping`, {
                method: 'GET',
                headers: {
                    'X-WP-Nonce': cf7TelegramData?.nonce,
                }
            });
            if (!res.ok) throw new Error('Ping failed');
            const json = await res.json();
            setOnline(json.online);
        } catch (err) {
            console.error('Ping failed', err);
            setOnline(false);
        }
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

            setBots(prev => prev.map(b => (
                b.id === bot.id ? { ...b, title: { ...b.title, rendered: nameValue }, token: tokenValue } : b
            )));

            setIsEditingName(false);
            setIsEditingToken(false);

            await pingBot();

        } catch (err) {
            console.error(err);
            setError('Failed to update bot');
        } finally {
            setSaving(false);
        }
    };

    const deleteBot = async () => {
        if (!window.confirm('Are you sure you want to delete this bot?')) return;

        setSaving(true);
        setError(null);

        try {
            const response = await fetch(`${cf7TelegramData.routes.bots}${bot.id}/?force=true`, {
                method: 'DELETE',
                headers: {
                    'X-WP-Nonce': cf7TelegramData?.nonce,
                }
            });

            if (!response.ok) throw new Error('Failed to delete bot');

            setBots(prev => prev.filter(b => b.id !== bot.id));
        } catch (err) {
            console.error(err);
            setError('Failed to delete bot');
        } finally {
            setSaving(false);
        }
    };

    const handleKeyDown = (e) => {
        if (e.key === 'Enter') saveBot();
        if (e.key === 'Escape') cancelEdit();
    };

    const handleToggleChatStatus = async (chatId, currentStatus) => {
        const relationIndex = botsChatRelations.findIndex(rel => rel.data.from === bot.id && rel.data.to === chatId);
        if (relationIndex === -1) return;

        const relation = botsChatRelations[relationIndex];

        let newStatus;
        if (currentStatus === 'active') newStatus = 'muted';
        else if (currentStatus === 'muted') newStatus = 'active';
        else if (currentStatus === 'pending') newStatus = 'active';
        else return;

        setUpdatingStatusIds(prev => [...prev, chatId]);

        try {
            const response = await fetch(`${cf7TelegramData.routes.relations.bot2chat}${relation.data.id}/meta`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': cf7TelegramData?.nonce,
                },
                body: JSON.stringify({
                    meta: [{ key: 'status', value: newStatus }]
                })
            });

            if (response.ok) {
                const updatedRelations = [...botsChatRelations];
                updatedRelations[relationIndex] = {
                    ...relation,
                    data: {
                        ...relation.data,
                        meta: {
                            ...relation.data.meta,
                            status: [newStatus]
                        }
                    }
                };
                setBotsChatRelations(updatedRelations);
            } else {
                console.error('Failed to update chat status');
            }
        } catch (err) {
            console.error('Failed to update chat status', err);
        } finally {
            setUpdatingStatusIds(prev => prev.filter(id => id !== chatId));
        }
    };

    const handleTokenChange = (e) => {
        setTokenValue(e.target.value);
    };

    // Trimmed token for display (only last 4 characters)
    const trimmedToken = tokenValue.length > 7 ? `***${tokenValue.slice(-4)}` : tokenValue;

    return (
        <BotView
            bot={bot}
            chatsForBot={chatsForBot}
            botsChatRelations={botsChatRelations}
            updatingStatusIds={updatingStatusIds}
            isEditingName={isEditingName}
            isEditingToken={isEditingToken}
            nameValue={nameValue}
            tokenValue={tokenValue}
            trimmedToken={trimmedToken}
            saving={saving}
            error={error}
            handleEditName={handleEditName}
            handleEditToken={handleEditToken}
            cancelEdit={cancelEdit}
            saveBot={saveBot}
            deleteBot={deleteBot}
            handleKeyDown={handleKeyDown}
            setNameValue={setNameValue}
            setTokenValue={setTokenValue}
            handleToggleChatStatus={handleToggleChatStatus}
            online={online}
        />
    );
};

export default Bot;