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

const fetchFormsForChannel = async (channelId) => {
    const response = await fetch(cf7TelegramData.routes.relations.form2channel, {
        method: 'GET',
        headers: {
            'X-WP-Nonce': cf7TelegramData?.nonce,
        },
        body: {
            to: channelId,
        }
    });
    return await response.json();
};

const ChannelList = () => {
    const [client, setClient] = useState([]);
    const [forms, setForms] = useState([]); // Хранит все формы
    const [bot, setBots] = useState([]);
    const [chat, setChats] = useState([]);
    const [channels, setChannels] = useState([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        fetchClient().then(data => setClient(data));
        fetchForms().then(data => setForms(data)); // Загружаем все формы один раз
        fetchBots().then(data => setBots(data));
        fetchChats().then(data => setChats(data));
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
                        <Channel channel={channel} forms={forms} />
                    </li>
                ))}
            </ul>
        </div>
    );
};

const Channel = ({ channel, forms }) => {
    const [formIdsForChannel, setFormIdsForChannel] = useState([]);
    const [formsForChannel, setFormsForChannel] = useState([]); // Состояние для хранения отфильтрованных форм

    // Загрузка ID форм для данного канала при монтировании компонента
    useEffect(() => {
        fetchFormsForChannel(channel.id).then(data => {
            const formIds = data.map(relation => relation.data.from); // Извлекаем ID форм из data.from
            setFormIdsForChannel(formIds); // Сохраняем ID форм для канала
        });
    }, [channel.id]);

    // Фильтруем формы по ID, когда formIdsForChannel обновляется
    useEffect(() => {
        const channelForms = forms.filter(form => formIdsForChannel.includes(form.id));
        setFormsForChannel(channelForms); // Устанавливаем формы, которые соответствуют каналу
    }, [formIdsForChannel, forms]);

    return (
        <div className="cf7tg-channel" id={`channel-${channel.id}`}>
            <h4>{channel.title.rendered}</h4>
            {formsForChannel.length > 0 ? (
                <div>
                    <h5>Forms</h5>
                    <ul>
                        {formsForChannel.map(form => (
                            <li>{form.title}</li>
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
