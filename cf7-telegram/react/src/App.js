/* global cf7TelegramData */

import React, { useState, useEffect } from 'react';
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
    fetchChatsForChannels, apiDeleteChat
} from './utils/api';

const App = () => {
    const [client, setClient] = useState([]);
    const [forms, setForms] = useState([]);
    const [bots, setBots] = useState([]);
    const [chats, setChats] = useState([]);
    const [channels, setChannels] = useState([]);
    const [form2ChannelRelations, setForm2ChannelRelations] = useState([]);
    const [bot2ChannelRelations, setBot2ChannelRelations] = useState([]);
    const [chat2ChannelRelations, setChat2ChannelRelations] = useState([]);
    const [bot2ChatConnections, setBot2ChatConnections] = useState([]);
    const [loading, setLoading] = useState(true);

    // Run once when the component mounts.
    useEffect(() => {
        fetchClient().then(setClient);
        fetchForms().then(setForms);
        fetchBots().then(setBots);
        fetchChats().then(setChats);
        fetchFormsForChannels().then(setForm2ChannelRelations);
        fetchBotsForChannels().then(setBot2ChannelRelations);
        fetchChatsForChannels().then(setChat2ChannelRelations);
        loadBot2ChatConnections();

        const interval = setInterval(() => {
            loadBot2ChatConnections();
        }, 10000); // refresh every 10 seconds

        return () => clearInterval(interval);
    }, []);

    const loadBot2ChatConnections = () => {
        fetchBotsForChats().then((connections) => {
            const mapped = connections.map(rel => {
                const status = rel.data?.meta?.status?.[0];
                return {
                    ...rel,
                    data: {
                        ...rel.data,
                        muted: status === 'muted'
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

    // When chats has a chat that is not in bot2ChatConnections, destroy it.
    useEffect( () => {
        const chatIdsInBot2ChatConnections = bot2ChatConnections.map(rel => rel.data.to);
        const chatsToRemove = chats.filter(chat => !chatIdsInBot2ChatConnections.includes(chat.id));
        const chatIdsToRemove = chatsToRemove.map(chat => chat.id);
        const deletePromises = chatIdsToRemove.map(chatId => apiDeleteChat(chatId));
        Promise.all(deletePromises)
            .then(() => {
                setChats(chats.filter(chat => chatIdsInBot2ChatConnections.includes(chat.id)));
            })
            .catch(error => {
                console.error("Error deleting chats:", error);
            });
        },
        [chats, bot2ChatConnections]
    )

    if (loading) return <div>Loading channels...</div>;
    if (channels.length === 0) return <div>No channels found</div>;

    return (
        <div className="cf7-tg-container">
            <div className="list-container bots-container">
                <div className="title-container">
                    <h3 className="title">Bots</h3>
                    <NewBot setBots={setBots} />
                </div>

                <div className="bot-list">
                    {bots.map(bot => (
                        <div className="entity-container bot" key={bot.id} id={`bot-${bot.id}`}>
                            <Bot
                                bot={bot}
                                chats={chats}
                                bot2ChatConnections={bot2ChatConnections}
                                setBots={setBots}
                                setBot2ChatConnections={setBot2ChatConnections}
                                bot2ChannelRelations={bot2ChannelRelations}
                                setChat2ChannelRelations={setChat2ChannelRelations}
                            />
                        </div>
                    ))}
                </div>
            </div>

            <div className="list-container channels-container">
                <div className="title-container">
                    <h3 className="title">Channels</h3>
                    <NewChannel setChannels={setChannels} />
                </div>
                <div className="channel-list">
                    {channels.map(channel => (
                        <div className="entity-container channel" key={channel.id} id={`channel-${channel.id}`}>
                            <Channel
                                channel={channel}
                                forms={forms}
                                setChannels={setChannels}
                                form2ChannelRelations={form2ChannelRelations}
                                setForm2ChannelRelations={setForm2ChannelRelations}
                                bots={bots}
                                bot2ChannelRelations={bot2ChannelRelations}
                                chats={chats}
                                chat2ChannelRelations={chat2ChannelRelations}
                                setChat2ChannelRelations={setChat2ChannelRelations}
                                bot2ChatConnections={bot2ChatConnections}
                            />
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
};

export default App;
