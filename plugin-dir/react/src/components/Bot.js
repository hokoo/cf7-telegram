/* global cf7TelegramData, wp */

import React, {useState, useEffect, useRef} from 'react';
import BotView from './BotView';
import {
    connectChat2Channel, disconnectConnectionBot2Channel, disconnectConnectionBot2Chat, setBot2ChatConnectionStatus
} from "../utils/main";
import {
    apiDeleteBot, apiFetchUpdates, apiPingBot, apiSaveBot, fetchBot
} from "../utils/api";

const Bot = ({
    bot,
    chats,
    bot2ChatConnections,
    setBots,
    setBot2ChatConnections,
    bot2ChannelConnections,
    setBot2ChannelConnections,
    setChat2ChannelConnections,
    loadBot2ChatConnections,
    loadChats
}) => {
    const [isEditingToken, setIsEditingToken] = useState(false);
    const [nameValue, setNameValue] = useState(bot.title.rendered);
    const [tokenValue, setTokenValue] = useState(bot.token);
    const [isTokenEmpty, setIsTokenEmpty] = useState(bot.isTokenEmpty);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);
    const [updatingStatusIds, setUpdatingStatusIds] = useState([]);
    const renderEditTokenCount = useRef(0);

    const relatedChatIds = bot2ChatConnections
        .filter(connection => connection.data.from === bot.id)
        .map(connection => connection.data.to);

    const chatsForBot = chats.filter(chat => relatedChatIds.includes(chat.id));

    const [lastPing, setLastPing] = useState(null);
    const [online, setOnline] = useState(null);
    const pingTimeoutRef = useRef(null);
    const updatesTimeoutRef = useRef(null);
    const isUnmountedRef = useRef(false);
    const isFetchingRef = useRef(false);

    useEffect(() => {
        return () => {
            isUnmountedRef.current = true;
            pingTimeoutRef.current || clearTimeout(pingTimeoutRef.current);
            updatesTimeoutRef.current || clearTimeout(updatesTimeoutRef.current);
        };
    }, []);

    useEffect(() => {
        if (online === null) {
            pingBot();
        }
    }, [lastPing]);

    useEffect(() => {
        if (online === false) {
            scheduleNextPing();
        }

        return () => {
            pingTimeoutRef.current || clearTimeout(pingTimeoutRef.current);
        };
    }, [lastPing]);

    useEffect(() => {
        if (online === true) {
            pingTimeoutRef.current || clearTimeout(pingTimeoutRef.current);
        }
    }, [lastPing]);

    // Fetch updates when the bot is online only.
    useEffect(() => {
        if (online === true) {
            updatesTimeoutRef.current || handleFetchUpdates().then( () => {
                    scheduleNextFetch();
                }
            );

        } else if (updatesTimeoutRef.current) {
            // Clear the updates interval if it is already running.
            clearTimeout(updatesTimeoutRef.current);
            updatesTimeoutRef.current = null;
        }

        return () => {
            if (updatesTimeoutRef.current) clearTimeout(updatesTimeoutRef.current);
        };
    }, [online]);

    const scheduleNextPing = () => {
        pingTimeoutRef.current = setTimeout( () => {
            pingBot()
        }, cf7TelegramData.intervals.ping);
    };

    const scheduleNextFetch = () => {
        updatesTimeoutRef.current = setTimeout(async () => {
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
            // Skip if the bot token is editing now.
            if (isEditingToken) {
                // Throw an error so that the next ping will be scheduled.
                throw new Error('Token is being edited');
            }

            // Skip if the bot is already online.
            if (online === true) {
                return;
            }

            let pingedBot = await apiPingBot(bot.id);

            if (isUnmountedRef.current) {
                return;
            }

            setOnline(pingedBot.online);

            if (pingedBot.online) {
                setNameValue(pingedBot.botName);

                let fetched = await fetchBot(bot.id);

                // No need check bot name since it automatically updates by backend.

                setBots(prev => prev.map(b => (
                    b.id === bot.id ? {
                        ...b,
                        title: fetched.title,
                        online: true
                    } : b
                )));
            }
        } catch (err) {
            console.error('Ping failed', err);
            if (!isUnmountedRef.current) {
                setOnline(false);
            }
        } finally {
            setLastPing(Date.now());
        }
    };


    const handleEditToken = () => {
        if ( bot.isTokenDefinedByConst ) {
            return;
        }

        if ( online && ! window.confirm( wp.i18n.__( 'Changing the bot token will disconnect all its chats and channels. Continue?', 'cf7-telegram' ) ) ) {
            return;
        }

        setError(null);
        setIsEditingToken(true);
        renderEditTokenCount.current = 0;
    };

    const cancelEdit = () => {
        setTokenValue(bot.token);
        setIsTokenEmpty(bot.isTokenEmpty);
        setIsEditingToken(false);
        setError(null);
    };

    const handleKeyDown = (e) => {
        if (e.key === 'Enter') {
            if ( '' === tokenValue.trim() ) {
                cancelEdit();
                return
            }

            saveBotToken();
        }
        if (e.key === 'Escape') cancelEdit();
    };

    /**
     * Saves the bot with the new token and name.
     * ATTENTION! This will disconnect all chats and channels connected to the bot.
     *
     * @returns {Promise<void>}
     */
    const saveBotToken = async () => {
        setSaving(true);
        setError(null);

        try {
            await apiSaveBot(bot.id, '', tokenValue.trim())

            setIsTokenEmpty(false);
            setIsEditingToken(false);

            await pingBot();

        } catch (err) {
            console.error(err);
            setError(wp.i18n.__( 'Failed to update bot', 'cf7-telegram' ));
        } finally {
            // Disconnect all chats.
            let connections = bot2ChatConnections.filter(c => c.data.from === bot.id);
            for (const connection of connections) {
                await disconnectConnectionBot2Chat(connection.data.id, setBot2ChatConnections);
            }

            // Disconnect all channels.
            connections = bot2ChannelConnections.filter(c => c.data.from === bot.id);
            for (const connection of connections) {
                await disconnectConnectionBot2Channel(connection.data.id, setBot2ChannelConnections);
            }

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
            setError(wp.i18n.__( 'Failed to delete bot', 'cf7-telegram' ));
        } finally {
            setSaving(false);
        }
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
        if (connectionIndex === -1 || !window.confirm( wp.i18n.__( 'Are you sure you want to delete this chat?', 'cf7-telegram' )) ) return;

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

    return (<BotView
        bot={bot}
        chatsForBot={chatsForBot}
        bot2ChatConnections={bot2ChatConnections}
        updatingStatusIds={updatingStatusIds}
        isEditingToken={isEditingToken}
        nameValue={nameValue}
        isTokenEmpty={isTokenEmpty}
        tokenValue={tokenValue}
        saving={saving}
        error={error}
        handleEditToken={handleEditToken}
        deleteBot={deleteBot}
        handleKeyDown={handleKeyDown}
        setTokenValue={setTokenValue}
        handleToggleChatStatus={handleToggleChatStatus}
        handleDisconnectChat={handleDisconnectChat}
        online={online}
        renderEditTokenCount={renderEditTokenCount}
    />);
};

export default Bot;
