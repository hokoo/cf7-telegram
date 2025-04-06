/* global cf7TelegramData */

import React, { useState, useEffect } from 'react';
import Channel from './components/Channel';
import Bot from './components/Bot';
import NewBotButton from './components/NewBotButton';

import {
    fetchClient,
    fetchForms,
    fetchBots,
    fetchChats,
    fetchChannels,
    fetchFormsForChannels,
    fetchBotsForChannels,
    fetchBotsForChats,
    fetchChatsForChannels
} from './utils/api';

const ChannelList = () => {
    const [client, setClient] = useState([]);
    const [forms, setForms] = useState([]);
    const [bots, setBots] = useState([]);
    const [chats, setChats] = useState([]);
    const [channels, setChannels] = useState([]);
    const [formsRelations, setFormsRelations] = useState([]);
    const [botsRelations, setBotsRelations] = useState([]);
    const [chatsRelations, setChatsRelations] = useState([]);
    const [botsChatRelations, setBotsChatRelations] = useState([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        fetchClient().then(setClient);
        fetchForms().then(setForms);
        fetchBots().then(setBots);
        fetchChats().then(setChats);
        fetchFormsForChannels().then(setFormsRelations);
        fetchBotsForChannels().then(setBotsRelations);
        fetchChatsForChannels().then(setChatsRelations);
        fetchBotsForChats().then((relations) => {
            // Map muted status for UI usage
            const mapped = relations.map(rel => {
                const status = rel.data?.meta?.status?.[0];
                return {
                    ...rel,
                    data: {
                        ...rel.data,
                        muted: status === 'muted'
                    }
                };
            });
            setBotsChatRelations(mapped);
        });
    }, []);

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

    if (loading) return <div>Loading channels...</div>;
    if (channels.length === 0) return <div>No channels found</div>;

    return (
        <div className="cf7-tg-container">
            <div className="bots-container">
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                    <h3>Bots</h3>
                    <NewBotButton setBots={setBots} />
                </div>

                <div className="bot-list">
                    {bots.map(bot => (
                        <div className="entity-container bot" key={bot.id} id={`bot-${bot.id}`}>
                            <Bot
                                bot={bot}
                                chats={chats}
                                botsChatRelations={botsChatRelations}
                                setBots={setBots}
                            />
                        </div>
                    ))}
                </div>
            </div>

            <div className="channels-container">
                <h3>Channels</h3>
                <div className="channel-list">
                    {channels.map(channel => (
                        <div className="entity-container channel" key={channel.id} id={`channel-${channel.id}`}>
                            <Channel
                                channel={channel}
                                forms={forms}
                                formsRelations={formsRelations}
                                setFormsRelations={setFormsRelations}
                                bots={bots}
                                botsRelations={botsRelations}
                                setBotsRelations={setBotsRelations}
                                chats={chats}
                                chatsRelations={chatsRelations}
                                botsChatRelations={botsChatRelations}
                            />
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
};

export default ChannelList;
