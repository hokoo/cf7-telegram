/* global cf7TelegramData */

import React, {useState, useEffect, useRef} from 'react';
import BotView from './BotView';
import {
    connectChat2Channel, disconnectConnectionBot2Chat, setBot2ChatConnectionStatus
} from "../utils/main";
import {
    apiDeleteBot, apiFetchUpdates, apiPingBot, apiSaveBot
} from "../utils/api";

const Bot = ({
    bot,
    chats,
    bot2ChatConnections,
    setBots,
    setBot2ChatConnections,
    bot2ChannelConnections,
    setChat2ChannelConnections,
    loadBot2ChatConnections,
    loadChats
}) => {
    const [isEditingToken, setIsEditingToken] = useState(false);
    const [nameValue, setNameValue] = useState(bot.title.rendered);
    const [tokenValue, setTokenValue] = useState(bot.token);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);
    const [updatingStatusIds, setUpdatingStatusIds] = useState([]);

    const relatedChatIds = bot2ChatConnections
        .filter(connection => connection.data.from === bot.id)
        .map(connection => connection.data.to);

    const chatsForBot = chats.filter(chat => relatedChatIds.includes(chat.id));

    const [online, setOnline] = useState(null);
    const pingTimeoutRef = useRef(null);
    const updatesIntervalRef = useRef(null);
    const isUnmountedRef = useRef(false);
    const isFetchingRef = useRef(false);


    useEffect(() => {
        return () => {
            isUnmountedRef.current = true;
            if (pingTimeoutRef.current) clearTimeout(pingTimeoutRef.current);
            if (updatesIntervalRef.current) clearTimeout(updatesIntervalRef.current);
        };
    }, []);

    useEffect(() => {
        if (online === null) {
            pingBot();
        }
    }, [online]);

    useEffect(() => {
        if (online === false && !pingTimeoutRef.current) {
            scheduleNextPing();
        }

        return () => {
            if (pingTimeoutRef.current) clearTimeout(pingTimeoutRef.current);
        };
    }, [online]);

    // Fetch updates when the bot is online only.
    useEffect(() => {
        if (online === true) {
            updatesIntervalRef.current || handleFetchUpdates().then( () => {
                    scheduleNextFetch();
                }
            );

        } else if (updatesIntervalRef.current) {
            // Clear the updates interval if it is already running.
            clearTimeout(updatesIntervalRef.current);
            updatesIntervalRef.current = null;
        }

        return () => {
            if (updatesIntervalRef.current) clearTimeout(updatesIntervalRef.current);
        };
    }, [online]);

    const scheduleNextPing = () => {
        pingTimeoutRef.current = setTimeout(pingBot, cf7TelegramData.intervals.ping);
    };

    const scheduleNextFetch = () => {
        updatesIntervalRef.current = setTimeout(async () => {
            await handleFetchUpdates();

            if (!isUnmountedRef.current && online === true) {
                scheduleNextFetch();
            }
        }, cf7TelegramData.intervals.bot_fetch);
    }

    const handleFetchUpdates = async () => {
        if (isFetchingRef.current) return;

        isFetchingRef.current = true;
        try {
            let updates = await apiFetchUpdates(bot.id);

            if (updates.hasNewConnections) {
                // This means that new or an existing chat has been connected to another bot.
                // Anyway, fetch bot2Chat connections first.
                loadBot2ChatConnections();
            }

            if (updates.hasNewChats) {
                // If there are new chats, we need to fetch the chats again.
                loadChats();
            }
        } catch (err) {
            console.error('Fetch updates failed', err);
        } finally {
            isFetchingRef.current = false;
        }
    }

    const pingBot = async () => {
        try {
            const pingedBot = await apiPingBot(bot.id);

            if (isUnmountedRef.current) return;

            setOnline(pingedBot.online);

            if (pingedBot.botName) {
                setNameValue(pingedBot.botName);
                setBots(prev => prev.map(b => (
                    b.id === bot.id ? {
                        ...b,
                        title: { ...b.title, rendered: pingedBot.botName }
                    } : b
                )));
            }
        } catch (err) {
            console.error('Ping failed', err);
            if (!isUnmountedRef.current) {
                setOnline(false);
            }
        }
    };


    const handleEditToken = () => {
        setError(null);
        setIsEditingToken(true);
    };

    const cancelEdit = () => {
        setTokenValue(bot.token);
        setIsEditingToken(false);
        setError(null);
    };

    const saveBot = async () => {
        setSaving(true);
        setError(null);

        try {
            const response = await apiSaveBot(bot.id, nameValue, tokenValue)

            if (!response) return;

            setBots(prev => prev.map(b => (b.id === bot.id ? {
                ...b, title: {...b.title, rendered: nameValue}, token: tokenValue
            } : b)));

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
        const connectionIndex = bot2ChatConnections.findIndex(c => c.data.from === bot.id && c.data.to === chatId);
        if (connectionIndex === -1) return;

        const connection = bot2ChatConnections[connectionIndex];

        let newStatus;
        if (currentStatus === 'active') {
            newStatus = 'muted'
        } else if (currentStatus === 'muted') {
            newStatus = 'active';
        } else if (currentStatus === 'pending') {
            newStatus = 'active';
        } else return;

        setUpdatingStatusIds(prev => [...prev, chatId]);

        try {
            let res = setBot2ChatConnectionStatus(connection.data.id, newStatus, setBot2ChatConnections);

            // If the status was 'pending', we need to connect the chat to the all channels this bot is connected to.
            if (currentStatus === 'pending') {
                const channels = bot2ChannelConnections.filter(c => c.data.from === bot.id);
                for (const channel of channels) {
                    await connectChat2Channel(chatId, channel.data.to, setChat2ChannelConnections)
                }
            }

        } catch (err) {
            console.error('Failed to update chat status', err);
        } finally {
            setUpdatingStatusIds(prev => prev.filter(id => id !== chatId));
        }
    };

    const handleDisconnectChat = async (chatId, botID) => {
        const connectionIndex = bot2ChatConnections.findIndex(c => c.data.from === botID && c.data.to === chatId);
        if (connectionIndex === -1 || !window.confirm('Are you sure you want to delete this chat?')) return;

        const connection = bot2ChatConnections[connectionIndex];

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

    return (<BotView
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
    />);
};

export default Bot;
