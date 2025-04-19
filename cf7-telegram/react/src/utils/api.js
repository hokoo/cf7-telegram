/* global cf7TelegramData */

const apiRequest = async (url, method, body) => {
    method = method ?? 'GET';

    let query = {
        method,
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': cf7TelegramData?.nonce,
        }
    }

    if (body) {
        query.body = JSON.stringify(body);
    }

    try {
        const response = await fetch(url, query);

        if (!response.ok) throw new Error('Network response was not ok');
        return await response.json();
    } catch (error) {
        console.error('API request error:', error);
        throw error;
    }
}

export const fetchClient = async () => {
    return await apiRequest(cf7TelegramData.routes.client)
};

export const fetchForms = async () => {
    return await apiRequest(cf7TelegramData.routes.forms)
};

export const fetchBots = async () => {
    return await apiRequest(cf7TelegramData.routes.bots)
};

export const fetchChats = async () => {
    return await apiRequest(cf7TelegramData.routes.chats)
};

export const fetchChannels = async () => {
    return await apiRequest(cf7TelegramData.routes.channels)
};

export const fetchFormsForChannels = async () => {
    return await apiRequest(cf7TelegramData.routes.relations.form2channel)
};

export const fetchBotsForChannels = async () => {
    return await apiRequest(cf7TelegramData.routes.relations.bot2channel)
};

export const fetchBotsForChats = async () => {
    return await apiRequest(cf7TelegramData.routes.relations.bot2chat)
};

export const fetchChatsForChannels = async () => {
    return await apiRequest(cf7TelegramData.routes.relations.chat2channel)
};

export const apiConnectBot2Channel = async (botId, channelId) => {
    return await apiRequest(
        cf7TelegramData.routes.relations.bot2channel,
        'POST',
        {from: botId, to: channelId}
    )
}

export const apiFetchUpdates = async (botId) => {
    return await apiRequest(
        `${cf7TelegramData.routes.bots}${botId}/fetch_updates`
    )
}

export const apiDisconnectBot2Channel = async (connectionId) => {
    return await apiRequest(
        `${cf7TelegramData.routes.relations.bot2channel}${connectionId}`,
        'DELETE'
    )
}

export const apiConnectChat2Channel = async (chatId, channelId) => {
    return await apiRequest(
        cf7TelegramData.routes.relations.chat2channel,
        'POST',
        {from: chatId, to: channelId}
    )
};

export const apiConnectForm2Channel = async (formId, channelId) => {
    return await apiRequest(
        cf7TelegramData.routes.relations.form2channel,
        'POST',
        {from: formId, to: channelId}
    )
}

export const apiDisconnectChat2Channel = async (connectionId) => {
    return await apiRequest(
        `${cf7TelegramData.routes.relations.chat2channel}${connectionId}`,
        'DELETE'
    )
};

export const apiDisconnectBot2Chat = async (connectionID) => {
    return await apiRequest(
        `${cf7TelegramData.routes.relations.bot2chat}${connectionID}`,
        'DELETE'
    );
}

export const apiDisconnectForm2Channel = async (connectionID) => {
    return await apiRequest(
        `${cf7TelegramData.routes.relations.form2channel}${connectionID}`,
        'DELETE'
    );
}

export const apiSetBot2ChatConnectionStatus = async (connectionID, status) => {
    return await apiRequest(
        `${cf7TelegramData.routes.relations.bot2chat}${connectionID}/meta`,
        'PATCH',
        {meta: [{key: 'status', value: status}]}
    )
}

export const apiDeleteChat = async (chatId) => {
    return await apiRequest(
        `${cf7TelegramData.routes.chats}${chatId}/?force=true`,
        'DELETE'
    );
}

export const apiCreateChannel = async (title) => {
    return await apiRequest(
        cf7TelegramData.routes.channels,
        'POST',
        {
            title: title,
            status: 'publish',
        },
    );
};

export const apiSaveChannel = async (channelId, title) => {
    let channelData = {
        title: title,
    };

    return await apiRequest(
        `${cf7TelegramData.routes.channels}${channelId}`,
        'POST',
        channelData
    );
}

export const apiDeleteChannel = async (channelId) => {
    return await apiRequest(
        `${cf7TelegramData.routes.channels}${channelId}/?force=true`,
        'DELETE'
    );
}

export const apiCreateBot = async (title, token) => {
    let newBotData = {
        title: title,
        token: token ?? '',
        status: 'publish',
    };

    return await apiRequest(
        cf7TelegramData.routes.bots,
        'POST',
        newBotData
    );
};

export const apiDeleteBot = async (botId) => {
    return await apiRequest(
        `${cf7TelegramData.routes.bots}${botId}/?force=true`,
        'DELETE'
    );
}

export const apiPingBot = async (botId) => {
    return await apiRequest(`${cf7TelegramData.routes.bots}${botId}/ping`);
}

export const apiSaveBot = async (botId, title, token) => {
    let botData = {}

    if (title) {
        botData.title = title;
    }

    if (token) {
        botData.token = token;
    }

    return await apiRequest(
        `${cf7TelegramData.routes.bots}${botId}`,
        'POST',
        botData
    );
}