/* global cf7TelegramData, wp */

import React, {useState, useEffect, useRef} from 'react';
import BotView from './BotView';
import {
    connectChat2Channel, disconnectConnectionBot2Chat, setBot2ChatConnectionStatus
} from "../utils/main";
import {
    apiDeleteBot, apiFetchUpdates, apiPingBot, apiUpdateBotToken, fetchBot
} from "../utils/api";

const UPDATE_TRANSPORT_ERROR_THRESHOLD = 3;

export const saveBotTokenTransactionally = async ({
    botId,
    token,
    pingBot,
    bot2ChatConnections,
    setBot2ChatConnections,
    bot2ChannelConnections,
    setBot2ChannelConnections,
}) => {
    const result = await apiUpdateBotToken(botId, token.trim());

    await pingBot({force: true, skipEditingCheck: true});

    if (result.relationsReset) {
        setBot2ChatConnections(previous => previous.filter(connection => connection.data.from !== botId));
        setBot2ChannelConnections(previous => previous.filter(connection => connection.data.from !== botId));
    }

    return result;
};

export const getUpdateDiagnostic = (updates) => {
    if (updates?.hasWebhookConflict) {
        return 'webhook_conflict';
    }

    const errors = Array.isArray(updates?.errors) ? updates.errors : [];
    if (errors.length && errors.every(error => 'transport' === error?.errorType)) {
        return 'transient_update_error';
    }

    if (errors.length) {
        return 'update_error';
    }

    return null;
};

export const updateBotChatStatus = async ({
    connectionId,
    newStatus,
    currentStatus,
    chatId,
    botId,
    bot2ChannelConnections,
    setBot2ChatConnections,
    setChat2ChannelConnections,
}) => {
    await setBot2ChatConnectionStatus(connectionId, newStatus, setBot2ChatConnections);

    if ('pending' !== currentStatus) {
        return;
    }

    const channels = bot2ChannelConnections.filter(connection => connection.data.from === botId);
    for (const channel of channels) {
        await connectChat2Channel(chatId, channel.data.to, setChat2ChannelConnections);
    }
};

const Bot = ({
    bot,
    chats,
    bot2ChatConnections,
    setBots,
    setBot2ChatConnections,
    bot2ChannelConnections,
    setBot2ChannelConnections,
    setChat2ChannelConnections,
    loadChatData,
    chatDataStatus = 'ready'
}) => {
    const [isEditingToken, setIsEditingToken] = useState(false);
    const [nameValue, setNameValue] = useState(bot.title.rendered);
    const [tokenValue, setTokenValue] = useState(bot.token);
    const [isTokenEmpty, setIsTokenEmpty] = useState(bot.isTokenEmpty);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);
    const [updatingStatusIds, setUpdatingStatusIds] = useState([]);

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
    const consecutiveTransportErrorsRef = useRef(0);

    useEffect(() => {
        return () => {
            isUnmountedRef.current = true;
            if (pingTimeoutRef.current) clearTimeout(pingTimeoutRef.current);
            if (updatesTimeoutRef.current) clearTimeout(updatesTimeoutRef.current);
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
            if (pingTimeoutRef.current) clearTimeout(pingTimeoutRef.current);
        };
    }, [lastPing]);

    useEffect(() => {
        if (online === true) {
            if (pingTimeoutRef.current) clearTimeout(pingTimeoutRef.current);
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

    const recordTransientUpdateError = () => {
        consecutiveTransportErrorsRef.current += 1;
        if (consecutiveTransportErrorsRef.current >= UPDATE_TRANSPORT_ERROR_THRESHOLD) {
            setError(wp.i18n.__( 'Telegram updates could not be checked.', 'cf7-telegram' ));
        }
    };

    const handleFetchUpdates = async () => {
        if (isFetchingRef.current) return;

        isFetchingRef.current = true;
        try {
            let updates = await apiFetchUpdates(bot.id);
            const diagnostic = getUpdateDiagnostic(updates);
            if ('webhook_conflict' === diagnostic) {
                consecutiveTransportErrorsRef.current = 0;
                setError(wp.i18n.__( 'Telegram webhook is active. Disable it before checking for new chats.', 'cf7-telegram' ));
                return;
            }

            if ('transient_update_error' === diagnostic) {
                recordTransientUpdateError();
                return;
            }

            if ('update_error' === diagnostic) {
                consecutiveTransportErrorsRef.current = 0;
                setError(wp.i18n.__( 'Telegram updates could not be checked.', 'cf7-telegram' ));
                return;
            }

            consecutiveTransportErrorsRef.current = 0;
            setError(null);
            if (updates.hasNewConnections || updates.hasNewChats) {
                await loadChatData();
            }
        } catch (err) {
            console.error('Fetch updates failed', err);
            if ('rest_transport' === err?.category) {
                recordTransientUpdateError();
            } else {
                consecutiveTransportErrorsRef.current = 0;
                setError(wp.i18n.__( 'Telegram updates could not be checked.', 'cf7-telegram' ));
            }
        } finally {
            isFetchingRef.current = false;
        }
    }

    const pingBot = async ({force = false, skipEditingCheck = false} = {}) => {
        try {
            // Skip if the bot token is editing now.
            if (!skipEditingCheck && isEditingToken) {
                // Throw an error so that the next ping will be scheduled.
                throw new Error('Token is being edited');
            }

            // Skip if the bot is already online.
            if (!force && online === true) {
                return true;
            }

            let pingedBot = await apiPingBot(bot.id);

            if (isUnmountedRef.current) {
                return pingedBot.online;
            }

            setOnline(pingedBot.online);

            if (pingedBot.online) {
                setNameValue(pingedBot.botName);

                try {
                    let fetched = await fetchBot(bot.id);

                    // No need check bot name since it automatically updates by backend.
                    setBots(prev => prev.map(b => (
                        b.id === bot.id ? {
                            ...b,
                            title: fetched.title,
                            online: true
                        } : b
                    )));
                } catch (err) {
                    console.error('Failed to refresh bot data', err);
                }
            }

            return pingedBot.online;
        } catch (err) {
            console.error('Ping failed', err);
            if (!isUnmountedRef.current) {
                setOnline(false);
            }
            return false;
        } finally {
            setLastPing(Date.now());
        }
    };


    const handleEditToken = () => {
        if ( bot.isTokenDefinedByConst ) {
            return;
        }

        if ( online && ! window.confirm( wp.i18n.__( 'Changing the token to another Telegram bot will disconnect this bot from its chats and bridges. Continue?', 'cf7-telegram' ) ) ) {
            return;
        }

        setError(null);
        setTokenValue('');
        setIsEditingToken(true);
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
     * ATTENTION! This will disconnect all chats and bridges connected to the bot.
     *
     * @returns {Promise<void>}
     */
    const saveBotToken = async () => {
        setSaving(true);
        setError(null);

        try {
            const nextToken = tokenValue.trim();
            await saveBotTokenTransactionally({
                botId: bot.id,
                token: nextToken,
                pingBot,
                bot2ChatConnections,
                setBot2ChatConnections,
                bot2ChannelConnections,
                setBot2ChannelConnections,
            });

            setTokenValue(nextToken);
            setIsTokenEmpty(false);
            setIsEditingToken(false);
        } catch (err) {
            console.error(err);
            setError(wp.i18n.__( 'Failed to update bot', 'cf7-telegram' ));
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
            setError(wp.i18n.__( 'Failed to delete bot', 'cf7-telegram' ));
        } finally {
            setSaving(false);
        }
    };

    const handleToggleChatStatus = async (chatId, currentStatus) => {
        if (updatingStatusIds.includes(chatId)) return;

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
            await updateBotChatStatus({
                connectionId: connection.data.id,
                newStatus,
                currentStatus,
                chatId,
                botId: bot.id,
                bot2ChannelConnections,
                setBot2ChatConnections,
                setChat2ChannelConnections,
            });

        } catch (err) {
            console.error('Failed to update chat status', err);
        } finally {
            setUpdatingStatusIds(prev => prev.filter(id => id !== chatId));
        }
    };

    const handleDisconnectChat = async (chatId, botID) => {
        if (updatingStatusIds.includes(chatId)) return;

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
        chatDataStatus={chatDataStatus}
    />);
};

export default Bot;
