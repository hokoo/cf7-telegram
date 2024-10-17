/* global cf7TelegramData */

import React, {useState, useEffect} from 'react';

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
}

const fetchBots = async () => {
    const response = await fetch(cf7TelegramData.routes.bots, {
        method: 'GET',
        headers: {
            'X-WP-Nonce': cf7TelegramData?.nonce,
        },
    });
    return await response.json();
}

const fetchChats = async () => {
    const response = await fetch(cf7TelegramData.routes.chats, {
        method: 'GET',
        headers: {
            'X-WP-Nonce': cf7TelegramData?.nonce,
        },
    });
    return await response.json();
}

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
            to: channelId
        }
    });
    return await response.json();
}


const ChannelList = () => {
    const [client, setClient] = useState([]);
    const [forms, setForms] = useState([]);
    const [bot, setBots] = useState([]);
    const [chat, setChats] = useState([]);
    const [channels, setChannels] = useState([]);

    const [loading, setLoading] = useState(true);

    useEffect(() => {
            fetchClient()
                .then(data => {
                    setClient(data);
                })

            fetchForms()
                .then(data => {
                    setForms(data);
                })

            fetchBots()
                .then(data => {
                    setBots(data);
                })

            fetchChats()
                .then(data => {
                    setChats(data);
                })
        },
        []
    );

    useEffect(() => {
        fetchChannels()
            .then(data => {
                setChannels(data);
                setLoading(false);

                channels.forEach(channel => {
                    // fetchFormsForChannel(channel.id)
                })
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
                        <Channel channel={channel}/>
                    </li>
                ))}
            </ul>
        </div>
    );
};


const Channel = ({channel}) => {
    return (
        <div className="cf7tg-channel" id={`channel-${channel.id}`}>
            <h4>{channel.title.rendered}</h4>
        </div>
    );
};

export default ChannelList;
