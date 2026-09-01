import {
    apiCreateBot,
    apiDeleteBot,
    apiDeleteChannel,
    apiDeleteChat,
    apiFetchUpdates,
    apiPingBot,
    apiRenameChat,
    apiRestoreChatName,
    ApiError,
    fetchBots,
    fetchBotsForChannels,
    fetchBotsForChats,
    fetchChannels,
    fetchChats,
    fetchChatsForChannels,
    fetchForms,
    fetchFormsForChannels,
} from './api';

const response = (data, totalPages, ok = true, status = ok ? 200 : 500) => ({
    ok,
    status,
    headers: {
        get: jest.fn((name) => name === 'X-WP-TotalPages' ? String(totalPages) : null),
    },
    json: jest.fn().mockResolvedValue(data),
});

const responseWithoutTotalPages = (data, ok = true, status = ok ? 200 : 500) => ({
    ok,
    status,
    headers: {
        get: jest.fn(() => null),
    },
    json: jest.fn().mockResolvedValue(data),
});

const fetchUrl = (callIndex) => new URL(global.fetch.mock.calls[callIndex][0]);

describe('API collections', () => {
    let consoleError;

    beforeEach(() => {
        global.cf7TelegramData = {
            nonce: 'test-nonce',
            routes: {
                forms: 'https://example.test/wp-json/contact-form-7/v1/contact-forms/',
                chats: 'https://example.test/wp-json/wp/v2/cf7tg_chat/',
                bots: 'https://example.test/wp-json/wp/v2/cf7tg_bot/',
                channels: 'https://example.test/wp-json/wp/v2/cf7tg_channel/',
                relations: {
                    bot2channel: 'https://example.test/wp-json/wp-connections/v1/client/cf7-telegram/relation/bot2channel/',
                    chat2channel: 'https://example.test/wp-json/wp-connections/v1/client/cf7-telegram/relation/chat2channel/',
                    form2channel: 'https://example.test/wp-json/wp-connections/v1/client/cf7-telegram/relation/form2channel/',
                    bot2chat: 'https://example.test/wp-json/wp-connections/v1/client/cf7-telegram/relation/bot2chat/',
                },
            },
        };

        global.fetch = jest.fn();
        consoleError = jest.spyOn(console, 'error').mockImplementation(() => {});
    });

    afterEach(() => {
        consoleError.mockRestore();
    });

    it('keeps the existing array shape for a single chat page', async () => {
        const chats = [{id: 1, title: {rendered: 'Chat 1'}}];

        global.fetch.mockResolvedValueOnce(response(chats, 1));

        await expect(fetchChats()).resolves.toEqual(chats);

        expect(global.fetch).toHaveBeenCalledTimes(1);
        expect(fetchUrl(0).searchParams.get('page')).toBe('1');
        expect(fetchUrl(0).searchParams.get('per_page')).toBe('100');
        expect(fetchUrl(0).searchParams.get('order')).toBe('asc');
        expect(fetchUrl(0).searchParams.get('orderby')).toBe('id');
    });

    it('loads exactly two pages for 101 ordered unique chats', async () => {
        const firstPage = Array.from({length: 100}, (_, index) => ({id: index + 1}));
        const secondPage = [{id: 101}];

        global.fetch
            .mockResolvedValueOnce(response(firstPage, 2))
            .mockResolvedValueOnce(response(secondPage, 2));

        const chats = await fetchChats();

        expect(chats).toEqual([...firstPage, ...secondPage]);
        expect(chats).toHaveLength(101);
        expect(new Set(chats.map(chat => chat.id)).size).toBe(101);
        expect(global.fetch).toHaveBeenCalledTimes(2);
        expect(fetchUrl(0).searchParams.get('page')).toBe('1');
        expect(fetchUrl(1).searchParams.get('page')).toBe('2');
        expect(fetchUrl(0).searchParams.get('per_page')).toBe('100');
        expect(fetchUrl(1).searchParams.get('per_page')).toBe('100');
    });

    it('keeps the first copy when a later page repeats a chat id', async () => {
        global.fetch
            .mockResolvedValueOnce(response([{id: 1, title: {rendered: 'Original'}}], 2))
            .mockResolvedValueOnce(response([{id: 1, title: {rendered: 'Duplicate'}}, {id: 2}], 2));

        await expect(fetchChats()).resolves.toEqual([
            {id: 1, title: {rendered: 'Original'}},
            {id: 2},
        ]);
    });

    it('rejects with a structured ApiError when a later page fails', async () => {
        global.fetch
            .mockResolvedValueOnce(response([{id: 1}], 2))
            .mockResolvedValueOnce(response({
                code: 'rest_forbidden',
                message: 'Server error for ?token=123456789:SECRET_TOKEN',
                data: {
                    status: 403,
                    token: '123456789:SECRET_TOKEN',
                    retry: {
                        nonce: 'test-nonce',
                    },
                },
            }, 2, false, 403));

        let thrown;
        try {
            await fetchChats();
        } catch (error) {
            thrown = error;
        }

        expect(thrown).toBeInstanceOf(ApiError);
        expect(thrown).toMatchObject({
            name: 'ApiError',
            status: 403,
            code: 'rest_forbidden',
            category: 'rest_permission',
            method: 'GET',
            data: {
                status: 403,
                token: '[redacted]',
                retry: {
                    nonce: '[redacted]',
                },
            },
        });
    });

    it('distinguishes transport, HTTP, and invalid response failures safely', async () => {
        global.fetch
            .mockRejectedValueOnce(new Error('connect ECONNREFUSED ?token=123456789:SECRET_TOKEN'))
            .mockResolvedValueOnce({
                ok: false,
                status: 502,
                headers: {get: jest.fn(() => null)},
                json: jest.fn().mockRejectedValue(new Error('invalid json')),
            })
            .mockResolvedValueOnce({
                ok: true,
                status: 200,
                headers: {get: jest.fn(() => null)},
                json: jest.fn().mockRejectedValue(new Error('invalid json')),
            });

        await expect(fetchBots()).rejects.toMatchObject({
            category: 'rest_transport',
            status: 0,
            message: 'connect ECONNREFUSED ?token=[redacted]',
        });
        await expect(fetchBots()).rejects.toMatchObject({
            category: 'rest_http',
            status: 502,
            message: 'The request could not be completed.',
        });
        await expect(fetchBots()).rejects.toMatchObject({
            category: 'rest_parse',
            status: 200,
            message: 'The server returned an invalid response.',
        });
    });

    it('redacts sensitive query values from ApiError URLs', async () => {
        global.cf7TelegramData.routes.bots = 'https://example.test/wp-json/wp/v2/cf7tg_bot/?token=123456789:SECRET_TOKEN&_wpnonce=test-nonce';
        global.fetch.mockResolvedValueOnce(response({code: 'rest_forbidden', message: 'Forbidden', data: {status: 403}}, 1, false, 403));

        await expect(fetchBots()).rejects.toMatchObject({
            url: '/wp-json/wp/v2/cf7tg_bot/?token=%5Bredacted%5D&_wpnonce=%5Bredacted%5D&order=asc&orderby=id&per_page=100&page=1',
        });
    });

    it('loads every bot page through the WordPress posts collection contract', async () => {
        global.fetch
            .mockResolvedValueOnce(response([{id: 10}], 2))
            .mockResolvedValueOnce(response([{id: 11}], 2));

        await expect(fetchBots()).resolves.toEqual([{id: 10}, {id: 11}]);

        expect(global.fetch).toHaveBeenCalledTimes(2);
        expect(fetchUrl(0).searchParams.get('page')).toBe('1');
        expect(fetchUrl(1).searchParams.get('page')).toBe('2');
        expect(fetchUrl(0).searchParams.get('order')).toBe('asc');
        expect(fetchUrl(0).searchParams.get('orderby')).toBe('id');
    });

    it('loads every channel page through the WordPress posts collection contract', async () => {
        global.fetch
            .mockResolvedValueOnce(response([{id: 20}], 2))
            .mockResolvedValueOnce(response([{id: 21}], 2));

        await expect(fetchChannels()).resolves.toEqual([{id: 20}, {id: 21}]);

        expect(global.fetch).toHaveBeenCalledTimes(2);
        expect(fetchUrl(0).searchParams.get('page')).toBe('1');
        expect(fetchUrl(1).searchParams.get('page')).toBe('2');
        expect(fetchUrl(0).searchParams.get('per_page')).toBe('100');
    });

    it('uses one bounded offset request for Contact Form 7 forms when the first response is incomplete', async () => {
        const forms = [{id: 100, title: 'Contact form'}];
        global.fetch.mockResolvedValueOnce(responseWithoutTotalPages(forms));

        await expect(fetchForms()).resolves.toEqual(forms);

        expect(global.fetch).toHaveBeenCalledTimes(1);
        expect(fetchUrl(0).pathname).toBe('/wp-json/contact-form-7/v1/contact-forms/');
        expect(fetchUrl(0).searchParams.get('per_page')).toBe('100');
        expect(fetchUrl(0).searchParams.get('offset')).toBe('0');
        expect(fetchUrl(0).searchParams.get('order')).toBe('asc');
        expect(fetchUrl(0).searchParams.get('orderby')).toBe('id');
        expect(fetchUrl(0).searchParams.has('page')).toBe(false);
    });

    it('loads multiple Contact Form 7 form pages using offset because the endpoint omits total pages', async () => {
        const firstPage = Array.from({length: 100}, (_, index) => ({id: index + 1}));
        const secondPage = [{id: 101}];

        global.fetch
            .mockResolvedValueOnce(responseWithoutTotalPages(firstPage))
            .mockResolvedValueOnce(responseWithoutTotalPages(secondPage));

        await expect(fetchForms()).resolves.toEqual([...firstPage, ...secondPage]);

        expect(global.fetch).toHaveBeenCalledTimes(2);
        expect(fetchUrl(0).searchParams.get('offset')).toBe('0');
        expect(fetchUrl(1).searchParams.get('offset')).toBe('100');
    });

    it('stops Contact Form 7 offset pagination when a full page repeats already loaded IDs', async () => {
        const repeatedPage = Array.from({length: 100}, (_, index) => ({id: index + 1}));

        global.fetch
            .mockResolvedValueOnce(responseWithoutTotalPages(repeatedPage))
            .mockResolvedValueOnce(responseWithoutTotalPages(repeatedPage));

        await expect(fetchForms()).resolves.toEqual(repeatedPage);

        expect(global.fetch).toHaveBeenCalledTimes(2);
        expect(fetchUrl(0).searchParams.get('offset')).toBe('0');
        expect(fetchUrl(1).searchParams.get('offset')).toBe('100');
    });

    it('preserves single-request behavior for relation collections', async () => {
        const relations = [{data: {id: 1, from: 10, to: 20}}];

        global.fetch.mockResolvedValue(responseWithoutTotalPages(relations));

        await expect(fetchFormsForChannels()).resolves.toEqual(relations);
        await expect(fetchBotsForChannels()).resolves.toEqual(relations);
        await expect(fetchBotsForChats()).resolves.toEqual(relations);
        await expect(fetchChatsForChannels()).resolves.toEqual(relations);

        expect(global.fetch).toHaveBeenCalledTimes(4);
        for (let index = 0; index < 4; index++) {
            expect(fetchUrl(index).searchParams.has('page')).toBe(false);
            expect(fetchUrl(index).searchParams.has('per_page')).toBe(false);
        }
    });

    it('creates an empty bot without sending a placeholder token for validation', async () => {
        global.fetch.mockResolvedValueOnce(response({id: 10}, 1));

        await expect(apiCreateBot('Bot Name')).resolves.toEqual({id: 10});

        const request = global.fetch.mock.calls[0][1];
        expect(JSON.parse(request.body)).toEqual({title: 'Bot Name', status: 'publish'});
    });

    it('uses POST for bot action endpoints that can mutate bot state or diagnostics', async () => {
        global.fetch
            .mockResolvedValueOnce(response({online: true}, 1))
            .mockResolvedValueOnce(response({hasNewChats: false}, 1));

        await expect(apiPingBot(10)).resolves.toEqual({online: true});
        await expect(apiFetchUpdates(10)).resolves.toEqual({hasNewChats: false});

        expect(global.fetch.mock.calls[0][0]).toBe('https://example.test/wp-json/wp/v2/cf7tg_bot/10/ping');
        expect(global.fetch.mock.calls[0][1].method).toBe('POST');
        expect(global.fetch.mock.calls[1][0]).toBe('https://example.test/wp-json/wp/v2/cf7tg_bot/10/fetch_updates');
        expect(global.fetch.mock.calls[1][1].method).toBe('POST');
    });

    it('adds force delete params outside rest_route endpoint values', async () => {
        global.cf7TelegramData.routes.chats = 'https://example.test/index.php?rest_route=/wp/v2/cf7tg_chat/';
        global.cf7TelegramData.routes.bots = 'https://example.test/index.php?rest_route=/wp/v2/cf7tg_bot/';
        global.cf7TelegramData.routes.channels = 'https://example.test/index.php?rest_route=/wp/v2/cf7tg_channel/';
        global.fetch.mockResolvedValue(response({deleted: true}, 1));

        await expect(apiDeleteChat(8)).resolves.toEqual({deleted: true});
        await expect(apiDeleteBot(10)).resolves.toEqual({deleted: true});
        await expect(apiDeleteChannel(12)).resolves.toEqual({deleted: true});

        expect(global.fetch.mock.calls[0][0]).toBe('https://example.test/index.php?rest_route=/wp/v2/cf7tg_chat/8&force=true');
        expect(global.fetch.mock.calls[0][1].method).toBe('DELETE');
        expect(global.fetch.mock.calls[1][0]).toBe('https://example.test/index.php?rest_route=/wp/v2/cf7tg_bot/10&force=true');
        expect(global.fetch.mock.calls[1][1].method).toBe('DELETE');
        expect(global.fetch.mock.calls[2][0]).toBe('https://example.test/index.php?rest_route=/wp/v2/cf7tg_channel/12&force=true');
        expect(global.fetch.mock.calls[2][1].method).toBe('DELETE');
    });

    it('uses POST for chat rename and restore-name endpoints', async () => {
        global.fetch
            .mockResolvedValueOnce(response({id: 8, title: {rendered: 'Custom'}}, 1))
            .mockResolvedValueOnce(response({id: 8, title: {rendered: 'Telegram'}}, 1));

        await expect(apiRenameChat(8, 10, 'Custom')).resolves.toEqual({id: 8, title: {rendered: 'Custom'}});
        await expect(apiRestoreChatName(8, 10)).resolves.toEqual({id: 8, title: {rendered: 'Telegram'}});

        expect(global.fetch.mock.calls[0][0]).toBe('https://example.test/wp-json/wp/v2/cf7tg_chat/8/name');
        expect(global.fetch.mock.calls[0][1].method).toBe('POST');
        expect(JSON.parse(global.fetch.mock.calls[0][1].body)).toEqual({bot_id: 10, name: 'Custom'});
        expect(global.fetch.mock.calls[1][0]).toBe('https://example.test/wp-json/wp/v2/cf7tg_chat/8/restore_name');
        expect(global.fetch.mock.calls[1][1].method).toBe('POST');
        expect(JSON.parse(global.fetch.mock.calls[1][1].body)).toEqual({bot_id: 10});
    });
});
