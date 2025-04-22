/* global cf7TelegramData, wp */

import React, {useState, useEffect} from 'react';
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
    apiDeleteChat
} from './utils/api';

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

    // Run once when the component mounts.
    useEffect(() => {
        fetchClient().then(setClient);
        fetchForms().then(setForms);
        fetchBots().then(setBots);

        // That's crucial to load the bot2ChatConnections first so that chat-garbage-collector would not delete chats.
        loadBot2ChatConnections();
        loadChats();

        fetchFormsForChannels().then(setForm2ChannelConnections);
        fetchBotsForChannels().then(setBot2ChannelConnections);
        fetchChatsForChannels().then(setChat2ChannelConnections);
    }, []);

    const loadChats = () => {
        fetchChats().then(setChats);
    }

    const loadBot2ChatConnections = () => {
        fetchBotsForChats().then((connections) => {
            const mapped = connections.map(rel => {
                const status = rel.data?.meta?.status?.[0];
                return {
                    ...rel, data: {
                        ...rel.data, muted: status === 'muted'
                    }
                };
            });
            setBot2ChatConnections(mapped);
        });
    };

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

    // Chat-garbage collector. When chats has a chat that is not in bot2ChatConnections, destroy it.
    useEffect(() => {
        const chatIdsInBot2ChatConnections = bot2ChatConnections.map(rel => rel.data.to);
        const chatsToRemove = chats.filter(chat => !chatIdsInBot2ChatConnections.includes(chat.id));
        const chatIdsToRemove = chatsToRemove.map(chat => chat.id);

        if (chatIdsToRemove.length === 0) return;

        const deletePromises = chatIdsToRemove.map(chatId => apiDeleteChat(chatId));
        Promise.all(deletePromises)
            .then(() => {
                setChats(currentChats => currentChats.filter(chat => chatIdsInBot2ChatConnections.includes(chat.id)));
            })
            .catch(error => {
                console.error("Error deleting chats:", error);
            });
    }, [chats, bot2ChatConnections])

    if (loading) return <div>{wp.i18n.__( 'Loading data...', 'cf7-telegram' )}</div>;

    return (
        <>
        <h1>{wp.i18n.__( 'Telegram notificator settings', 'cf7-telegram' )}</h1>
        <div className="cf7-tg-container">
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
                            loadBot2ChatConnections={loadBot2ChatConnections}
                            loadChats={loadChats}
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

        <style>
            {`.copyable::after { content: '` + wp.i18n.__( 'Copied!', 'cf7-telegram' ) + `' !important }`}
        </style>
        </>
    );
};

export default App;
