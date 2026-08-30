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

const RESOURCE_NAMES = [
    'client',
    'forms',
    'bots',
    'chats',
    'channels',
    'form2ChannelConnections',
    'bot2ChannelConnections',
    'chat2ChannelConnections',
];

const createResourceStates = () => RESOURCE_NAMES.reduce((states, name) => ({
    ...states,
    [name]: {status: 'idle', error: null},
}), {});

export class SettingsErrorBoundary extends React.Component {
    constructor(props) {
        super(props);
        this.state = {hasError: false, retryKey: 0};
    }

    static getDerivedStateFromError() {
        return {hasError: true};
    }

    componentDidCatch() {
        console.error('CF7 Telegram settings failed to render.');
    }

    retry = () => {
        this.setState(state => ({hasError: false, retryKey: state.retryKey + 1}));
    };

    render() {
        if (this.state.hasError) {
            return (
                <div className="cf7-tg-error-boundary" role="alert">
                    <p>{wp.i18n.__( 'The settings screen could not be displayed.', 'cf7-telegram' )}</p>
                    <button type="button" className="button" onClick={this.retry}>
                        {wp.i18n.__( 'Try again', 'cf7-telegram' )}
                    </button>
                </div>
            );
        }

        return <React.Fragment key={this.state.retryKey}>{this.props.children}</React.Fragment>;
    }
}

const SettingsApp = () => {
    const [forms, setForms] = useState([]);
    const [bots, setBots] = useState([]);
    const [chats, setChats] = useState([]);
    const [channels, setChannels] = useState([]);
    const [form2ChannelConnections, setForm2ChannelConnections] = useState([]);
    const [bot2ChannelConnections, setBot2ChannelConnections] = useState([]);
    const [chat2ChannelConnections, setChat2ChannelConnections] = useState([]);
    const [bot2ChatConnections, setBot2ChatConnections] = useState([]);
    const [resources, setResources] = useState(createResourceStates);

    const loadResource = useCallback(async (name, loader, apply) => {
        setResources(previous => ({
            ...previous,
            [name]: {status: 'loading', error: null},
        }));

        try {
            const data = await loader();
            apply(data);
            setResources(previous => ({
                ...previous,
                [name]: {status: 'success', error: null},
            }));
            return data;
        } catch (error) {
            setResources(previous => ({
                ...previous,
                [name]: {status: 'error', error},
            }));
            throw error;
        }
    }, []);

    const loadChatData = useCallback(async () => {
        return loadResource(
            'chats',
            async () => {
                const [connections, loadedChats] = await Promise.all([
                    fetchBotsForChats(),
                    fetchChats()
                ]);

                return {connections, loadedChats};
            },
            ({connections, loadedChats}) => {
                setBot2ChatConnections(mapBot2ChatConnections(connections));
                setChats(loadedChats);
            }
        );
    }, [loadResource]);

    const loadResources = useCallback((names = RESOURCE_NAMES) => Promise.allSettled(
        names.map(name => {
            switch (name) {
                case 'client':
                    return loadResource(name, fetchClient, () => {});
                case 'forms':
                    return loadResource(name, fetchForms, setForms);
                case 'bots':
                    return loadResource(name, fetchBots, setBots);
                case 'chats':
                    return loadChatData();
                case 'channels':
                    return loadResource(name, fetchChannels, setChannels);
                case 'form2ChannelConnections':
                    return loadResource(name, fetchFormsForChannels, setForm2ChannelConnections);
                case 'bot2ChannelConnections':
                    return loadResource(name, fetchBotsForChannels, setBot2ChannelConnections);
                case 'chat2ChannelConnections':
                    return loadResource(name, fetchChatsForChannels, setChat2ChannelConnections);
                default:
                    return Promise.resolve();
            }
        })
    ), [loadChatData, loadResource]);

    // Run once when the component mounts.
    useEffect(() => {
        loadResources();
    }, [loadResources]);

    const resourceStatus = name => resources[name]?.status ?? 'idle';
    const resourceAvailability = (...names) => {
        if (names.some(name => 'error' === resourceStatus(name))) {
            return 'error';
        }

        return names.every(name => 'success' === resourceStatus(name)) ? 'ready' : 'loading';
    };
    const failedResources = RESOURCE_NAMES.filter(name => 'error' === resourceStatus(name));
    const hasSettledResource = RESOURCE_NAMES.some(name => ['success', 'error'].includes(resourceStatus(name)));

    if (!hasSettledResource) return <div>{wp.i18n.__( 'Loading data...', 'cf7-telegram' )}</div>;

    const migrationStatus = cf7TelegramData?.migration?.status || {};
    const canShowMigrationAction = Boolean(cf7TelegramData?.migration?.show_action_button);
    const canRetryMigration = migrationStatus.can_retry !== false;
    const migrationError = 'migration_failed' === migrationStatus.last_error?.category
        ? migrationStatus.last_error.message
        : null;

    return (
        <>
        <h1>{wp.i18n.__( 'Telegram notificator settings', 'cf7-telegram' )}</h1>
        {failedResources.length > 0 && (
            <div className="notice notice-error cf7t-notice cf7-tg-load-status" role="alert">
                <p>{wp.i18n.__( 'Some settings data could not be loaded.', 'cf7-telegram' )}</p>
                <button
                    type="button"
                    className="button"
                    onClick={() => loadResources(failedResources)}
                >
                    {wp.i18n.__( 'Retry failed requests', 'cf7-telegram' )}
                </button>
            </div>
        )}
        <div className="cf7-tg-container">
            <div className="main-container">
                <div className="list-container bots-container">
                    <div className="title-container">
                        <h3 className="title">{wp.i18n.__( 'Bots', 'cf7-telegram' )}</h3>
                        <NewBot setBots={setBots} disabled={'success' !== resourceStatus('bots')}/>
                    </div>

                    <div className="bot-list">
                        {'error' === resourceStatus('bots') && (
                            <p className="resource-error">{wp.i18n.__( 'Bots could not be loaded.', 'cf7-telegram' )}</p>
                        )}
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
                                chatDataStatus={resourceAvailability('chats')}
                            />
                        ))}
                    </div>
                </div>

                <div className="list-container channels-container">
                    <div className="title-container">
                        <h3 className="title">{wp.i18n.__( 'Channels', 'cf7-telegram' )}</h3>
                        <NewChannel setChannels={setChannels} disabled={'success' !== resourceStatus('channels')}/>
                    </div>
                    <div className="channel-list">
                        {'error' === resourceStatus('channels') && (
                            <p className="resource-error">{wp.i18n.__( 'Channels could not be loaded.', 'cf7-telegram' )}</p>
                        )}
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
                                dataAvailability={{
                                    forms: resourceAvailability('forms', 'form2ChannelConnections'),
                                    bots: resourceAvailability('bots', 'bot2ChannelConnections'),
                                    chats: resourceAvailability('chats', 'chat2ChannelConnections'),
                                }}
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

const App = () => (
    <SettingsErrorBoundary>
        <SettingsApp />
    </SettingsErrorBoundary>
);

export default App;
