/* global cf7TelegramData */

import React, { useState, useEffect } from 'react';

const fetchClient = async () => {
    const response = await fetch(cf7TelegramData.routes.client, {
        method: 'GET',
        headers: {
            'X-WP-Nonce': cf7TelegramData?.nonce,
        },
    });
    return await response.json();
};

const fetchForms = async () => {
    const response = await fetch(cf7TelegramData.routes.forms, {
        method: 'GET',
        headers: {
            'X-WP-Nonce': cf7TelegramData?.nonce,
        },
    });
    return await response.json();
};

const fetchBots = async () => {
    const response = await fetch(cf7TelegramData.routes.bots, {
        method: 'GET',
        headers: {
            'X-WP-Nonce': cf7TelegramData?.nonce,
        },
    });
    return await response.json();
};

const fetchChats = async () => {
    const response = await fetch(cf7TelegramData.routes.chats, {
        method: 'GET',
        headers: {
            'X-WP-Nonce': cf7TelegramData?.nonce,
        },
    });
    return await response.json();
};

const fetchChannels = async () => {
    const response = await fetch(cf7TelegramData.routes.channels, {
        method: 'GET',
        headers: {
            'X-WP-Nonce': cf7TelegramData?.nonce,
        },
    });
    return await response.json();
};

const fetchFormsForChannels = async () => {
    const response = await fetch(cf7TelegramData.routes.relations.form2channel, {
        method: 'GET',
        headers: {
            'X-WP-Nonce': cf7TelegramData?.nonce,
        },
    });
    return await response.json(); // Returns all form-to-channel relations
};

const fetchBotsForChannels = async () => {
    const response = await fetch(cf7TelegramData.routes.relations.bot2channel, {
        method: 'GET',
        headers: {
            'X-WP-Nonce': cf7TelegramData?.nonce,
        },
    });
    return await response.json(); // Returns all bot-to-channel relations
};

const fetchBotsForChats = async () => {
    const response = await fetch(cf7TelegramData.routes.relations.bot2chat, {
        method: 'GET',
        headers: {
            'X-WP-Nonce': cf7TelegramData?.nonce,
        },
    });
    return await response.json(); // Returns all bot-to-chat relations
};

const fetchChatsForChannels = async () => {
    const response = await fetch(cf7TelegramData.routes.relations.chat2channel, {
        method: 'GET',
        headers: {
            'X-WP-Nonce': cf7TelegramData?.nonce,
        },
    });
    return await response.json(); // Returns all chat-to-channel relations
};

const ChannelList = () => {
    const [client, setClient] = useState([]);
    const [forms, setForms] = useState([]); // Stores all forms
    const [bots, setBots] = useState([]); // Stores all bots
    const [chats, setChats] = useState([]);
    const [channels, setChannels] = useState([]);
    const [formsRelations, setFormsRelations] = useState([]); // Stores all form-to-channel relations
    const [botsRelations, setBotsRelations] = useState([]); // Stores all bot-to-channel relations
    const [chatsRelations, setChatsRelations] = useState([]); // Stores all chat-to-channel relations
    const [loading, setLoading] = useState(true);
    const [botsChatRelations, setBotsChatRelations] = useState([]);

    useEffect(() => {
        fetchClient().then(data => setClient(data));
        fetchForms().then(data => setForms(data)); // Load all forms once
        fetchBots().then(data => setBots(data)); // Load all bots once
        fetchChats().then(data => setChats(data));
        fetchFormsForChannels().then(data => setFormsRelations(data)); // Load form-to-channel relations once
        fetchBotsForChannels().then(data => setBotsRelations(data)); // Load bot-to-channel relations once
        fetchChatsForChannels().then(data => setChatsRelations(data)); // Load chat-to-channel relations once
        fetchBotsForChats().then(data => setBotsChatRelations(data));

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

    if (loading) {
        return <div>Loading channels...</div>;
    }

    if (channels.length === 0) {
        return <div>No channels found</div>;
    }

    return (
        <div className="cf7-tg-container">

            <div className="bots-container">
                <h3>Bots</h3>
                <div className="bot-list">
                    {bots.map(bot => (
                        <div className="bot" key={bot.id} id={`bot-${bot.id}`}>
                            <Bot bot={bot} chats={chats} botsChatRelations={botsChatRelations}/>
                        </div>
                    ))}
                </div>
            </div>

            <div className="channels-container">
                <h3>Channels</h3>
                <div className="channel-list">
                    {channels.map(channel => (
                        <div className="channel" id={`channel-${channel.id}`} key={channel.id}>
                            <Channel
                                channel={channel}
                                forms={forms}
                                formsRelations={formsRelations}
                                bots={bots}
                                botsRelations={botsRelations}
                                chats={chats}
                                chatsRelations={chatsRelations}
                            />
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
};

const Channel = ({ channel, forms, formsRelations, bots, botsRelations, chats, chatsRelations }) => {
    const [formsForChannel, setFormsForChannel] = useState([]); // Stores filtered forms
    const [botForChannel, setBotForChannel] = useState(null); // Stores the bot assigned to the channel
    const [chatsForChannel, setChatsForChannel] = useState([]); // Stores filtered chats
    const [isEditingTitle, setIsEditingTitle] = useState(false);
    const [titleValue, setTitleValue] = useState(channel.title.rendered);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);

    // Filter forms by channel ID
    useEffect(() => {
        const relatedFormsIds = formsRelations
            .filter(relation => relation.data.to === channel.id)
            .map(relation => relation.data.from);
        const channelForms = forms.filter(form => relatedFormsIds.includes(form.id));
        setFormsForChannel(channelForms);
    }, [channel.id, forms, formsRelations]);

    // Find bot for this channel
    useEffect(() => {
        const relatedBot = botsRelations.find(relation => relation.data.to === channel.id);
        if (relatedBot) {
            const bot = bots.find(bot => bot.id === relatedBot.data.from);
            setBotForChannel(bot);
        } else {
            setBotForChannel(null);
        }
    }, [channel.id, bots, botsRelations]);

    // Filter chats for this channel
    useEffect(() => {
        const relatedChats = chatsRelations.filter(relation => relation.data.to === channel.id);
        if (relatedChats.length > 0) {
            const chatsForChannel = relatedChats.map(relation => chats.find(chat => chat.id === relation.data.from));
            setChatsForChannel(chatsForChannel);
        } else {
            setChatsForChannel([]);
        }
    }, [channel.id, chats, chatsRelations]);

    // === Handle channel title editing ===
    const handleTitleClick = () => {
        setError(null);
        setIsEditingTitle(true);
    };

    const handleTitleChange = (e) => setTitleValue(e.target.value);

    const handleCancelEdit = () => {
        setTitleValue(channel.title.rendered);
        setIsEditingTitle(false);
        setError(null);
    };

    const saveTitle = async () => {
        if (titleValue.trim() === '' || titleValue === channel.title.rendered) {
            setIsEditingTitle(false);
            return;
        }

        setSaving(true);
        setError(null);

        try {
            const response = await fetch(`${cf7TelegramData.routes.channels}${channel.id}`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': cf7TelegramData?.nonce,
                },
                body: JSON.stringify({
                    title: titleValue,
                }),
            });

            if (!response.ok) throw new Error('Failed to update title');

            setIsEditingTitle(false);
        } catch (err) {
            console.error(err);
            setError('Failed to update title');
        } finally {
            setSaving(false);
        }
    };

    const handleKeyDown = (e) => {
        if (e.key === 'Enter') saveTitle();
        if (e.key === 'Escape') handleCancelEdit();
    };

    return (
        <div className="cf7tg-channel-wrapper">
            <div className="channel-title">
                {isEditingTitle ? (
                    <div className="edit-title">
                        <input
                            type="text"
                            value={titleValue}
                            onChange={handleTitleChange}
                            onKeyDown={handleKeyDown}
                            onBlur={() => {}}
                            disabled={saving}
                            autoFocus
                        />
                        <button onClick={saveTitle} disabled={saving}>💾</button>
                        <button onClick={handleCancelEdit} disabled={saving}>❌</button>
                        {saving && <span>⏳ Saving...</span>}
                        {error && <p style={{ color: 'red' }}>{error}</p>}
                    </div>
                ) : (
                    <h4 onClick={handleTitleClick} style={{ cursor: 'pointer' }}>
                        {titleValue} <span style={{ marginLeft: 6, fontSize: '0.9em' }}>✏️</span>
                    </h4>
                )}
            </div>

            {/* Bot */}
            <div className="bots">
                <h5>Bot</h5>
                {botForChannel ? (
                    <div id={botForChannel.id} className="bot-for-channel">
                        <p>{botForChannel.title.rendered}</p>
                        <span className="bot-token">token: {botForChannel.token}</span>
                    </div>
                ) : (
                    <p>No bot assigned to this channel</p>
                )}
            </div>

            {/* Chats */}
            <div className="chats">
                <h5>Chats</h5>
                {chatsForChannel.length > 0 ? (
                    <ul>
                        {chatsForChannel.map(chat => (
                            <li key={chat.id}>{chat.title.rendered}</li>
                        ))}
                    </ul>
                ) : (
                    <p>No chats assigned to this channel</p>
                )}
            </div>

            {/* Forms */}
            <div className="forms">
                <h5>Forms</h5>
                {formsForChannel.length > 0 ? (
                    <ul>
                        {formsForChannel.map(form => (
                            <li key={form.id}>{form.title}</li>
                        ))}
                    </ul>
                ) : (
                    <p>No forms assigned to this channel</p>
                )}
            </div>
        </div>
    );
};

const Bot = ({ bot, chats, botsChatRelations }) => {
    const relatedChatIds = botsChatRelations
        .filter(relation => relation.data.from === bot.id)
        .map(relation => relation.data.to);

    const chatsForBot = chats.filter(chat => relatedChatIds.includes(chat.id));

    return (
        <div className="cf7tg-bot-wrapper">
            <h4>{bot.title.rendered}</h4>
            <span className="bot-token">token: {bot.token}</span>

            {chatsForBot.length > 0 ? (
                <div className="chats-for-bot">
                    <h5>Chats</h5>
                    <ul>
                        {chatsForBot.map(chat => (
                            <li key={chat.id}>{chat.title.rendered}</li>
                        ))}
                    </ul>
                </div>
            ) : (
                <p>No chats assigned to this bot</p>
            )}
        </div>
    );
};


export default ChannelList;
