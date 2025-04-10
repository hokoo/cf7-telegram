/* global cf7TelegramData */

import React, { useState, useEffect } from 'react';
import BotView from './BotView';
import {
    connectChat2Channel,
    disconnectConnectionBot2Chat,
    setBot2ChatRelationStatus
} from "../utils/main";
import {
    apiDeleteBot,
    apiPingBot,
    apiSaveBot
} from "../utils/api";

const Bot = ({
    bot,
    chats,
    bot2ChatConnections,
    setBots,
    setBot2ChatConnections,
    bot2ChannelRelations,
    setChat2ChannelRelations
}) => {
    const [isEditingToken, setIsEditingToken] = useState(false);
    const [nameValue, setNameValue] = useState(bot.title.rendered);
    const [tokenValue, setTokenValue] = useState(bot.token);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);
    const [updatingStatusIds, setUpdatingStatusIds] = useState([]);
    const [online, setOnline] = useState(null);

    const relatedChatIds = bot2ChatConnections
        .filter(relation => relation.data.from === bot.id)
        .map(relation => relation.data.to);

    const chatsForBot = chats.filter(chat => relatedChatIds.includes(chat.id));

    useEffect(() => {
        // @todo recheck when the bot is not online.
        if (online === null) {
            pingBot();
        }
    }, [online]);

    const handleEditToken = () => {
        setError(null);
        setIsEditingToken(true);
    };

    const cancelEdit = () => {
        setTokenValue(bot.token);
        setIsEditingToken(false);
        setError(null);
    };

    const pingBot = async () => {
        try {
            let pingedBot = await apiPingBot( bot.id );

            setOnline(pingedBot.online);
            if (pingedBot.botName) {
                setNameValue(pingedBot.botName);
                setBots(prev => prev.map(b => (
                    b.id === bot.id ? { ...b, title: { ...b.title, rendered: pingedBot.botName } } : b
                )));
            }
        } catch (err) {
            console.error('Ping failed', err);
            setOnline(false);
        }
    };

    const saveBot = async () => {
        setSaving(true);
        setError(null);

        try {
            const response = await apiSaveBot(bot.id, nameValue, tokenValue)

            if (!response) return;

            setBots(prev => prev.map(b => (
                b.id === bot.id ? { ...b, title: { ...b.title, rendered: nameValue }, token: tokenValue } : b
            )));

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
            const response = await apiDeleteBot(bot.id)

            if (!response) return;

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
        const relationIndex = bot2ChatConnections.findIndex(rel => rel.data.from === bot.id && rel.data.to === chatId);
        if (relationIndex === -1) return;

        const relation = bot2ChatConnections[relationIndex];

        let newStatus;
        if (currentStatus === 'active') newStatus = 'muted';
        else if (currentStatus === 'muted') newStatus = 'active';
        else if (currentStatus === 'pending') newStatus = 'active';
        else return;

        setUpdatingStatusIds(prev => [...prev, chatId]);

        try {
            let res = setBot2ChatRelationStatus(relation.data.id, newStatus, setBot2ChatConnections);

            // If the status was 'pending', we need to connect the chat to the all channels this bot is connected to.
            if (res && currentStatus === 'pending') {
                const channels = bot2ChannelRelations.filter(rel => rel.data.from === bot.id);
                for (const channel of channels) {
                    await connectChat2Channel(chatId, channel.data.to, setChat2ChannelRelations)
                }
            }

        } catch (err) {
            console.error('Failed to update chat status', err);
        } finally {
            setUpdatingStatusIds(prev => prev.filter(id => id !== chatId));
        }
    };

    const handleDisconnectChat = async (chatId, botID) => {
        const relationIndex = bot2ChatConnections.findIndex(rel => rel.data.from === botID && rel.data.to === chatId);
        if (
            relationIndex === -1 ||
            ! window.confirm('Are you sure you want to delete this chat?')
        ) return;

        const connection = bot2ChatConnections[relationIndex];

        setUpdatingStatusIds(prev => [...prev, chatId]);

        try {
            await disconnectConnectionBot2Chat(connection.data.id, setBot2ChatConnections)
        } catch (err) {
            console.error('Something went wrong while disconnecting chat', err);
        } finally {
            setUpdatingStatusIds(prev => prev.filter(id => id !== chatId));
        }
    }

    const handleTokenChange = (e) => {
        setTokenValue(e.target.value);
    };

    // Trimmed token for display (only last 4 characters)
    const trimmedToken = tokenValue.length > 7 ? `***${tokenValue.slice(-4)}` : tokenValue;

    return (
        <BotView
            bot={bot}
            chatsForBot={chatsForBot}
            bot2ChatConnections={bot2ChatConnections}
            updatingStatusIds={updatingStatusIds}
            isEditingToken={isEditingToken}
            nameValue={nameValue}
            tokenValue={tokenValue}
            trimmedToken={trimmedToken}
            saving={saving}
            error={error}
            handleEditToken={handleEditToken}
            deleteBot={deleteBot}
            handleKeyDown={handleKeyDown}
            setTokenValue={handleTokenChange}
            handleToggleChatStatus={handleToggleChatStatus}
            handleDisconnectChat={handleDisconnectChat}
            online={online}
        />
    );
};

export default Bot;