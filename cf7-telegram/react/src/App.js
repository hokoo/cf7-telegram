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
    return await response.json(); // Возвращает все отношения form2channel
};

const fetchBotsForChannels = async () => {
    const response = await fetch(cf7TelegramData.routes.relations.bot2channel, {
        method: 'GET',
        headers: {
            'X-WP-Nonce': cf7TelegramData?.nonce,
        },
    });
    return await response.json(); // Возвращает все отношения bot2channel
};

const fetchChatsForChannels = async () => {
    const response = await fetch(cf7TelegramData.routes.relations.chat2channel, {
        method: 'GET',
        headers: {
            'X-WP-Nonce': cf7TelegramData?.nonce,
        },
    });
    return await response.json(); // Возвращает все отношения chat2channel
}

const ChannelList = () => {
    const [client, setClient] = useState([]);
    const [forms, setForms] = useState([]); // Хранит все формы
    const [bots, setBots] = useState([]); // Хранит всех ботов
    const [chats, setChats] = useState([]);
    const [channels, setChannels] = useState([]);
    const [formsRelations, setFormsRelations] = useState([]); // Хранит все отношения form2channel
    const [botsRelations, setBotsRelations] = useState([]); // Хранит все отношения bot2channel
    const [chatsRelations, setChatsRelations] = useState([]); // Хранит все отношения chat2channel
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        fetchClient().then(data => setClient(data));
        fetchForms().then(data => setForms(data)); // Загружаем все формы один раз
        fetchBots().then(data => setBots(data)); // Загружаем всех ботов один раз
        fetchChats().then(data => setChats(data));
        fetchFormsForChannels().then(data => setFormsRelations(data)); // Загружаем отношения form2channel один раз
        fetchBotsForChannels().then(data => setBotsRelations(data)); // Загружаем отношения bot2channel один раз
        fetchChatsForChannels().then(data => setChatsRelations(data));
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
        <div className="cf7-tg-channels-container">
            <h3>Channels</h3>
            <div className="cf7-tg-channel-list">
                {channels.map(channel => (
                    <div className="channel" id={`channel-${channel.id}`} key={channel.id}>
                        <Channel channel={channel} forms={forms} formsRelations={formsRelations} bots={bots} botsRelations={botsRelations} chats={chats} chatsRelations={chatsRelations} />
                    </div>
                ))}
            </div>
        </div>
    );
};

const Channel = ({ channel, forms, formsRelations, bots, botsRelations, chats, chatsRelations }) => {
    const [formsForChannel, setFormsForChannel] = useState([]); // Состояние для хранения отфильтрованных форм
    const [botForChannel, setBotForChannel] = useState(null); // Состояние для хранения бота, связанного с каналом
    const [chatsForChannel, setChatsForChannel] = useState([]); // Состояние для хранения отфильтрованных чатов
    const [isEditingTitle, setIsEditingTitle] = useState(false);
    const [titleValue, setTitleValue] = useState(channel.title.rendered);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState(null);

    // Фильтруем формы по ID, когда канал обновляется
    useEffect(() => {
        const relatedFormsIds = formsRelations
            .filter(relation => relation.data.to === channel.id)
            .map(relation => relation.data.from); // Получаем ID форм, связанных с этим каналом

        // Фильтруем все формы на основе их ID
        const channelForms = forms.filter(form => relatedFormsIds.includes(form.id));
        setFormsForChannel(channelForms); // Устанавливаем формы, которые соответствуют каналу
    }, [channel.id, forms, formsRelations]);

    // Фильтруем бот для данного канала
    useEffect(() => {
        const relatedBot = botsRelations.find(relation => relation.data.to === channel.id); // Находим отношение бот-канал
        if (relatedBot) {
            const bot = bots.find(bot => bot.id === relatedBot.data.from); // Находим бота по ID
            setBotForChannel(bot);
        } else {
            setBotForChannel(null);
        }
    }, [channel.id, bots, botsRelations]);

    // Фильтруем чаты
    useEffect(() => {
        const relatedChats = chatsRelations.filter(relation => relation.data.to === channel.id); // Находим все отношения чат-канал
        if (relatedChats.length > 0) {
            const chatsForChannel = relatedChats.map(relation => chats.find(chat => chat.id === relation.data.from)); // Находим чаты по ID
            setChatsForChannel(chatsForChannel);
        } else {
            setChatsForChannel([]);
        }
    }, [channel.id, chats, chatsRelations]);

    // === Обработка редактирования названия ===
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

    // === Рендер ===
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

            {/* Бот */}
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

            {/* Чаты */}
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

            {/* Формы */}
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

export default ChannelList;
