/* global cf7TelegramData */

export class ApiError extends Error {
    constructor(message, details = {}) {
        super(message);
        this.name = 'ApiError';
        this.status = details.status ?? 0;
        this.code = details.code ?? '';
        this.category = details.category ?? 'rest_transport';
        this.method = details.method ?? 'GET';
        this.url = details.url ?? '';
        this.data = details.data ?? null;
    }
}

const getApiErrorCategory = (status, code = '') => {
    if ([401, 403].includes(Number(status)) || /forbidden|unauthorized|cannot_/i.test(code)) {
        return 'rest_permission';
    }

    return Number(status) >= 400 ? 'rest_http' : 'rest_transport';
};

const safeText = (text) => String(text)
    .replace(/bot\d{5,}(:|%(?:25)*3a)[A-Za-z0-9_-]{8,}/gi, 'bot[telegram-token]')
    .replace(/\d{5,}(:|%(?:25)*3a)[A-Za-z0-9_-]{8,}/gi, '[telegram-token]')
    .replace(/([?&][^=]*(?:nonce|token|secret|password|key)[^=]*=)[^&\s]*/gi, '$1[redacted]');

const safeData = (data) => {
    if (typeof data === 'string') {
        return safeText(data);
    }

    if (!data || typeof data !== 'object') {
        return data;
    }

    if (Array.isArray(data)) {
        return data.map(safeData);
    }

    return Object.entries(data).reduce((safe, [key, value]) => ({
        ...safe,
        [key]: /nonce|token|secret|password|key/i.test(key) ? '[redacted]' : safeData(value),
    }), {});
}

const safeUrl = (url) => {
    try {
        const parsed = new URL(
            url,
            typeof window !== 'undefined' ? window.location?.origin ?? 'https://example.invalid' : 'https://example.invalid'
        );

        for (const key of Array.from(parsed.searchParams.keys())) {
            if (/nonce|token|secret|password|key/i.test(key)) {
                parsed.searchParams.set(key, '[redacted]');
            }
        }

        return `${parsed.pathname}${parsed.search}${parsed.hash}`;
    } catch (error) {
        return safeText(url);
    }
}

const appendQueryParams = (url, params) => {
    const queryString = params.toString();

    if (!queryString) {
        return url;
    }

    return `${url}${url.includes('?') ? '&' : '?'}${queryString}`;
}

const apiRequest = async (url, method, body, options = {}) => {
    method = method ?? 'GET';
    const requestUrl = url;

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
                if (typeof value === 'undefined' || value === null) {
                    continue;
                }

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
        let data;

        try {
            data = await response.json();
        } catch (error) {
            throw new ApiError(
                response.ok ? 'The server returned an invalid response.' : 'The request could not be completed.',
                {
                    status: response.status ?? 0,
                    category: response.ok ? 'rest_parse' : getApiErrorCategory(response.status),
                    method,
                    url: safeUrl(url),
                }
            );
        }

        if (!response.ok) {
            throw new ApiError(
                'The request could not be completed.',
                {
                    status: response.status ?? data?.data?.status ?? 0,
                    code: data?.code ?? '',
                    category: getApiErrorCategory(response.status ?? data?.data?.status, data?.code),
                    method,
                    url: safeUrl(url),
                    data: safeData(data?.data ?? null),
                }
            );
        }

        return options.includeResponse ? {data, response} : data;
    } catch (error) {
        if (!(error instanceof ApiError)) {
            error = new ApiError(
                safeText(error?.message || 'API request failed'),
                {
                    category: 'rest_transport',
                    method,
                    url: safeUrl(requestUrl),
                }
            );
        }

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

const getTotalPages = (response) => {
    const totalPagesHeader = response.headers?.get?.('X-WP-TotalPages');
    const parsedTotalPages = Number.parseInt(totalPagesHeader, 10);

    return Number.isFinite(parsedTotalPages) && parsedTotalPages > 0 ? parsedTotalPages : null;
}

const fetchAllPages = async (url, params = {}, options = {}) => {
    const perPage = options.perPage ?? 100;
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

        const headerTotalPages = getTotalPages(response);
        if (headerTotalPages === null && options.stopWhenTotalPagesHeaderMissing) {
            totalPages = page;
        } else {
            totalPages = headerTotalPages ?? page;
        }

        page += 1;
    } while (page <= totalPages);

    return items;
}

const fetchWpPostCollection = async (url, params = {}) => fetchAllPages(
    url,
    {order: 'asc', orderby: 'id', ...params}
);

const fetchCf7FormsCollection = async () => {
    const perPage = 100;
    let offset = 0;
    let items = [];
    let keepFetching = true;

    while (keepFetching) {
        const pageItems = await apiRequest(
            cf7TelegramData.routes.forms,
            'GET',
            {per_page: perPage, offset, order: 'asc', orderby: 'id'}
        );
        const previousCount = items.length;
        const normalizedPageItems = Array.isArray(pageItems) ? pageItems : [];

        items = mergePageItems(items, normalizedPageItems);
        offset += normalizedPageItems.length;
        keepFetching = normalizedPageItems.length >= perPage && items.length > previousCount;
    }

    return items;
};

export const fetchClient = async () => {
    return await apiRequest(cf7TelegramData.routes.client)
};

export const fetchForms = async () => {
    return await fetchCf7FormsCollection()
};

export const fetchBots = async () => {
    return await fetchWpPostCollection(cf7TelegramData.routes.bots)
};

export const fetchChats = async () => {
    return await fetchWpPostCollection(cf7TelegramData.routes.chats)
};

export const fetchChannels = async () => {
    return await fetchWpPostCollection(cf7TelegramData.routes.channels)
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
        `${cf7TelegramData.routes.bots}${botId}/fetch_updates`,
        'POST'
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
    return await apiRequest(`${cf7TelegramData.routes.bots}${botId}/ping`, 'POST');
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
