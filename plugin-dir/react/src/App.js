/* global cf7TelegramData, wp */

import React, {useState, useEffect, useCallback} from 'react';
import Channel from './components/Channel';
import Bot from './components/Bot';
import NewBot from './components/NewBot';
import NewChannel from './components/NewChannel';

import {
    fetchClient,
    fetchForms,
    fetchBots,
    fetchChats,
    fetchChannels,
    fetchFormsForChannels,
    fetchBotsForChannels,
    fetchBotsForChats,
    fetchChatsForChannels,
} from './utils/api';

const mapBot2ChatConnections = (connections) => connections.map(rel => {
    const status = rel.data?.meta?.status?.[0];
    return {
        ...rel,
        data: {
            ...rel.data,
            muted: status === 'muted'
        }
    };
});

const App = () => {
    const [client, setClient] = useState([]);
    const [forms, setForms] = useState([]);
    const [bots, setBots] = useState([]);
    const [chats, setChats] = useState([]);
    const [channels, setChannels] = useState([]);
    const [form2ChannelConnections, setForm2ChannelConnections] = useState([]);
    const [bot2ChannelConnections, setBot2ChannelConnections] = useState([]);
    const [chat2ChannelConnections, setChat2ChannelConnections] = useState([]);
    const [bot2ChatConnections, setBot2ChatConnections] = useState([]);
    const [loading, setLoading] = useState(true);

    const loadChatData = useCallback(async () => {
        const [connections, loadedChats] = await Promise.all([
            fetchBotsForChats(),
            fetchChats()
        ]);

        setBot2ChatConnections(mapBot2ChatConnections(connections));
        setChats(loadedChats);
    }, []);

    // Run once when the component mounts.
    useEffect(() => {
        fetchClient().then(setClient);
        fetchForms().then(setForms);
        fetchBots().then(setBots);

        loadChatData();

        fetchFormsForChannels().then(setForm2ChannelConnections);
        fetchBotsForChannels().then(setBot2ChannelConnections);
        fetchChatsForChannels().then(setChat2ChannelConnections);
    }, [loadChatData]);

    useEffect(() => {
        fetchChannels()
            .then(data => {
                setChannels(data);
                setLoading(false);
            })
            .catch(error => {
                console.error("Error fetching channels:", error);
                setLoading(false);
            });
    }, []);

    if (loading) return <div>{wp.i18n.__( 'Loading data...', 'cf7-telegram' )}</div>;

    const migrationStatus = cf7TelegramData?.migration?.status || {};
    const canShowMigrationAction = Boolean(cf7TelegramData?.migration?.show_action_button);
    const canRetryMigration = migrationStatus.can_retry !== false;
    const migrationError = migrationStatus.last_error?.message;

    return (
        <>
        <h1>{wp.i18n.__( 'Telegram notificator settings', 'cf7-telegram' )}</h1>
        <div className="cf7-tg-container">
            <div className="main-container">
                <div className="list-container bots-container">
                    <div className="title-container">
                        <h3 className="title">{wp.i18n.__( 'Bots', 'cf7-telegram' )}</h3>
                        <NewBot setBots={setBots}/>
                    </div>

                    <div className="bot-list">
                        {bots.map(bot => (
                            <Bot
                                key={bot.id}
                                bot={bot}
                                chats={chats}
                                bot2ChatConnections={bot2ChatConnections}
                                setBots={setBots}
                                setBot2ChatConnections={setBot2ChatConnections}
                                bot2ChannelConnections={bot2ChannelConnections}
                                setBot2ChannelConnections={setBot2ChannelConnections}
                                setChat2ChannelConnections={setChat2ChannelConnections}
                                loadChatData={loadChatData}
                            />
                        ))}
                    </div>
                </div>

                <div className="list-container channels-container">
                    <div className="title-container">
                        <h3 className="title">{wp.i18n.__( 'Channels', 'cf7-telegram' )}</h3>
                        <NewChannel setChannels={setChannels}/>
                    </div>
                    <div className="channel-list">
                        {channels.map(channel => (
                            <Channel
                                key={channel.id}
                                channel={channel}
                                forms={forms}
                                setChannels={setChannels}
                                form2ChannelConnections={form2ChannelConnections}
                                setForm2ChannelConnections={setForm2ChannelConnections}
                                bots={bots}
                                bot2ChannelConnections={bot2ChannelConnections}
                                setBot2ChannelConnections={setBot2ChannelConnections}
                                chats={chats}
                                chat2ChannelConnections={chat2ChannelConnections}
                                setChat2ChannelConnections={setChat2ChannelConnections}
                                bot2ChatConnections={bot2ChatConnections}
                            />
                        ))}
                    </div>
                </div>
            </div>
        </div>

        {canShowMigrationAction && (
            <div className="cf7-tg-migration-action">
                <form method="post" action={cf7TelegramData?.migration?.action_url}>
                    <input type="hidden" name="action" value="cf7tg_migration_action" />
                    <input
                        type="hidden"
                        name="cf7tg_migration_nonce"
                        value={cf7TelegramData?.migration?.nonce}
                    />
                    <p>
                        {migrationStatus.is_failed
                            ? wp.i18n.__( 'Data migration failed. You can retry it now.', 'cf7-telegram' )
                            : wp.i18n.__( 'We detected settings from an older version that couldn’t be migrated automatically. Click the button below to migrate them to the new version.', 'cf7-telegram' )}
                    </p>
                    {migrationError && (
                        <p className="cf7-tg-migration-error">
                            {migrationError}
                        </p>
                    )}
                    <button type="submit" className="button button-primary" disabled={!canRetryMigration}>
                        {migrationStatus.is_failed
                            ? wp.i18n.__( 'Retry migration', 'cf7-telegram' )
                            : wp.i18n.__( 'Run migration', 'cf7-telegram' )}
                    </button>
                </form>
            </div>
        )}

        <style>
            {`.copyable::after { content: '` + wp.i18n.__( 'Copied!', 'cf7-telegram' ) + `' !important }`}
        </style>
        </>
    );
};

export default App;
