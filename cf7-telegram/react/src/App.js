/* global cf7TelegramData */

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

    if (loading) return <div>Loading channels...</div>;
    if (channels.length === 0) return <div>No channels found</div>;

    return (<div className="cf7-tg-container">
            <div className="list-container bots-container">
                <div className="title-container">
                    <h3 className="title">Bots</h3>
                    <NewBot setBots={setBots}/>
                </div>

                <div className="bot-list">
                    {bots.map(bot => (
                        <Bot
                            bot={bot}
                            chats={chats}
                            bot2ChatConnections={bot2ChatConnections}
                            setBots={setBots}
                            setBot2ChatConnections={setBot2ChatConnections}
                            bot2ChannelConnections={bot2ChannelConnections}
                            setChat2ChannelConnections={setChat2ChannelConnections}
                            loadBot2ChatConnections={loadBot2ChatConnections}
                            loadChats={loadChats}
                        />
                    ))}
                </div>
            </div>

            <div className="list-container channels-container">
                <div className="title-container">
                    <h3 className="title">Channels</h3>
                    <NewChannel setChannels={setChannels}/>
                </div>
                <div className="channel-list">
                    {channels.map(channel => (
                        <Channel
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
        </div>);
};

export default App;
