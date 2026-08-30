/* global cf7TelegramData */

const appendQueryParams = (url, params) => {
    const queryString = params.toString();

    if (!queryString) {
        return url;
    }

    return `${url}${url.includes('?') ? '&' : '?'}${queryString}`;
}

const apiRequest = async (url, method, body, options = {}) => {
    method = method ?? 'GET';

    let query = {
        method,
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': cf7TelegramData?.nonce,
        }
    }

    let params = new URLSearchParams();
    if (method === 'GET' || method === 'HEAD') {
        // GET and HEAD requests should not have a body, so refactor body to query parameters
        // and append them to the URL.
        if (body) {
            for (const [key, value] of Object.entries(body)) {
                params.append(key, value);
            }

            body = null;
        }

        url = appendQueryParams(url, params);
    }

    if (body) {
        query.body = JSON.stringify(body);
    }

    try {
        const response = await fetch(url, query);

        if (!response.ok) throw new Error('Network response was not ok');

        const data = await response.json();
        return options.includeResponse ? {data, response} : data;
    } catch (error) {
        console.error('API request error:', error);
        throw error;
    }
}

const mergePageItems = (items, pageItems) => {
    const seenIds = new Set(items.map(item => item?.id));

    return items.concat(
        pageItems.filter(item => {
            if (!item || typeof item.id === 'undefined') {
                return true;
            }

            if (seenIds.has(item.id)) {
                return false;
            }

            seenIds.add(item.id);
            return true;
        })
    );
}

const fetchAllPages = async (url, params = {}) => {
    const perPage = 100;
    let page = 1;
    let totalPages = 1;
    let items = [];

    do {
        const {data, response} = await apiRequest(
            url,
            'GET',
            {...params, per_page: perPage, page},
            {includeResponse: true}
        );

        items = mergePageItems(items, Array.isArray(data) ? data : []);

        const totalPagesHeader = response.headers?.get?.('X-WP-TotalPages');
        const parsedTotalPages = Number.parseInt(totalPagesHeader, 10);
        totalPages = Number.isFinite(parsedTotalPages) && parsedTotalPages > 0 ? parsedTotalPages : page;
        page += 1;
    } while (page <= totalPages);

    return items;
}

export const fetchClient = async () => {
    return await apiRequest(cf7TelegramData.routes.client)
};

export const fetchForms = async () => {
    return await apiRequest(cf7TelegramData.routes.forms)
};

export const fetchBots = async () => {
    return await apiRequest(
        cf7TelegramData.routes.bots,
        'GET',
        {
            order: 'asc', orderby: 'id'
        }
    )
};

export const fetchChats = async () => {
    return await fetchAllPages(
        cf7TelegramData.routes.chats,
        {order: 'asc', orderby: 'id'}
    )
};

export const fetchChannels = async () => {
    return await apiRequest(
        cf7TelegramData.routes.channels,
        'GET',
        {
            order: 'asc', orderby: 'id'
        }
    )
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
        status: 'publish',
    };

    if (token?.trim()) {
        newBotData.token = token.trim();
    }

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

export const apiUpdateBotToken = async (botId, token) => {
    return await apiRequest(
        `${cf7TelegramData.routes.bots}${botId}/token`,
        'POST',
        {token}
    );
}

export const fetchBot = async (botId) => {
    return await apiRequest(`${cf7TelegramData.routes.bots}${botId}`)
}
