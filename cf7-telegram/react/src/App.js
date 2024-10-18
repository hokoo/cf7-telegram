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
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        fetchClient().then(data => setClient(data));
        fetchForms().then(data => setForms(data)); // Загружаем все формы один раз
        fetchBots().then(data => setBots(data)); // Загружаем всех ботов один раз
        fetchChats().then(data => setChats(data));
        fetchFormsForChannels().then(data => setFormsRelations(data)); // Загружаем отношения form2channel один раз
        fetchBotsForChannels().then(data => setBotsRelations(data)); // Загружаем отношения bot2channel один раз
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
        <div>
            <h3>Channels</h3>
            <ul>
                {channels.map(channel => (
                    <li key={channel.id}>
                        <Channel channel={channel} forms={forms} formsRelations={formsRelations} bots={bots} botsRelations={botsRelations} />
                    </li>
                ))}
            </ul>
        </div>
    );
};

const Channel = ({ channel, forms, formsRelations, bots, botsRelations }) => {
    const [formsForChannel, setFormsForChannel] = useState([]); // Состояние для хранения отфильтрованных форм
    const [botForChannel, setBotForChannel] = useState(null); // Состояние для хранения бота, связанного с каналом

    // Фильтруем формы по ID, когда канал обновляется
    useEffect(() => {
        // Извлекаем все ID форм, которые относятся к этому каналу
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

    return (
        <div className="cf7tg-channel" id={`channel-${channel.id}`}>
            <h4>{channel.title.rendered}</h4>

            {/* Отображение бота */}
            {botForChannel ? (
                <div>
                    <h5>Bot</h5>
                    <p>{botForChannel.name}</p>
                </div>
            ) : (
                <p>No bot assigned to this channel</p>
            )}

            {/* Отображение форм */}
            {formsForChannel.length > 0 ? (
                <div>
                    <h5>Forms</h5>
                    <ul>
                        {formsForChannel.map(form => (
                            <li key={form.id}>{form.title}</li>
                        ))}
                    </ul>
                </div>
            ) : (
                <p>No forms assigned to this channel</p>
            )}
        </div>
    );
};

export default ChannelList;
