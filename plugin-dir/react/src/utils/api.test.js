import {apiCreateBot, fetchChats} from './api';

const response = (data, totalPages, ok = true) => ({
    ok,
    headers: {
        get: jest.fn((name) => name === 'X-WP-TotalPages' ? String(totalPages) : null),
    },
    json: jest.fn().mockResolvedValue(data),
});

const fetchUrl = (callIndex) => new URL(global.fetch.mock.calls[callIndex][0]);

describe('fetchChats', () => {
    let consoleError;

    beforeEach(() => {
        global.cf7TelegramData = {
            nonce: 'test-nonce',
            routes: {
                chats: 'https://example.test/wp-json/wp/v2/cf7tg_chat/',
                bots: 'https://example.test/wp-json/wp/v2/cf7tg_bot/',
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

    it('rejects when a later page fails', async () => {
        global.fetch
            .mockResolvedValueOnce(response([{id: 1}], 2))
            .mockResolvedValueOnce(response({message: 'Server error'}, 2, false));

        await expect(fetchChats()).rejects.toThrow('Network response was not ok');

        expect(global.fetch).toHaveBeenCalledTimes(2);
        expect(fetchUrl(0).searchParams.get('page')).toBe('1');
        expect(fetchUrl(1).searchParams.get('page')).toBe('2');
    });

    it('creates an empty bot without sending a placeholder token for validation', async () => {
        global.fetch.mockResolvedValueOnce(response({id: 10}, 1));

        await expect(apiCreateBot('Bot Name')).resolves.toEqual({id: 10});

        const request = global.fetch.mock.calls[0][1];
        expect(JSON.parse(request.body)).toEqual({title: 'Bot Name', status: 'publish'});
    });
});
